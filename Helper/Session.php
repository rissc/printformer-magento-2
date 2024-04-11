<?php

namespace Rissc\Printformer\Helper;

use Lcobucci\JWT\Exception;
use Magento\Catalog\Model\Session as CatalogSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Rissc\Printformer\Model\DraftFactory;
use Rissc\Printformer\Setup\InstallSchema;

class Session extends AbstractHelper
{
    const SESSION_DRAFT_KEY = 'key-';
    const SESSION_KEY_PRINTFORMER_DRAFTID = InstallSchema::COLUMN_NAME_DRAFTID;
    const SESSION_KEY_PRINTFORMER_CURRENT_INTENT = 'printformer_current_intent';
    const SESSION_KEY_WISHLIST_URL = 'wishlist_url';
    const SESSION_KEY_DESIGN_URL = 'design_url';

    /**
     * @var CatalogSession
     */
    protected $catalogSession;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var DraftFactory
     */
    private DraftFactory $draftFactory;
    private Product $printformerProductHelper;

    /**
     * Session constructor.
     * @param Context $context
     * @param CatalogSession $catalogSession
     * @param CustomerSession $customerSession
     */
    public function __construct(
        Context $context,
        CatalogSession $catalogSession,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        DraftFactory $draftFactory
    ) {
        parent::__construct($context);
        $this->catalogSession = $catalogSession;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->draftFactory = $draftFactory;
    }

    /**
     * @param int $productId
     * @param int $draftId
     * @param string $storeId
     * @return $this
     */
    public function setDraftId($productId, $printformerProductId, $draftId, $storeId)
    {
        $data = $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_DRAFTID);
        if (!is_array($data)) {
            $data = [];
        }
        $data[$storeId][$productId][$printformerProductId] = $draftId;
        $this->catalogSession->setData(self::SESSION_KEY_PRINTFORMER_DRAFTID, $data);

