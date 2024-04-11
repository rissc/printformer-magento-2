<?php

namespace Rissc\Printformer\Observer\Product\Save;

use Magento\Catalog\Controller\Adminhtml\Product\Save;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Rissc\Printformer\Logger\PrintformerLogger;

class SavePrintformerProducts implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var PrintformerLogger
     */
    protected $printformerLogger;

    /**
     * SavePrintformerProducts constructor.
     * @param ManagerInterface $eventManager
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ManagerInterface $eventManager,
        ResourceConnection $resourceConnection,
        PrintformerLogger $printformerLogger
    ) {
        $this->eventManager = $eventManager;
        $this->resourceConnection = $resourceConnection;
        $this->printformerLogger = $printformerLogger;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        /** @var Product $product */
        $product = $observer->getProduct();

        /** @var Save $controller */
        $controller = $observer->getController();

        /** @var RequestInterface $request */
        $request = $controller->getRequest();

        /** @var array $params */
        $params = $request->getParams();

        $connection = $this->resourceConnection->getConnection();

        $data = [];

        if(isset($params['printformer']['printformer_templates'])) {
            foreach ($params['printformer']['printformer_templates'] as $record) {
                if(is_array($record) && (!isset($record['is_delete']) || $record['is_delete'] != 1)) {
                    $item = [
                        'product_id' => $product->getId(),
                        'printformer_product_id' => $record['id'],
                        'identifier' => $record['identifier'],
                        'store_id' => $product->getStoreId(),
                        'intent' => $record['intent']
                    ];
                    $this->printformerLogger->info(__('Printformer template with name '. $record['name']. ' assign to product Id: '. $product->getId(). ', product Name: '. $product->getName() .
                        ', ' . __('StoreId: '). $product->getStoreId()));
                    $data[] = $item;
                }
            }
        }

        $dataWrapper = new DataObject();

        $dataWrapper->setContent($data);
        $this->eventManager->dispatch('catalog_product_printformer_product_insert_before', ['controller' => $controller, 'product' => $product, 'insert_data' => $dataWrapper]);
        $data = $dataWrapper->getContent();

        $connection->beginTransaction();
        $connection->delete('catalog_product_printformer_product', ['product_id = ?' => $product->getId(), 'store_id = ?' => $product->getStoreId()]);
        if(count($data) > 0) {
            $connection->insertMultiple('catalog_product_printformer_product', $data);
        }
        $connection->commit();

        $this->eventManager->dispatch('catalog_product_printformer_product_insert_after', ['controller' => $controller, 'product' => $product, 'insert_data' => $dataWrapper]);
    }
}
