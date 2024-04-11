<?php

namespace Rissc\Printformer\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Rissc\Printformer\Gateway\Admin\Product;
use Rissc\Printformer\Helper\Config;
use Rissc\Printformer\Helper\Api;
use Rissc\Printformer\Logger\PrintformerLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Sync extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Product
     */
    protected $gateway;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var Api
     */
    private Api $apiHelper;

    /**
     * @var PrintformerLogger
     */
    protected $printformerLogger;

    /**
     * Sync constructor.
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param JsonFactory $resultJsonFactory
     * @param Product $gateway
     * @param Config $config
     * @param Api $apiHelper
     * @param PrintformerLogger $printformerLogger
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        JsonFactory $resultJsonFactory,
        Product $gateway,
        Config $config,
        Api $apiHelper,
        PrintformerLogger $printformerLogger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->gateway = $gateway;
        $this->config = $config;
        $this->_scopeConfig = $scopeConfig;
        $this->apiHelper = $apiHelper;
        $this->printformerLogger = $printformerLogger;
        parent::__construct($context);
    }

    public function execute()
    {
        $storeId = $this->getRequest()->getParam('store_id', false);
        $websiteId = $this->getRequest()->getParam('website_id', false);

        try {
            if (!$this->config->isEnabled($storeId, $websiteId)) {
                $this->printformerLogger->error(__('Module disabled.') .' '. __('StoreId:'). $storeId);
                throw new \Exception(__('Module disabled.'));
            }

            try {
                $url = $this->apiHelper->apiUrl()->getClientName($storeId, $websiteId);
                $httpClient = $this->apiHelper->getHttpClient($storeId, $websiteId);

                $response = $httpClient->get($url);
                $response = json_decode($response->getBody(), true);
                $name = $response['data']['name'];
                $this->printformerLogger->info(__('Templates synchronized start') .' '. __('Mandator:'). $name .
                    ' ' . __('StoreId:'). $storeId);
                $this->gateway->syncProducts($storeId, $websiteId);
                $response = ['success' => 'true', 'message' => __('Templates synchronized successfully.').'<br>'.__('Mandator:').$name];
                $this->printformerLogger->info(__('Templates synchronized finish') .' '. __('Mandator:'). $name .
                    ' ' . __('StoreId:'). $storeId);
            } catch (\Exception $e) {
                $response = ['error' => 'true', 'message' => __('Error setting name client configuration. Empty Response. Url: ' . $url)];
                $this->printformerLogger->error( __('Error setting name client configuration. Empty Response. Url: ' .
                    $url .' '. __('StoreId:'). $storeId));
            }
        } catch (\Exception $e){
            $response = ['error' => 'true', 'message' => $e->getMessage()];
            $this->printformerLogger->error(__('Templates synchronized Error.') .
                ' '. __('StoreId:'). $storeId .' '. $e->getMessage());
        }
        $this->_actionFlag->set('', self::FLAG_NO_POST_DISPATCH, true);
        $resultJson = $this->resultJsonFactory->create();
        return $resultJson->setData($response);
    }

    /**
     * {@inheritdoc}
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Rissc_Printformer::config');
    }


}