        return $this;
    }

    /**
     * @param int $productId
     * @param int $storeId
     * @return $this
     */
    public function unsetDraftId($productId, $printformerProductId, $storeId)
    {
        $data = $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_DRAFTID);
        if (!is_array($data)) {
            $data = [];
        }
        unset($data[$storeId][$productId][$printformerProductId]);
        $this->catalogSession->setData(self::SESSION_KEY_PRINTFORMER_DRAFTID, $data);

        return $this;
    }

    /**
     * @param int $productId
     * @param int $storeId
     * @return $this
     */
    public function unsetDraftIds($productId, $storeId)
    {
        $data = $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_DRAFTID);
        if (!is_array($data)) {
            $data = [];
        }
        unset($data[$storeId][$productId]);
        $this->catalogSession->setData(self::SESSION_KEY_PRINTFORMER_DRAFTID, $data);

        return $this;
    }

    /**
     * @param int $productId
     * @param int $storeId
     * @param string $intent
     * @param bool $clear
     * @return string|null
     */
    public function getDraftId($productId, $printformerProductId, $storeId, $intent = null, $clear = false)
    {
        if ($intent == $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT) || $intent == null) {
            $data = $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_DRAFTID, $clear);
            return isset($data[$storeId][$productId][$printformerProductId]) ? $data[$storeId][$productId][$printformerProductId] : null;
        } else {
            return null;
        }
    }

    /**
     * @return int|null
     */
    public function getCustomerId()
    {
        return $this->customerSession->getCustomerId();
    }

    /**
     * @param $loginUrl
     * @return bool
     */
    public function authenticateCustomer($loginUrl = null)
    {
        return $this->customerSession->authenticate($loginUrl);
    }

    /**
     * @return CatalogSession
     */
    public function getCatalogSession()
    {
        return $this->catalogSession;
    }

    /**
     * @return CustomerSession
     */
    public function getCustomerSession()
    {
        return $this->customerSession;
    }

    /**
     * @return CheckoutSession
     */
    public function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    private function getSessionUniqueIdFromDraft(string $draftId)
    {
        $uniqueId = false;
        try {
            $draftProcess = $this->draftFactory->create();
            $draftCollection = $draftProcess->getCollection()
                ->addFieldToFilter('draft_id', ['eq' => $draftId]);
            $lastItem = $draftCollection->getLastItem();
            $uniqueId = $lastItem->getSessionUniqueId();
        } catch (\Exception $e) {
        }
        return $uniqueId;
    }

    public function loadDraftIdsFromSession(string $productId): array
    {
        $resultDraftIds = [];

        $originSessionUniqueIds = $this->getSessionUniqueIdsByProductId($productId);
        if (!empty($originSessionUniqueIds)){
            $sessionUniqueIds = implode(",", $originSessionUniqueIds);
            if (!empty($sessionUniqueIds)) {
                $draftProcess = $this->draftFactory->create();
                $draftCollection = $draftProcess->getCollection()
                    ->addFieldToFilter('session_unique_id', ['in' => $sessionUniqueIds]);
                $items = $draftCollection->getItems();
                if (!empty($items)) {
                    foreach ($items as $item) {
                        if (!empty($item)) {
                            $productId = $item->getProductId();
                            $printformerProductId = $item->getPrintformerProductId();
                            if(!empty($printformerProductId) && !empty($productId)) {
                                if (!isset($resultDraftIds[$productId]))
                                    $resultDraftIds[$productId] = [];
                                $resultDraftIds[$productId][$printformerProductId] = $item->getDraftId();
                            }
                        }
                    }
                }
            }
        }

        return $resultDraftIds;
    }

    /**
     * @param $productId
     * @param $storeId
     * @param $buyRequest
     * @return void
     */
    public function loadDraftsForBuyRequest($productId, $storeId, $buyRequest): void
    {
        $buyRequestDraftFieldData = $buyRequest->getData($this::SESSION_KEY_PRINTFORMER_DRAFTID);
        if(empty($buyRequestDraftFieldData)) {
            $draftField = $this->loadDraftIdsFromSession($productId);
        }

        if (!empty($draftField[$productId])) {
            foreach ($draftField[$productId] as $draftKey => $draftValue) {
                $printformerProductId = $draftKey;
                if ($buyRequest->getData('_processing_params')) {
                    $draftId = $buyRequest
                        ->getData('_processing_params')
                        ->getData('current_config')
                        ->getData($this::SESSION_KEY_PRINTFORMER_DRAFTID);
                    if ($draftId) {
                        $buyRequest->setData($this::SESSION_KEY_PRINTFORMER_DRAFTID, $draftId);
                    }
                } elseif (!empty($draftValue)) {
                    $draftIdsStored = $buyRequest->getData($this::SESSION_KEY_PRINTFORMER_DRAFTID);
                    if (!empty($draftIdsStored)){
                        $draftIds = $draftIdsStored . ',' . $draftValue;
                    } else {
                        $draftIds = $draftValue;
                    }
                    $buyRequest->setData(
                        $this::SESSION_KEY_PRINTFORMER_DRAFTID,
                        $draftIds
                    );
                    $this->unsetSessionUniqueIdByDraftId($draftValue);
                    $this->unsetDraftId($productId, $printformerProductId, $storeId);
                }
            }
        }
    }

    /**
     * @param string $draftId
     * @return false
     */
    public function getPfProductByDraftId(string $draftId)
    {
        $lastPfProduct = false;
        try {
            $draftProcess = $this->draftFactory->create();
            $draftCollection = $draftProcess->getCollection()
                ->addFieldToFilter('id', ['eq' => $draftId]);
            $lastPfProduct = $draftCollection->getLastItem();

        } catch (\Exception $e) {
        }
        return $lastPfProduct;
    }

    /**
     * @param string $processId
     * @return false
     */
    public function getPfProductByDraftProcessId(string $processId)
    {
        $printformerProductId = false;
        try {
            $draftProcess = $this->draftFactory->create();
            $draftCollection = $draftProcess->getCollection()
                ->addFieldToFilter('id', ['eq' => $processId]);
            $lastPfProduct = $draftCollection->getLastItem();

        } catch (\Exception $e) {
        }
        return $lastPfProduct;
    }

    /**
     * Get unique id from session for the unique-ids-entry with corresponding product-id
     *
     * @param $productId
     * @return string|null
     */
    public function getSessionUniqueIdByProductId($productId, $pfProductId)
    {
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        $sessionUniqueId = null;

        if (!empty($sessionUniqueIds) && !empty($productId) && !empty($pfProductId)){
            if (isset($sessionUniqueIds[$productId])) {
                if (isset($sessionUniqueIds[$productId][$pfProductId])) {
                    $sessionUniqueId = $sessionUniqueIds[$productId][$pfProductId];
                }
            }
        }

        return $sessionUniqueId;
    }

    /**
     * Get unique id from session for the unique-ids-entry with corresponding product-id
     *
     * @param $productId
     * @return array
     */
    public function getSessionUniqueIdsByProductId($productId): array
    {
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        if (!empty($sessionUniqueIds)){
            $sessionUniqueIdsShifted = $sessionUniqueIds;
            $sessionUniqueIdsShifted = array_shift($sessionUniqueIdsShifted);
            if (empty($sessionUniqueIdsShifted) && !empty($sessionUniqueIds[$productId])) {
                $sessionUniqueIds = $sessionUniqueIds[$productId];
            } else {
                $sessionUniqueIds = $sessionUniqueIdsShifted;
            }
        }

        if (empty($sessionUniqueIds)) {
            $sessionUniqueIds = [];
        }
        return $sessionUniqueIds;
    }

    /**
     * Get unique id from session for the unique-ids-entry with corresponding product and pf-product-id
     *
     * @param $productId
     * @param $pfProductId
     * @return string|null
     */
    public function getSessionUniqueIdByProductAndPfProductId($productId, $pfProductId)
    {
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        $sessionUniqueId = null;

        if (!empty($sessionUniqueIds) && !empty($productId)){
            if (isset($sessionUniqueIds[$productId])) {
                $sessionUniqueId = $sessionUniqueIds[$productId][$pfProductId];
            }
        }

        return $sessionUniqueId;
    }

    /**
     * Create unique id  for a new unique-ids-entry with corresponding product- and pf-product-id if there is no one in
     * drafts-table or load the existing unique-id for the draft with product-id
     *
     * @param $productId
     * @param $pfProductId
     * @param $draftId
     * @return false|string
     */
    public function loadSessionUniqueId(
        $productId,
        $pfProductId,
        $draftId = ''
    )
    {
        $uniqueId = false;
        if (!empty($draftId)) {
            $uniqueId = $this->getSessionUniqueIdFromDraft($draftId);
        }

        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        if (empty($sessionUniqueIds)){
            $sessionUniqueIds = array();
        }

        if (!empty($pfProductId) && !empty($productId)) {
            if (empty($uniqueId)) {
                $uniqueId = md5(time() . '_' . $this->customerSession->getCustomerId() . '_' . $productId);
            }
            $sessionUniqueIds[$productId][$pfProductId] = $uniqueId;
            $this->customerSession->setSessionUniqueIds($sessionUniqueIds);
        }

        return $uniqueId;
    }

    /**
     * Remove the current session-unique id for concrete product-id from session
     *
     * @param $productId
     * @return void
     */
    public function removeSessionUniqueIdFromSession($productId, $pfProductId = null)
    {
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        if (!empty($sessionUniqueIds) && !empty($productId)){
            if (!empty($pfProductId)) {
                unset($sessionUniqueIds[$productId][$pfProductId]);
            } else {
                unset($sessionUniqueIds[$productId]);
            }
        }
        $this->customerSession->setSessionUniqueIds($sessionUniqueIds);
    }

    /**
     * @param string $draftId
     * @return bool
     */
    public function unsetSessionUniqueIdByDraftId(string $draftId)
    {
        $resultDeleteSuccess = false;
        $draftProcess = $this->draftFactory->create();
        $draftCollection = $draftProcess->getCollection()
            ->addFieldToFilter('draft_id', ['eq' => $draftId]);
        $lastItem = $draftCollection->getLastItem();
        $productId = $lastItem->getProductId();
        $pfProductId = $lastItem->getPrintformerProductId();
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        if (!empty($sessionUniqueIds) && !empty($productId)){
            if (!empty($pfProductId)) {
                unset($sessionUniqueIds[$productId][$pfProductId]);
                $resultDeleteSuccess = true;
            }
        }
        $this->customerSession->setSessionUniqueIds($sessionUniqueIds);
        return $resultDeleteSuccess;
    }

    /**
     * Remove the current session-unique id for concrete product-id from session
     *
     * @param $productId
     * @return void
     */
    public function removeSessionUniqueIdByProductIdFromSession($productId, $pfProductId)
    {
        $sessionUniqueIds = $this->customerSession->getSessionUniqueIds();
        if (!empty($sessionUniqueIds) && !empty($productId) && !empty($pfProductId)){
            unset($sessionUniqueIds[$productId][$pfProductId]);
            $this->customerSession->setSessionUniqueIds($sessionUniqueIds);
        }
    }

    /**
     * @param $intent
     * @return void
     */
    public function setCurrentIntent($intent)
    {
        if ($intent != $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT)) {
            $this->catalogSession->setData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT, $intent);
        }
    }

    public function unsetCurrentIntent()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT)) {
            $this->catalogSession->setData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT, null);
        }
    }

    public function getCurrentIntent()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT)) {
            return $this->catalogSession->getData(self::SESSION_KEY_PRINTFORMER_CURRENT_INTENT);
        }

        return null;
    }

    /**
     * @param $wishlistUrl
     * @return void
     */
    public function setWishlistUrl($wishlistUrl)
    {
        if ($wishlistUrl != $this->catalogSession->getData(self::SESSION_KEY_WISHLIST_URL)) {
            $this->catalogSession->setData(self::SESSION_KEY_WISHLIST_URL, $wishlistUrl);
        }
    }

    public function unsetWishlistUrl()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_WISHLIST_URL)) {
            $this->catalogSession->setData(self::SESSION_KEY_WISHLIST_URL, null);
        }
    }

    public function getWishlistUrl()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_WISHLIST_URL)) {
            return $this->catalogSession->getData(self::SESSION_KEY_WISHLIST_URL);
        }

        return null;
    }

    /**
     * @param $designUrl
     * @return void
     */
    public function setDesignUrl($designUrl)
    {
        if ($designUrl != $this->catalogSession->getData(self::SESSION_KEY_DESIGN_URL)) {
            $this->catalogSession->setData(self::SESSION_KEY_DESIGN_URL, $designUrl);
        }
    }

    public function unsetDesignUrl()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_DESIGN_URL)) {
            $this->catalogSession->setData(self::SESSION_KEY_DESIGN_URL, null);
        }
    }

    public function getDesignUrl()
    {
        if ($this->catalogSession->getData(self::SESSION_KEY_DESIGN_URL)) {
            return $this->catalogSession->getData(self::SESSION_KEY_DESIGN_URL);
        }

        return null;
    }

    /**
     * @param string $draftId
     *
     * @return array|null
     */
    public function getDraftCache($draftId)
    {
        if (!$this->catalogSession->getPrintformerDraftCache()) {
            return null;
        }

        $drafts = $this->catalogSession->getPrintformerDraftCache();
        if (empty($drafts[$draftId])) {
            return null;
        }

        return $drafts[$draftId];
    }

    /**
     * @param string $draftId
     *
     * @return bool
     */
    public function hasDraftInCache($draftId)
    {
        return $this->getDraftCache($draftId) != null;
    }

    /**
     * @param $draftId
     * @param $data
     *
     * @return void
     */
    public function addDraftToCache($draftId, $data)
    {
        if ($this->hasDraftInCache($draftId)) {
            return;
        }

        $drafts = $this->catalogSession->getPrintformerDraftCache();
        if (!$drafts) {
            $drafts = [];
        }

        $drafts[$draftId] = $data;

        $this->catalogSession->setPrintformerDraftCache($drafts);
    }

    /**
     * @param $draftId
     * @param $data
     *
     * @return void
     */
    public function updateDraftInCache($draftId, $data)
    {
        if (!$this->catalogSession->getPrintformerDraftCache()) {
            return;
        }

        if (!$this->hasDraftInCache($draftId)) {
            return;
        }

        $drafts = $this->catalogSession->getPrintformerDraftCache();

        $drafts[$draftId] = $data;

        $this->catalogSession->setPrintformerDraftCache($drafts);
    }

    /**
     * @param string $draftId
     */
    public function removeDraftFromCache($draftId)
    {
        if (!$this->catalogSession->getPrintformerDraftCache()) {
            return;
        }

        if (!$this->hasDraftInCache($draftId)) {
            return;
        }

        $drafts = $this->catalogSession->getPrintformerDraftCache();
        unset($drafts[$draftId]);
        $this->catalogSession->setPrintformerDraftCache($drafts);
    }

    /**
     * @param $draft
     * @param $sessionData
     * @return void
     */
    public function setSessionDraftKey($draft, $sessionData)
    {
        $draftKey = self::SESSION_DRAFT_KEY.$draft;
        $this->catalogSession->setData($draftKey, $sessionData);
    }

    public function unsetSessionDraftKey($draft)
    {
        $draftKey = self::SESSION_DRAFT_KEY.$draft;
        if ($this->catalogSession->getData($draftKey)) {
            $this->catalogSession->unsetData($draftKey);
        }
    }

    public function getSessionDraftKey($draft)
    {
        $draftKey = self::SESSION_DRAFT_KEY.$draft;
        if ($this->catalogSession->getData($draftKey)) {
            return $this->catalogSession->getData($draftKey);
        }

        return null;
    }

    /**
     * @param string $draftId
     * @return array|false
     */
    public function getDraftPageInfo(string $draftId)
    {
        $draftPageInfoFromSession = $this->catalogSession->getPrintformerDraftPageInfo();
        if (is_array($draftPageInfoFromSession) && isset($draftPageInfoFromSession[$draftId])){
            $result = $draftPageInfoFromSession;
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * @param $draftId
     * @param $data
     * @return false
     */
    public function setDraftPageInfo($draftId, $data)
    {
        if (is_array($data)){
            $preparedData = array(
                $draftId => $data
            );

            $result = $this->catalogSession->setPrintformerDraftPageInfo($preparedData);
        } else {
            $result = false;
        }

        return $result;
    }
}
