<?php

namespace Rissc\Printformer\Controller\Editor;

use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Rissc\Printformer\Gateway\User\Draft as DraftGateway;
use Rissc\Printformer\Helper\Api\Url as UrlHelper;
use Rissc\Printformer\Model\DraftFactory;
use Rissc\Printformer\Model\Draft;
use Rissc\Printformer\Helper\Session as SessionHelper;
use Rissc\Printformer\Helper\Editor\Preselect as PreselectHelper;
use Rissc\Printformer\Helper\Api as ApiHelper;

class Open extends Action
{
    /**
     * @var DraftGateway
     */
    protected $_draftGateway;

    /**
     * @var UrlHelper
     */
    protected $_urlHelper;

    /**
     * @var ApiHelper
     */
    protected $_apiHelper;

    /**
     * @var ProductFactory
     */
    protected $_productFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var DraftFactory
     */
    protected $_draftFactory;

    /**
     * @var SessionHelper
     */
    protected $_sessionHelper;

    /**
     * @var PreselectHelper
     */
    protected $_preselectHelper;

    /**
     * @var PageFactory
     */
    protected $_pageFactory;

    /**
     * Open constructor.
     *
     * @param Context $context
     * @param DraftGateway $draftGateway
     * @param UrlHelper $urlHelper
     * @param ProductFactory $productFactory
     * @param StoreManagerInterface $storeManager
     * @param DraftFactory $draftFactory
     * @param SessionHelper $sessionHelper
     * @param PreselectHelper $preselectHelper
     * @param ApiHelper $apiHelper
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        DraftGateway $draftGateway,
        UrlHelper $urlHelper,
        ProductFactory $productFactory,
        StoreManagerInterface $storeManager,
        DraftFactory $draftFactory,
        SessionHelper $sessionHelper,
        PreselectHelper $preselectHelper,
        ApiHelper $apiHelper,
        PageFactory $pageFactory
    )
    {
        $this->_draftGateway = $draftGateway;
        $this->_urlHelper = $urlHelper;
        $this->_productFactory = $productFactory;
        $this->_storeManager = $storeManager;
        $this->_draftFactory = $draftFactory;
        $this->_sessionHelper = $sessionHelper;
        $this->_preselectHelper = $preselectHelper;
        $this->_apiHelper = $apiHelper;
        $this->_pageFactory = $pageFactory;

        parent::__construct($context);
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    protected function _getParam(string $key)
    {
        /** @var DataObject $sessionParams */
        if ($sessionParams = $this->_sessionHelper->getCatalogSession()->getPrintformerRedirectParameters()) {
            $paramValue = $sessionParams->getData($key);
        } else {
            $paramValue = $this->getRequest()->getParam($key);
        }

