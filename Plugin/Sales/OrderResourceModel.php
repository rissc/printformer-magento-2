<?php

namespace Rissc\Printformer\Plugin\Sales;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\ResourceModel\Order;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Psr\Log\LoggerInterface;
use Rissc\Printformer\Gateway\Admin\Draft;
use Rissc\Printformer\Helper\Api;
use Rissc\Printformer\Helper\Config;
use Rissc\Printformer\Model\DraftFactory;
use Rissc\Printformer\Setup\InstallSchema;
use Rissc\Printformer\Cron\Processing\Runs\External;

class OrderResourceModel
{
    const STATE_PENDING = 'pending';

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Draft
     */
    protected $draft;

    /**
     * @var DraftFactory
     */
    protected $draftFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var External
     */
    private $external;

    /**
     * @var GroupRepositoryInterface
     */
    protected $groupRepository;

    /**
     * @param Api $api
     * @param Config $config
     * @param Draft $draft
     * @param DraftFactory $draftFactory
     * @param LoggerInterface $logger
     * @param External $external
     * @param GroupRepositoryInterface $groupRepository
     */
    public function __construct(
        Api $api,
        Config $config,
        Draft $draft,
        DraftFactory $draftFactory,
        LoggerInterface $logger,
        External $external,
        GroupRepositoryInterface $groupRepository
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->draft = $draft;
        $this->draftFactory = $draftFactory;
        $this->logger = $logger;
        $this->external = $external;
        $this->groupRepository = $groupRepository;
    }

    /**
     * @param Order $orderResourceModel
     * @param AbstractDb $result
     * @param OrderModel $orderModel
     * @return AbstractDb|void
     */
    public function afterSave(Order $orderResourceModel, AbstractDb $result, OrderModel $orderModel)
    {
        $processingPermitted = false;
        $draftIds = [];
        $orderItems = [];

        try {
            $incrementOrderId = $orderModel->getIncrementId();
            $processingPermitted = $this->api->isOrderStateValidToProcess($orderModel->getStatus());
            $customerGroupId = $orderModel->getCustomerGroupId();

            foreach ($orderModel->getAllItems() as $item) {
                if($item->getProductType() == 'downloadable'){
                    array_push($orderItems, $item->getId());
                }

                if ($item->getPrintformerOrdered() || !$item->getPrintformerDraftid()) {
                    continue;
                }

                $printDraftIds = $item->getData(InstallSchema::COLUMN_NAME_DRAFTID);
                if (!empty($printDraftIds)){
                    $itemDraftIds = explode(',', $printDraftIds);
                    foreach ($itemDraftIds as $draftId) {
                        $draftIds[] = $draftId;
                        $this->draftFactory
                            ->create()
                            ->load($draftId, 'draft_id')
                            ->setOrderItemId($item->getId())
                            ->save();

                        if ($this->config->getOrderDraftUpdate($item->getStoreId())){
                            $groupNumber = $this->getGroupNumber($customerGroupId);
                            $this->api->updateDraftHash($draftId, $incrementOrderId, $item->getStoreId(), $groupNumber);
                        }
                    }
                }
            }

            if (!empty($orderItems)){
                $orderItemIds = implode(',', $orderItems);
                $this->external->setOrderItemIdsToFilter($orderItemIds);
                $this->external->execute();
            }

            if (empty($draftIds)) {
                return;
            } else {
                $draftIds = array_unique($draftIds);
            }

            if ($processingPermitted) {
                $this->api->setAsyncOrdered($draftIds);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e);
        } finally {
            if ($processingPermitted) {
                if (isset($draftIds) && !empty($draftIds)){
                    foreach ($draftIds as $draftId) {
                        $this->api->setProcessingStateOnOrderItemByDraftId($draftId, $this->api::ProcessingStateAfterOrder);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param $groupId
     * @return mixed|string|void
     */
    public function getGroupNumber($groupId){
        try {
            $group = $this->groupRepository->getById($groupId);
            list($number) = explode("_", $group->getCode());
            return $number;
        } catch (\Exception $e) {}
    }
}