        return $paramValue;
    }

    /**
     * @return array
     */
    protected function _getParams()
    {
        /** @var DataObject $sessionParams */
        if ($sessionParams = $this->_sessionHelper->getCatalogSession()->getPrintformerRedirectParameters()) {
            $paramValues = $sessionParams->toArray();
        } else {
            $paramValues = $this->getRequest()->getParams();
        }

        return $paramValues;
    }

    /**
     * @return Redirect|null
     */
    protected function _niceUrl()
    {
        if ($this->_sessionHelper->getCatalogSession()->getPrintformerRedirectParameters()) {
            return null;
        }

        $params = $this->_getParams();
        if ($this->getRequest()->isPost()) {
            $this->_savePreselectedData($params);
        }

        $dataParams = new DataObject($params);
        $this->_sessionHelper->getCatalogSession()->setPrintformerRedirectParameters($dataParams);

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setUrl($this->_url->getUrl('*/*/*'));

        return $redirect;
    }

    /**
     * @return void
     */
    protected function _clearSession()
    {
        if (!$this->_sessionHelper->getCatalogSession()->getPrintformerRedirectParameters()) {
            return;
        }

        $this->_sessionHelper->getCatalogSession()->setPrintformerRedirectParameters(null);
    }

    /**
     * @return ResponseInterface|Redirect|ResultInterface|Page
     *
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $refererUrl = $this->_redirect->getRefererUrl();
        if (strpos($refererUrl ?? '', "wishlist")) {
            $this->_sessionHelper->setWishlistUrl($refererUrl);
        }
        if (strpos($refererUrl ?? '', "design")) {
            $this->_sessionHelper->setDesignUrl($refererUrl);
        }

        if (!$this->_getParam('shopframe')) {
            if ($redirect = $this->_niceUrl()) {
                return $redirect;
            }
        }

        $this->getRequest()->setParams($this->_getParams());
        $this->_clearSession();

        /**
         * Get all params and variables needed
         */
        $params = $this->_getParams();
        $productId = $this->_getParam('product_id');
        $selectedProductId = $this->_getParam('selected_product_id');
        $identifier = $this->_getParam('identifier');
        $intent = $this->_getParam('intent');
        $printformerDraft = $this->_getParam('draft_id');
        $sessionUniqueId = $this->_getParam('session_id');
        $requestReferrer = $this->_getParam('custom_referrer');
        $printformerProductId = $this->_getParam('printformer_product_id');
        $storeId = $this->_storeManager->getStore()->getId();
        $customerSession = $this->_sessionHelper->getCustomerSession();
        $overrideFrameConfig = $this->_getParam('shopframe') != null;

        $customerId = null;
        if($customerSession->getCustomerId() !== null) {
            $customerId = $customerSession->getCustomerId();
        }

        if ($selectedProductId && $productId !== $selectedProductId) {
           $productId = $selectedProductId;
        }

        /**
         * Stop process if page reload
         */
        if (!$params) {
            $this->_die('');
        }

        /**
         * Open Editor in a frame if the display mode is set to "Shop Frame"
         * and we don't have a parameter set to override the config check
         */
        if (!$overrideFrameConfig && $this->_apiHelper->config()->isFrameEnabled()) {
            return $this->initShopFrame();
        }

        /**
         * Show an error if product id was not set
         */
        if (!$productId) {
            $this->_die(__('We could not determine the right Parameters. Please try again.'));
        }



        /**
         * Load product and save intent to session data
         */
        /** @var Product $product */
        $product = $this->_productFactory->create()->load($productId);
        $this->_sessionHelper->setCurrentIntent($intent);

        /**
         * Try to load draft from database
         */
        $draftProcess = $this->_apiHelper->draftProcess(
            $printformerDraft,
            $identifier,
            $product->getId(),
            $intent,
            $sessionUniqueId,
            $customerId,
            $printformerProductId
        );

        /**
         * If draft could not be created or loaded, show an error
         */
        if (!$draftProcess->getId()) {
            $this->_die(__('We could not determine the right Parameters. Please try again.'));
        }

        //TODO Check and clean old catalog sessions
        $sessionData = [
            'draft_id' => $draftProcess->getDraftId(),
            'saved_printformer_options' => $this->_sessionHelper->getCatalogSession()->getSavedPrintformerOptions(),
            'printformer_current_intent' => $intent,
            'printformer_identifier' => $identifier
        ];

        $this->_sessionHelper->setSessionDraftKey($draftProcess->getDraftId(), $sessionData);

        /**
         * Get printformer editor url by draft id
         */
        $editorParams = [
            'identifier' => $identifier,
            'product_id' => $productId,
            'data' => [
                'draft_process' => $draftProcess->getId(),
                'draft_hash' => $draftProcess->getDraftId(),
                'callback_url' => $requestReferrer
            ]
        ];

        if (!empty($params['quote_id']) && !empty($productId)) {
            $editorParams['data']['quote_id'] = $params['quote_id'];
        }

        $editorUrl = $this->_apiHelper->getEditorWebtokenUrl(
            $draftProcess->getDraftId(),
            $draftProcess->getUserIdentifier(),
            $editorParams
        );

        /**
         * Build redirect url
         */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setUrl($editorUrl);

        return $redirect;
    }

    /**
     * Add preselect data to session
     * @param array $data
     * @return void
     */
    protected function _savePreselectedData($data)
    {
        $preselectData = $this->_preselectHelper->getPreselectArray($data);

        if (!empty($preselectData)) {
            $this->_sessionHelper->getCatalogSession()->setSavedPrintformerOptions($preselectData);
        }
    }

    /**
     * Add notice to message manager and die()
     * @param Phrase $notice
     * @return void
     */
    protected function _die($notice)
    {
        if ($notice) {
            $this->messageManager->addNoticeMessage($notice);
        }
        echo '
                <script type="text/javascript">
                    window.top.location.href = \'' . $this->_redirect->getRefererUrl() . '\'
                </script>
            ';
        die();
    }

    /**
     * @param Product $product
     * @param string $intent
     * @param int $customerId
     * @param int $storeId
     * @param string $sessionUniqueId
     *
     * @return Draft
     * @throws Exception
     */
    protected function _createDraftProcess(
        Product $product,
        $storeId,
        $intent,
        $customerId,
        $sessionUniqueId = null
    )
    {
        $draftId = $this->_draftGateway->createDraft($product->getPrintformerProduct(), $intent);
        $userIdentifier = $this->_draftGateway->getUserIdentifier();

        /** @var Draft $draftProcess */
        $draftProcess = $this->_draftFactory->create();
        $draftProcess->addData([
            'draft_id' => $draftId,
            'store_id' => $storeId,
            'intent' => $intent,
            'session_unique_id' => $sessionUniqueId,
            'product_id' => $product->getId(),
            'customer_id' => $customerId,
            'user_identifier' => $userIdentifier,
            'created_at' => time()
        ]);
        $draftProcess->getResource()->save($draftProcess);

        if (!$draftProcess->getId()) {
            $this->_die(__('We could not save your draft. Please try again.'));
        }

        return $draftProcess;
    }

    /**
     * @param string $sessionUniqueId
     * @param Product $product
     * @param string $intent
     * @param string $printformerDraft
     * @param int $storeId
     * @param CustomerSession $customerSession
     *
     * @return Draft
     * @throws Exception
     */
    protected function _getDraftProcess(
        $sessionUniqueId,
        $product,
        $intent,
        $printformerDraft,
        $storeId,
        $customerSession
    )
    {
        /** @var Draft $draftProcess */
        $draftProcess = $this->_draftFactory->create();

        $draftCollection = $draftProcess->getCollection()
            ->addFieldToFilter('session_unique_id', ['eq' => $sessionUniqueId])
            ->addFieldToFilter('intent', ['eq' => $intent]);
        if ($printformerDraft != null) {
            $draftCollection->addFieldToFilter('draft_id', ['eq' => $printformerDraft]);
        }
        if ($draftCollection->count() == 1) {
            if ($draftCollection->getFirstItem()->getUserIdentifier() == $this->_draftGateway->getUserIdentifier()
                || $this->_sessionHelper->getCustomerId() == null) {
                /** @var Draft $draft */
                $draftProcess = $draftCollection->getFirstItem();
                if ($draftProcess->getId() && $draftProcess->getDraftId()) {
                    $this->_sessionHelper->setCurrentIntent($draftProcess->getIntent());
                }
            }
        } else {
            $draftProcess = $draftCollection->getLastItem();
        }

        return $draftProcess;
    }

    /**
     * @param       $requestReferrer
     * @param Draft $draftProcess
     * @param int $storeId
     * @param array $params
     * @param bool $encodeUrl
     *
     * @return string
     */
    protected function _getCallbackUrl(
        $requestReferrer,
        Draft $draftProcess,
        $storeId = 0,
        $params = [],
        $encodeUrl = true
    )
    {
        if ($requestReferrer != null) {
            $referrer = urldecode($requestReferrer);
        } else {
            $referrerParams = array_merge($params, [
                'store_id' => $storeId,
                'draft_process' => $draftProcess->getId()
            ]);

            if (isset($params['quote_id']) && isset($params['product_id'])) {
                $referrerParams['quote_id'] = $params['quote_id'];
                $referrerParams['edit_product'] = $params['product_id'];
                $referrerParams['is_edit'] = 1;
            }

            $referrer = $this->_url->getUrl('printformer/editor/save', $referrerParams);
        }

        if ($encodeUrl) {
            $referrer = urlencode(base64_encode($referrer));
        }

        return $referrer;
    }

    /**
     * @param string $editorUrl
     * @param string $requestReferrer
     * @param Draft $draftProcess
     * @param CustomerSession $customerSession
     * @param int $storeId
     * @param array $params
     * @return string
     */
    protected function _buildRedirectUrl(
        $editorUrl,
        $requestReferrer,
        Draft $draftProcess,
        CustomerSession $customerSession,
        $storeId = 0,
        $params = []
    )
    {
        /**
         * Disassembly editor url into base url and params for following process
         */
        $editorUrlparts = explode('?', $editorUrl ?? '');

        $editorUrlBase = $editorUrlparts[0];
        $editorUrlParams = '';
        if (isset($editorUrlparts[1])) {
            $editorUrlParams = $editorUrlparts[1];
        }

        $editorUrlParamsArray = [];
        parse_str($editorUrlParams, $editorUrlParamsArray);

        /**
         * Get callback url and add it to params
         */
        $editorUrlParamsArray['callback'] = $this->_getCallbackUrl($requestReferrer, $draftProcess, $storeId, $params);

        /**
         * Add customer id to params
         */
        if ($customerSession->isLoggedIn()) {
            $editorUrlParamsArray['user'] = $customerSession->getCustomerId();
        }

        $paramsObject = new DataObject($editorUrlParamsArray);
        $this->_eventManager->dispatch(
            'printformer_open_assign_url_params_before',
            [
                'request' => $this->_request,
                'params' => $paramsObject
            ]
        );
        /**
         * Override editor params with current action params
         */
        foreach ($paramsObject->getData() as $key => $param) {
            $editorUrlParamsArray[$key] = $param;
        }

        /**
         * Assemble url with params and return it
         */
        $queryArray = [];
        foreach ($editorUrlParamsArray as $key => $value) {
            $queryArray[] = $key . '=' . $value;
        }

        $editorUrl = $editorUrlBase . '?' . implode('&', $queryArray);

        return $editorUrl;
    }

    /**
     * @return string
     */
    protected function getFrameUrl()
    {
        $params = $this->getRequest()->getParams();
        $params['shopframe'] = 1;

        return $this->_url->getUrl('*/*/*', $params);
    }

    /**
     * @return Page
     */
    protected function initShopFrame()
    {
        $resultPage = $this->_pageFactory->create();
        $iframeBlock = $resultPage->getLayout()->getBlock('printformer_editor_shopframe');
        $iframeBlock->setFrameUrl($this->getFrameUrl());

        return $resultPage;
    }
}
