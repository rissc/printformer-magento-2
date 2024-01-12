<?php
namespace Rissc\Printformer\Helper;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Store\Model\Store;
use Rissc\Printformer\Helper\Api\Url as UrlHelper;
use Magento\Store\Model\StoreManagerInterface;
use Rissc\Printformer\Model\Draft;
use Rissc\Printformer\Model\DraftFactory;
use GuzzleHttp\Psr7\Stream as Psr7Stream;
use Rissc\Printformer\Helper\Session as SessionHelper;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Backend\Model\Session as AdminSession;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order\ItemFactory;
use Magento\Framework\Serialize\SerializerInterface;
use Rissc\Printformer\Helper\Log as LogHelper;
use Rissc\Printformer\Model\ResourceModel\Draft as DraftResource;
use Rissc\Printformer\Helper\Catalog as CatalogHelper;
use Rissc\Printformer\Helper\Config as ConfigHelper;
use Rissc\Printformer\Helper\Api\Url\V2;
use GuzzleHttp\ClientFactory;
//todo: integrate sdk everywhere
use Rissc\Printformer\Helper\Sdk\PrintformerSdkSingleton;
use Rissc\Printformer\Printformer;

class Api extends AbstractHelper
{
    const API_URL_CALLBACKORDEREDSTATUS = 'callbackOrderedStatus';
    const API_UPLOAD_INTENT = 'upload';
    const CALLBACK_UPLOAD_ENDPOINT = 'printformer/process/draft';
    const ProcessingStateAfterOrder = 9;
    const ProcessingStateAfterCron = 2;
    const ProcessingStateAfterUploadCallback = 3;
    const ProcessingStateAdminMassResend = 4;
    const DRAFT_USAGE_PAGE_INFO_PREVIEW = 'preview';
    const DRAFT_USAGE_PAGE_INFO_PRINT = 'print';

    /** @var UrlHelper */
    protected $_urlHelper;

    /** @var CustomerSession */
    protected $_customerSession;

    /** @var SessionHelper */
    protected $_sessionHelper;

    /** @var StoreManagerInterface */
    protected $_storeManager;

    /** @var DraftFactory */
    protected $_draftFactory;

    /** @var Config */
    protected $_config;

    /** @var CustomerFactory */
    protected $_customerFactory;

    /** @var CustomerResource */
    protected $_customerResource;

    /** @var Client[] */
    protected $_httpClients = [];

    /** @var int */
    protected $_storeId = Store::DEFAULT_STORE_ID;

    /** @var AdminSession */
    protected $_adminSession;

    /**
     * @var PrintformerProductAttributes
     */
    protected $printformerProductAttributes;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var ItemFactory
     */
    private $_itemFactory;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var OrderItemRepositoryInterface
     */
    protected $orderItemRepository;

    /**
     * @var Log
     */
    private $_logHelper;

    /**
     * @var Configuration
     */
    private Configuration $jwtConfig;
    private DraftResource $draftResource;
    private Context $context;
    private Config $configHelper;

    /** @var CatalogHelper */
    protected $_catalogHelper;
    private ClientFactory $clientFactory;
    private Printformer $printformerSdk;

    /**
     * @param SerializerInterface $serializer
     */
    protected $serializer;


    /**
     * @param Context $context
     * @param CustomerSession $customerSession
     * @param UrlHelper $urlHelper
     * @param StoreManagerInterface $storeManager
     * @param DraftFactory $draftFactory
     * @param Session $sessionHelper
     * @param Config $config
     * @param CustomerFactory $customerFactory
     * @param CustomerResource $customerResource
     * @param AdminSession $adminSession
     * @param PrintformerProductAttributes $printformerProductAttributes
     * @param Filesystem $filesystem
     * @param UrlInterface $urlBuilder
     * @param ItemFactory $itemFactory
     * @param TimezoneInterface $timezone
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param Log $_logHelper
     * @param DraftResource $draftResource
     * @param Catalog $catalogHelper
     * @param Config $configHelper
     * @param SerializerInterface $serializer
     * @param ClientFactory $clientFactory
     */
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        UrlHelper $urlHelper,
        StoreManagerInterface $storeManager,
        DraftFactory $draftFactory,
        SessionHelper $sessionHelper,
        Config $config,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        AdminSession $adminSession,
        PrintformerProductAttributes $printformerProductAttributes,
        Filesystem $filesystem,
        UrlInterface $urlBuilder,
        ItemFactory $itemFactory,
        TimezoneInterface $timezone,
        OrderItemRepositoryInterface $orderItemRepository,
        LogHelper $_logHelper,
        DraftResource $draftResource,
        CatalogHelper $catalogHelper,
        ConfigHelper $configHelper,
        SerializerInterface $serializer,
        ClientFactory $clientFactory
    ) {
        $this->_customerSession = $customerSession;
        $this->_urlHelper = $urlHelper;
        $this->_storeManager = $storeManager;
        $this->_draftFactory = $draftFactory;
        $this->_sessionHelper = $sessionHelper;
        $this->_config = $config;
        $this->_customerFactory = $customerFactory;
        $this->_customerResource = $customerResource;
        $this->_adminSession = $adminSession;
        $this->printformerProductAttributes = $printformerProductAttributes;
        $this->filesystem = $filesystem;
        $this->urlBuilder = $urlBuilder;
        $this->_itemFactory = $itemFactory;
        $this->timezone = $timezone;
        $this->orderItemRepository = $orderItemRepository;
        $this->_logHelper = $_logHelper;
        $this->draftResource = $draftResource;
        $this->context = $context;
        $this->_catalogHelper = $catalogHelper;
        $this->configHelper = $configHelper;
        $this->serializer = $serializer;
        $this->clientFactory = $clientFactory;

        try {
            $config = $this->getPrintformerConfig($this->getStoreId(), $this->getWebsiteId());
            $this->printformerSdk = PrintformerSdkSingleton::getInstance($config)->getSdk();
        } catch (\Exception $e) {
            $this->_logger->critical('Unable to load singleton printformer-sdk instance');
        }

        $this->jwtConfig = $this->getJwtConfig();

        $this->apiUrl()->initVersionHelper();
        $this->apiUrl()->setStoreManager($storeManager);

        parent::__construct($context);
    }

    public function getStoreId(): int | null
    {
        $storeId = null;
        if ($this->_storeManager) {
            if ($this->_storeManager->getStore()) {
                $storeId = $this->_storeManager->getStore()->getId();
            }
        }
        return $storeId;
    }

    public function getWebsiteId(): int | null
    {
        $websiteId = null;
        if ($this->_storeManager) {
            if ($this->_storeManager->getStore()) {
                $websiteId = $this->_storeManager->getStore($this->_storeManager->getStore()->getId())->getWebsiteId();
            }
        }
        return $websiteId;
    }

    /**
     * @return Client
     */
    public function getHttpClient($storeId = false, $websiteId = false)
    {
        if ($storeId == false && $websiteId == false){
            $storeId = $this->getStoreManager()->getStore()->getId();
            $websiteId = $this->getStoreManager()->getWebsite()->getId();
        }

        if (!isset($this->_httpClients[$storeId])) {
            $this->_httpClients[$storeId] = $this->clientFactory->create(
                [
                    'config' => [
                        'base_url' => $this->apiUrl()->getPrintformerBaseUrl($storeId, $websiteId),
                        'headers' => [
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $this->_config->getClientApiKey($storeId, $websiteId),
                        ],
                    ],
                ],
            );
        }

        return $this->_httpClients[$storeId];
    }

    /**
     * @return Config
     */
    public function config()
    {
        return $this->_config;
    }

    /**
     * @return StoreManagerInterface
     */
    public function getStoreManager()
    {
        return $this->_storeManager;
    }


    /**
     * @param $customer
     * @return void
     */
    public function checkUserData($customer)
    {
        if ($customer->getPrintformerIdentification() !== null) {
            $transfer = $this->_config->isDataTransferEnabled();
            if (!$transfer) {
                return;
            }
            //todo:  $createdEntry = $this->_logHelper->createGetEntry('Show User Data');
            $userIdentifier = null;
            try {
                $pfUser = $this->printformerSdk->clientFactory()->user()->show($customer->getPrintformerIdentification());
                $userIdentifier = $pfUser->identifier;
                //todo: $this->_logHelper->updateEntry($createdEntry, ['response_data' => $responseBody]);
                if ($userIdentifier) {

                    if ($pfUser->firstName == '' || $pfUser->lastName == '') {
                        $pfUser = $this->printformerSdk->clientFactory()->user()->update($userIdentifier, [
                            'email' => $customer->getEmail() ? $customer->getEmail() : null,
                            'firstName' => $customer->getFirstname() ? $customer->getFirstname() : null,
                            'lastName' => $customer->getLastname() ? $customer->getLastname() : null
                        ]);

                        //todo: $createdEntry = $this->_logHelper->createPutEntry('Update User', $pfUser);
                        //todo: $this->_logHelper->updateEntry($createdEntry, ['response_data' => $pfUser]);
                    }
                }
            } catch (Exception $e) {
                $this->_logger->critical($e);
            }
        } else {
            $userIdentifier = $this->createUser($customer);
            $customer->setData('printformer_identification', $userIdentifier);
        }
    }

    /**
     * @param Customer|int $customer
     * @param null $admin
     *
     * @return string
     * @throws AlreadyExistsException
     */
    public function getUserIdentifier($customer = null, $admin = null)
    {
        $stores = $this->getStoreManager()->getStores(true, false);

        foreach ($stores as $store) {
            if ($store->getCode() == 'admin') {
                if ($admin !== null) {
                    if (!$admin->getData('printformer_identification')) {
                        $adminUserIdentifier = $this->createUser($customer);
                        $connection = $admin->getResource()->getConnection();
                        $connection->query("
                        UPDATE " . $connection->getTableName('admin_user') . "
                        SET
                            `printformer_identification` = '" . $adminUserIdentifier . "'
                        WHERE
                            `user_id` = " . $admin->getId() . ";
                    ");
                        $this->_adminSession->setPrintformerIdentification($adminUserIdentifier);
                    } else {
                        if ($admin->getData('printformer_identification') != $this->_adminSession->getPrintformerIdentification()) {
                            $this->_adminSession->setPrintformerIdentification($admin->getData('printformer_identification'));
                        }
                    }

                    return $this->_adminSession->getPrintformerIdentification();
                }
            }

            continue;
        }

        if (is_numeric($customer) && $customer === 0) {
            return null;
        }

        $customerModel = null;
        if (!empty($customer) && is_numeric($customer)) {

            /** @var Customer $customerModel */
            $customerModel = $this->_customerFactory->create();
            $this->_customerResource->load($customerModel, $customer);

            $customer = $customerModel;
            $this->checkUserData($customer);
        }

        if ($customer !== null) {
            return $customer->getPrintformerIdentification();
        }

        if ($this->_customerSession->isLoggedIn()) {
            $customer = $this->_customerSession->getCustomer();
            $customer->getResource()->load($customer, $customer->getId());

            if (!$customer->getData('printformer_identification')) {
                $customerUserIdentifier = $this->loadPrintformerIdentifierOnCustomer($customer);
                $this->_customerSession->setPrintformerIdentification($customerUserIdentifier);
            } else {
                if ($customer->getData('printformer_identification') !=
                    $this->_customerSession->getPrintformerIdentification()) {
                    $this->_customerSession->setPrintformerIdentification(
                        $customer->getData('printformer_identification')
                    );
                }
            }

            $this->checkUserData($customer);

        } else {
            if (!$this->_customerSession->getPrintformerIdentification()) {
                $guestUserIdentifier = $this->createUser();
                $this->_customerSession->setPrintformerIdentification($guestUserIdentifier);
            }
        }

        return $this->_customerSession->getPrintformerIdentification();
    }

    /**
     * @return UrlHelper
     */
    public function apiUrl()
    {
        return $this->_urlHelper;
    }

    /**
     * @param DataObject $customer
     * @return string|null
     */
    public function createUser(DataObject $customer = new DataObject()): string | null
    {
        $userIdentifier = null;
        $transfer = $this->_config->isDataTransferEnabled();
        try {

            $pfUser = $this->printformerSdk->clientFactory()->user()->create([
                'email' => $customer->getEmail() ? $customer->getEmail() : null,
                'firstName' => $customer->getFirstname() ? $customer->getFirstname() : null,
                'lastName' => $customer->getLastname() ? $customer->getLastname() : null,
                //todo: 'title' => '',
                //todo: 'salutation' => '',
                //todo: 'customAttributes' => '',
                //todo: 'locale' => '',
            ]);
            $userIdentifier = $pfUser->identifier;
            //todo:  $createdEntry = $this->_logHelper->createPostEntry('create User', $pfUser);
            //todo:  $this->_logHelper->updateEntry($createdEntry, ['response_data' => $pfUser]);
        } catch (Exception $e) {
            $this->_logger->critical($e);
        }

        return $userIdentifier;
    }

    /**
     * @param int $identifier
     * @param string $userIdentifier
     * @param array $params
     * @return mixed
     */
    public function createDraftHash($identifier, $userIdentifier, $params = [])
    {
        $draftClient = $this->printformerSdk->clientFactory()->draft();
        $draft = $draftClient
            ->create([
                'templateIdentifier' => $identifier,
                'user_identifier' => $userIdentifier,
                'intent' => $params['intent']
            ]);
        $draftHash = $draft->draftHash;
        $draftData = json_decode(json_encode($draft), true);
        $draftDataJson = json_encode($draft);

        $requestData = [
            'json' => [
                'user_identifier' => $userIdentifier
            ]
        ];
        if (!empty($identifier)) {
            $requestData['json']['templateIdentifier'] = $identifier;
        }
        $params = $this->mergeAdditionalParamsForApiCall($params);
        foreach ($params as $key => $value) {
            $requestData['json'][$key] = $value;
        }

        $createdEntry = $this->_logHelper->createPostEntry('Create Draft', $requestData);
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson, 'draft_id' => $draftHash]);

        if ($this->_sessionHelper->hasDraftInCache($draftHash)) {
            $this->_sessionHelper->updateDraftInCache($draftHash, $draftData);
        } else {
            $this->_sessionHelper->addDraftToCache($draftHash, $draftData);
        }

        return $draftHash;
    }

    /**
     * @param $originUserIdentifier
     * @param $tempUserIdentifier
     * @return \Rissc\Printformer\Client\User\User|void
     */
    public function mergeUsers($originUserIdentifier, $tempUserIdentifier)
    {
        try {
            $pfUser = $this->printformerSdk->clientFactory()->user()->merge($originUserIdentifier, $tempUserIdentifier);
            return $pfUser;
        } catch (Exception $e) {
            $this->_logger->critical($e);
        }
    }

    /**
     * @param $draftHash
     * @param $orderId
     * @return mixed
     */
    public function updateDraftHash($draftHash, $orderId)
    {
        $draftClient = $this->printformerSdk->clientFactory()->draft();
        $requestData = [
            'customAttributes' => [
                $this->_config->getOrderDraftUpdateOrderId() => $orderId
            ]
        ];
        $draft = $draftClient->update($draftHash, $requestData);

        $draftHash = $draft->draftHash;
        $draftData = json_decode(json_encode($draft), true);
        $draftDataJson = json_encode($draft);

        $createdEntry = $this->_logHelper->createPutEntry('Update DraftHash', $requestData);
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson]);

        if ($this->_sessionHelper->hasDraftInCache($draftHash)) {
            $this->_sessionHelper->updateDraftInCache($draftHash, $draftData);
        } else {
            $this->_sessionHelper->addDraftToCache($draftHash, $draftData);
        }

        return $draftHash;
    }

    /**
     * @param $draftHash
     * @param $pageInfo
     * @return array|mixed
     */
    public function getDraftUsagePageInfo($draftHash, $pageInfo)
    {
        $storedDraftPageInfo = $this->_sessionHelper->getDraftPageInfo($draftHash);

        if (!$storedDraftPageInfo) {
            $draftClient = $this->printformerSdk->clientFactory()->draft();
            $draft = $draftClient->pageInfo($draftHash, $pageInfo);

            $draftData = json_decode(json_encode($draft), true);
            $draftDataJson = json_encode($draft);

            $createdEntry = $this->_logHelper->createGetEntry('Page Info');
            $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson]);

            $this->_sessionHelper->setDraftPageInfo($draftHash, $draftData);
            $result =  $draftData;
        } else {
            $result = $storedDraftPageInfo[$draftHash];
        }

        return $result;
    }

    /**
     * @param $draftId
     * @param $downloadableLinkFilePath
     * @param $social
     * @return bool
     */
    public function uploadPdf($draftId, $downloadableLinkFilePath, $social = false)
    {
        $absoluteMediaPath = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        $absoluteDownloadableMediaPath = $absoluteMediaPath.'downloadable/files/links';
        $absoluteLinkFilePath = $absoluteDownloadableMediaPath.$downloadableLinkFilePath;

        if (file_exists($absoluteLinkFilePath) && is_readable($absoluteLinkFilePath)){
            //Create write instance on tmp file-location
            $directoryWriteInstance = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

            //copy file to media tmp directory
            $downloadableTempLinkFilePath = DirectoryList::TMP.DIRECTORY_SEPARATOR.self::API_UPLOAD_INTENT.DIRECTORY_SEPARATOR.$draftId.DIRECTORY_SEPARATOR.basename($downloadableLinkFilePath);
            $fullDownloadableTempLinkFilePath = $absoluteMediaPath.$downloadableTempLinkFilePath;
            if (!file_exists($fullDownloadableTempLinkFilePath)){
                $directoryWriteInstance->copyFile($absoluteLinkFilePath, $downloadableTempLinkFilePath);
            }

            //generate temporary pdf-url for temporary file to upload into printformer api
            $baseUrl = $this->getStoreManager()->getStore()->getBaseUrl();
            $apiUrl = $this->apiUrl()->getUploadDraftId($draftId);

            $filePathUrl = $baseUrl.DirectoryList::PUB.DIRECTORY_SEPARATOR.DirectoryList::MEDIA.DIRECTORY_SEPARATOR.$downloadableTempLinkFilePath;

            if (isset($filePathUrl) && isset($apiUrl)) {

                //upload temporary file-url
                $requestData = [
                    'json' => [
                        'fileURL' => $filePathUrl
                    ]
                ];
                if (!$social) {
                    $callBackUrl = $this->getUploadCallbackUrl($draftId);
                    $callBackUrlWithQueryString = $callBackUrl.'?filepath='.$downloadableTempLinkFilePath;
                    $this->_logger->notice('Used callbackUrl='.$callBackUrlWithQueryString);
                    $requestData['json']['callbackURL'] = $callBackUrlWithQueryString;
                }

                try {
                    $createdEntry = $this->_logHelper->createPostEntry($apiUrl, $requestData);
                    $response = $this->getHttpClient()->post($apiUrl, $requestData);
                    $this->_logHelper->updateEntry($createdEntry, ['response_data' => $response->getBody()->getContents()]);

                    if ($response->getStatusCode() === 204){
                        $this->_logger->debug('Upload status code: 204'.'for upload with draft id: '.$draftId);
                        return true;
                    } else {
                        $this->_logger->debug('Upload status code: '.$response->getStatusCode().'for upload with draft id: '.$draftId);
                        return false;
                    }
                } catch (Exception $e) {
                    $this->_logger->debug('Upload failed for draft with draft-id: '.$draftId.' on file path order-id'.$filePathUrl.' for callback url: '.$callBackUrlWithQueryString);
                    $this->_logger->debug('Upload error message: '.$e->getMessage());
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * @param $draftId
     * @return string
     */
    public function getUploadCallbackUrl($draftId)
    {
        $params['draft_id'] = $draftId;
        return $this->urlBuilder->getUrl(self::CALLBACK_UPLOAD_ENDPOINT, $params);
    }


    /**
     * @param string $oldDraftId
     * @return string
     */
    public function getReplicateDraftId(string $oldDraftId): string
    {
        if ($this->_sessionHelper->hasDraftInCache($oldDraftId)) {
            $this->_sessionHelper->removeDraftFromCache($oldDraftId);
        }
        $draftClient = $this->printformerSdk->clientFactory()->draft();
        $draft = $draftClient->replicate($oldDraftId, []);
        $draftHash = $draft->draftHash;
        $draftData = json_decode(json_encode($draft), true);
        $draftDataJson = json_encode($draft);

        $createdEntry = $this->_logHelper->createGetEntry('Replicate Draft');
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson]);

        if ($this->_sessionHelper->hasDraftInCache($draftHash)) {
            $this->_sessionHelper->updateDraftInCache($draftHash, $draftData);
        } else {
            $this->_sessionHelper->addDraftToCache($draftHash, $draftData);
        }

        return $draftHash;
    }

    /**
     * @param string $draftHash
     * @param bool $forceUpdate
     * @return array
     */
    public function getPrintformerDraft($draftHash, $forceUpdate = false)
    {
        if (!$this->_sessionHelper->hasDraftInCache($draftHash) || $forceUpdate) {
            $draftClient = $this->printformerSdk->clientFactory()->draft();
            $draft = $draftClient->show($draftHash);
            $draftHash = $draft->draftHash;
            $draftData = json_decode(json_encode($draft), true);
            $draftDataJson = json_encode($draft);
            $createdEntry = $this->_logHelper->createGetEntry('Show Draft');
            $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson]);

            if ($forceUpdate && $this->_sessionHelper->hasDraftInCache($draftHash)) {
                $this->_sessionHelper->updateDraftInCache($draftHash, $draftData);
            } else {
                $this->_sessionHelper->addDraftToCache($draftHash, $draftData);
            }
        }

        return $this->_sessionHelper->getDraftCache($draftHash);
    }

    public function getEditorSdk($draftHash, $userIdentifier, $params = [])
    {
//        $editorUrl = $this->getPrintformerBaseUrl() .
//            self::EXT_EDITOR_PATH;


        $dataParams = [
            'product_id' => $params['product_id'],
            'draft_process' => $params['data']['draft_process']
        ];

        if (!empty($params['data']['quote_id'])) {
            $dataParams['quote_id'] = $params['data']['quote_id'];
        }

        $customCallbackUrl = null;
        if (!empty($params['data']['callback_url'])) {
            $customCallbackUrl = $params['data']['callback_url'];
        }

        $queryParams = [];
        $callback = $this->getCallbackSdkUrl(
            $customCallbackUrl,
            $this->_storeManager->getStore()->getId(),
            $dataParams
        );
        if ($this->_config->getRedirectProductOnCancel()) {
            $callbackCancel = $this->getProductCallbackSdkUrl(intval($params['product_id']), $params['data'], $this->_storeManager->getStore()->getId());
        }
        $editorUrl = (string)$this->printformerSdk->urlGenerator()->editor()
            ->draft($draftHash)
            ->callback($callback)
            ->callbackCancel($callbackCancel ?? '') // Optional, if omitted the callbackCancel URL is used
            ->user($userIdentifier);
        //->step('preview');

        return $editorUrl;
    }


    /**
     * @param Product | int $product
     * @param array $params
     * @param int $storeId
     *
     * @return string
     */
    protected function getProductCallbackSdkUrl(
        $product,
        $params = [],
        $storeId = 0
    )
    {
        $product = $this->_catalogHelper->prepareProduct($product);

        if (isset($params['quote_id']) && $product->getId()) {
            $referrerParams['id'] = $params['quote_id'];
            $referrerParams['product_id'] = $product->getId();

            $baseUrl = $this->_urlBuilder->getUrl('checkout/cart/configure', $referrerParams);
        } else {
            $baseUrl = $product->getProductUrl(null);
        }

        return $baseUrl;
    }

    /**
     * @param string $requestReferrer
     * @param int $storeId
     * @param array $params
     *
     * @return string
     */
    protected function getCallbackSdkUrl(
        $requestReferrer,
        $storeId = 0,
        $params = []
    )
    {
        if ($requestReferrer != null) {
            $referrer = urldecode($requestReferrer);
        } else {
            $referrerParams = array_merge($params, [
                'store_id' => $storeId,
            ]);

            if (isset($params['quote_id']) && isset($params['product_id'])) {
                $referrerParams['quote_id'] = $params['quote_id'];
                $referrerParams['edit_product'] = $params['product_id'];
                $referrerParams['is_edit'] = 1;
            }

            $referrer = $this->_urlBuilder->getUrl('printformer/editor/save', $referrerParams);
        }

        return $referrer;
    }

    /**
     * @param string $draftHash
     * @param string $userIdentifier
     * @param array  $params
     *
     * @return string
     * @throws Exception
     */
    public function getEditorWebtokenUrl($draftHash, $userIdentifier, $params = [])
    {
        $storeId = $this->getStoreId();
        // Check store id for admin pages
        if (isset($params['store_id'])){
            $storeId = $params['store_id'];
            try {
                $apiKey = $this->_config->getClientApiKey($storeId);
                if (!empty($apiKey)) {
                    $this->jwtConfig = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($apiKey));
                }
            } catch (NoSuchEntityException $e) {
            }
        }
        $editorOpenUrl = $this->getEditorSdk($draftHash, $userIdentifier, $params);
        $client = $this->_config->getClientIdentifier($storeId);

        $identifier = bin2hex(random_bytes(16));
        $issuedAt = new DateTimeImmutable();
        $expirationDate = $this->_config->getExpireDate($storeId);
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $client)
            ->withClaim('user', $userIdentifier)
            ->identifiedBy($identifier)
            ->withClaim('redirect', $editorOpenUrl)
            ->expiresAt($expirationDate)
            ->withHeader('jti', $identifier);
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $setIssuedAtTimestamp = time();
        $expirationDateTimeStamp = $this->_config->getExpireDateTimeStamp($storeId);
        $requestData = [
            'apiKey' => $this->_config->getClientApiKey($storeId),
            'storeId' => $storeId
        ];
        $data = [
            'draftId' => $draftHash,
            'userIdentifier' => $userIdentifier,
            'params' => $params,
            'storeId' => $storeId,
            'client' => $client,
            'id' => $identifier,
            'replicateAsHeader' => true,
            'redirect' => $editorOpenUrl,
            'expirationDateTimestamp' => $expirationDateTimeStamp,
            'expirationDateISO8601' => date('c',$expirationDateTimeStamp),
            'setIssuedAtTimestamp' => $setIssuedAtTimestamp,
            'setIssuedAtISO8601' => date('c',$setIssuedAtTimestamp),
            'request_data' => $requestData,
        ];
        $entry = $this->_logHelper->createRedirectEntry($editorOpenUrl, $data);

        $redirectUrl = $this->apiUrl()->getPrintformerBaseUrl($storeId) . V2::EXT_AUTH_PATH . '?' . http_build_query(['jwt' => $JWT]);
        $entry->setResponseData(json_encode(["redirectUrl" => $redirectUrl, "jwt" => $JWT]));
        $this->_logHelper->updateEntry($entry);

        return $redirectUrl;
    }

    /**
     * @param string $draftHash
     * @param int $identifier
     * @param int $productId
     * @param string $intent
     * @param string $sessionUniqueId
     * @param int $customerId
     * @param int $printformerProductId
     * @param false $checkOnly
     * @param string $colorVariation
     * @param array $availableVariants
     * @return DataObject|Draft
     * @throws NoSuchEntityException
     */
    public function draftProcess(
        $draftHash = null,
        $identifier = null,
        $productId = null,
        $intent = null,
        $sessionUniqueId = null,
        $customerId = null,
        $printformerProductId = null,
        $checkOnly = false,
        $colorVariation = null,
        $availableVariants = []
    ) {
        $storeId = $this->_storeManager->getStore()->getId();

        $process = $this->getDraftProcess($draftHash, $productId, $intent, $sessionUniqueId);
        $catalogSession = $this->_sessionHelper->getCatalogSession();
        $preselectData = $catalogSession->getSavedPrintformerOptions();
        $options = null;
        if (!empty($preselectData['super_attribute'])) {
            $options = $this->serializer->serialize($preselectData['super_attribute']);
        }

        if ($process->getId() && $options) {
            $process->setSuperAttribute($options);
            $process->getResource()->save($process);
        }

        if (!$process->getId() && !$checkOnly) {
            $dataParams = [
                'intent' => $intent
            ];

            if (!empty($availableVariants) && is_array($availableVariants)) {
                $dataParams['availableVariantVersions'] = $availableVariants;
                $process->addData([
                    'available_variants' => implode(",", $availableVariants)
                ]);
            }

            if (!$draftHash) {
                try {
                    $draftHash = $this->createDraftHash($identifier, $this->getUserIdentifier(), $dataParams);
                } catch (AlreadyExistsException $e) {
                    $this->_logger->critical('Failed to create draft');
                }
            }

            try {
                $process->addData([
                    'draft_id' => $draftHash,
                    'store_id' => $storeId,
                    'intent' => $intent,
                    'session_unique_id' => $sessionUniqueId,
                    'product_id' => $productId,
                    'customer_id' => $customerId,
                    'user_identifier' => $this->getUserIdentifier(),
                    'created_at' => time(),
                    'printformer_product_id' => $printformerProductId,
                    'color_variation' => $colorVariation,
                    'super_attribute' => $options
                ]);
                $process->getResource()->save($process);
            } catch (AlreadyExistsException $e) {
                $this->_logger->critical('Failed to save draft');
            }
        }

        return $process;
    }

    /**
     * @param null $draftHash
     * @param null $productId
     * @param null $intent
     * @param null $sessionUniqueId
     * @param null $colorVariation
     * @return DataObject|Draft|null
     */
    public function updateColorVariationOnProcess(
        $draftHash = null,
        $productId = null,
        $intent = null,
        $sessionUniqueId = null,
        $colorVariation = null
    ) {
        $process = null;
        try {
            $process = $this->getDraftProcess($draftHash, $productId, $intent, $sessionUniqueId);
            if($process->getId()) {
                $process->addData([
                    'color_variation' => $colorVariation
                ]);
                $process->getResource()->save($process);
            }
        } catch (Exception $e) {
            $this->_logger->critical('Failed to update current colorVariation');
        }

        return $process;
    }

    /**
     * @param null $draftHash
     * @param int $identifier
     * @param null $productId
     * @param null $sessionUniqueId
     * @param null $customerId
     * @param null $printformerProductId
     * @param bool $checkOnly
     * @param null $printformerUserIdentifier
     * @param null $templateIdentifier
     * @param null $orderId
     * @param null $storeId
     * @return DataObject|Draft
     */
    public function uploadDraftProcess(
        $draftHash = null,
        $identifier = 0,
        $productId = null,
        $sessionUniqueId = null,
        $customerId = null,
        $printformerProductId = null,
        $checkOnly = false,
        $printformerUserIdentifier = null,
        $templateIdentifier = null,
        $orderId = null,
        $storeId = null,
        $orderItemId = null,
        $orderIncrementId = null
    ) {
        $process = $this->getDraftProcess($draftHash, $productId, self::API_UPLOAD_INTENT, $sessionUniqueId);
        if(!$process->getId() && !$checkOnly) {
            if (!$draftHash) {
                $dataParams = [
                    'intent' => self::API_UPLOAD_INTENT
                ];

                if ($this->_config->getOrderDraftUpdate($storeId) && !empty($orderIncrementId)) {
                    $dataParams['customAttributes'] = [
                        $this->_config->getOrderDraftUpdateOrderId() => $orderIncrementId
                    ];
                }

                $additionalUploadDataParams = [
                    'templateIdentifier' => $templateIdentifier,
                    'user_identifier' => $printformerUserIdentifier
                ];

                $dataParams = array_merge($dataParams, $additionalUploadDataParams);
                $draftHash = $this->createDraftHash($identifier, $printformerUserIdentifier, $dataParams);

                $process->addData([
                    'draft_id' => $draftHash,
                    'store_id' => $storeId,
                    'intent' => self::API_UPLOAD_INTENT,
                    'session_unique_id' => $sessionUniqueId,
                    'product_id' => $productId,
                    'customer_id' => $customerId,
                    'user_identifier' => $printformerUserIdentifier,
                    'created_at' => time(),
                    'printformer_product_id' => $printformerProductId,
                    'order_item_id' => $orderItemId
                ]);
                $process->getResource()->save($process);
            }
        }

        return $process;
    }

    /**
     * @param string $draftHash
     * @param int    $productId
     * @param string $intent
     * @param string $sessionUniqueId
     * @param int    $printformerProductId
     *
     * @return DataObject|Draft
     * @throws Exception
     */
    public function getDraftProcess(
        $draftHash = null,
        $productId = null,
        $intent = null,
        $sessionUniqueId = null,
        $printformerProductId = null
    ) {
        /** @var Draft $process */
        $process = $this->_draftFactory->create();

        $draftCollection = $process->getCollection();
        if($draftHash !== null) {
            $draftCollection->addFieldToFilter('draft_id', ['eq' => $draftHash]);
        } else {
            if($intent !== null) {
                $draftCollection->addFieldToFilter('intent', ['eq' => $intent]);
            }
            $draftCollection->addFieldToFilter('session_unique_id', ['eq' => $sessionUniqueId]);
            $draftCollection->addFieldToFilter('product_id', ['eq' => $productId]);
        }
        if($printformerProductId !== null) {
            $draftCollection->addFieldToFilter('printformer_product_id', ['eq' => $printformerProductId]);
        }
        if ($draftCollection->count() == 1) {
            $process = $draftCollection->getFirstItem();
            if ($process->getId() && $process->getDraftId()) {
                $this->_sessionHelper->setCurrentIntent($process->getIntent());
            }
        } else {
            $process = $draftCollection->getLastItem();
        }

        return $process;
    }

    /**
     * @param $processId
     *
     * @return Draft
     */
    public function loadDraftProcess($processId)
    {
        /** @var Draft $process */
        $process = $this->_draftFactory->create();
        $process->getResource()->load($process, $processId);

        return $process;
    }

    /**
     * Return Boolean to specify if Order-State is permitted corresponding to the pf-configuration
     *
     * @param $status
     * @return bool
     */
    public function isOrderStateValidToProcess($status): bool
    {
        if (in_array($status, $this->configHelper->getOrderStatus())) {
            $resultStatusOrderCanBeProcessed = true;
        } else {
            $resultStatusOrderCanBeProcessed = false;
        }

        return $resultStatusOrderCanBeProcessed;
    }

    /**
     * @param $draftIds
     */
    public function setAsyncOrdered($draftIds, $storeId = null)
    {
        try {
            $draftProcessingUrl = $this->apiUrl()->getDraftProcessing($draftIds);

            $stateChangedNotifyUrl = $this->_urlBuilder->getUrl('rest/V1/printformer') . self::API_URL_CALLBACKORDEREDSTATUS;
            $requestData = [
                'json' => [
                    'draftIds' => $draftIds,
                    'stateChangedNotifyUrl' => $stateChangedNotifyUrl
                ]
            ];

            $createdEntry = $this->_logHelper->createPostEntry($draftProcessingUrl, $requestData);
            $response = $this->getHttpClient($storeId)->post($draftProcessingUrl, $requestData);
            $this->_logHelper->updateEntry($createdEntry, ['response_data' => $response->getBody()->getContents()]);
        } catch (Exception $e) {
            $this->_logger->debug('Process for draft ids failed. Error-message: '.$e->getMessage());
        }

        if(!empty($response)) {
            $responseArray = json_decode($response->getBody(), true);
            $processingHash = !empty($responseArray['processingId']) ? $responseArray['processingId'] : null;
            if(!empty($processingHash)) {
                $draftIdsToProcessSuccess = [];
                $draftIdsToProcessFailed = [];

                foreach ($draftIds as $draftHash) {
                    try {
                        /** @var Draft $process */
                        $process = $this->getDraftProcess($draftHash);
                        if ($process->getId()) {
                            $process->setProcessingId($processingHash);
                            $process->setProcessingStatus(1);
                            $process->getResource()->save($process);
                        }
                        array_push($draftIdsToProcessSuccess, $draftHash);
                    } catch (Exception $e) {
                        array_push($draftIdsToProcessFailed, $draftHash);
                        $this->_logger->debug('Error on draft processing for draft: '.$draftHash.PHP_EOL.'Status-code: '.$e->getCode().PHP_EOL.$e->getMessage().'Line: '.$e->getLine().PHP_EOL.'File: '.$e->getFile());
                    }
                }

                $this->_logger->debug('Drafts processing failed: '.implode(",", $draftIdsToProcessFailed));
                $this->_logger->debug('Drafts processing successfully processed: '.implode(",", $draftIdsToProcessSuccess));
            }
        } else {
            $this->_logger->debug('Cant get response for draft-ids:'.implode(",", $draftIds));
        }
    }

    /**
     * @param $draftHash
     * @return false|OrderItemInterface
     */
    public function getOrderItemByDraftId($draftHash)
    {
        $orderItem = false;
        $process = $this->_draftFactory->create();

        $draftCollection = $process->getCollection();
        if($draftHash !== null) {
            $draftCollection->addFieldToFilter('draft_id', ['eq' => $draftHash]);
            $process = $draftCollection->getFirstItem();

            if ($process->getId()) {
                $process = $draftCollection->getLastItem();
                $orderItemId = $process->getOrderItemId();
                if(!empty($orderItemId)){
                    $orderItem = $this->orderItemRepository->get($orderItemId);
                }
            }
        }

        return $orderItem;
    }

    /**
     * @param $draftId
     */
    public function setProcessingStateOnOrderItemByDraftId($draftId, $printformerCountState)
    {
        /** @var OrderItemInterface $orderItem */
        $orderItem = $this->getOrderItemByDraftId($draftId);
        $item = $this->_itemFactory->create();
        if (isset($orderItem) && $orderItem->getItemId() !== null){
            $item->getResource()->load($item, $orderItem->getItemId());
            $item->setPrintformerCountState($printformerCountState);
            $item->setPrintformerCountDate($this->timezone->date()->format('Y-m-d H:i:s'));
            $item->getResource()->save($item);
        }
    }

    /**
     * @param $draftId
     * @param $customerId
     * @param $userIdentifier
     * @return false|Draft
     */
    public function generateNewReplicateDraft($draftId, $customerId = null, $userIdentifier = null)
    {
        $result = false;
        $oldDraftId = $draftId;

        $newDraftId = $this->getReplicateDraftId($oldDraftId);
        if (!empty($newDraftId)) {
            /** @var Draft $draftProcess */
            $draftProcess = $this->draftProcess($oldDraftId);
            if ($draftProcess->getId()) {
                /** @var Draft $newDraftProcess */
                $newDraftProcess = $this->_draftFactory->create();
                $draftData = $draftProcess->getData();
                unset($draftData['id']);
                unset($draftData['created_at']);
                unset($draftData['order_item_id']);
                unset($draftData['processing_id']);
                $draftData['processing_status'] = 0;

                if (isset($customerId)) {
                    $draftData['customer_id'] = $customerId;
                }

                if (isset($userIdentifier)) {
                    $draftData['user_identifier'] = $userIdentifier;
                }

                $newDraftProcess->addData($draftData);

                $newDraftProcess->setDraftId($newDraftId);
                $newDraftProcess->setCopyFrom($oldDraftId);

                $this->draftResource->save($newDraftProcess);
                $result = $newDraftProcess;
            }
        }

        return $result;
    }

    /**
     * @param array $parsed_url
     *
     * @return string
     */
    protected function unparseUrl($parsed_url) {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
    }

    /**
     * @param string $stringStatus
     *
     * @return int
     */
    public function getMappedProcessingStatus($stringStatus)
    {
        switch($stringStatus) {
            case 'processed':
                return 1;
                break;
            case 'failed':
                return 0;
                break;
            case 'pending':
            case 'in-process':
                return 2;
                break;
        }

        return -1;
    }

    /**
     * @param string $draftHash
     * @param string $userIdentifier
     * @param int $width
     * @param int $height
     * @param int $page
     * @return array
     */
    public function getThumbnail($draftHash, $userIdentifier, $width, $height, $page = 1)
    {
        try {
            $thumbnailUrl = (string)$this->printformerSdk->urlGenerator()->draftFiles()
                ->image($draftHash)
                ->width($width)
                ->height($height)
                ->page($page);

            $createdEntry = $this->_logHelper->createGetEntry($thumbnailUrl);
            $this->_logHelper->updateEntry($createdEntry, ['response_data' => 'IMAGE']);
        } catch(ServerException $e) {
            throw $e;
        }

        $info = getimagesize($thumbnailUrl);
        $responseData = [
            'content_type' =>  $info['mime'],
            'size' => $info,
            'content' => file_get_contents($thumbnailUrl)
        ];

        return $responseData;
    }

    /**
     * @param $draftHash
     *
     * @return string
     * @throws Exception
     */
    public function getPdfLink($draftHash)
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $pdfUrl = $this->apiUrl()->getPDF($draftHash);
        $postFields = [
            'jwt' => $JWT
        ];

        return $pdfUrl . '?' . http_build_query($postFields);
    }

    /**
     * @param string $userIdentifier
     * @param array $drafts
     * @param bool $dryRun
     * @return array
     */
    public function migrateDrafts($userIdentifier, array $drafts, $dryRun = false)
    {
        $draftClient = $this->printformerSdk->clientFactory()->draft();
        $draft = $draftClient->claim($userIdentifier, $drafts, $dryRun);
        $draftData = json_decode(json_encode($draft), true);
        $draftDataJson = json_encode($draft);

        $createdEntry = $this->_logHelper->createPostEntry('Claim Draft', $userIdentifier);
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $draftDataJson]);

        return $draftData;
    }

    /**
     * @param string $userIdentifierOne
     * @param string $userIdentifierTwo
     *
     * @return array
     */
    public function userMerge($userIdentifierOne, $userIdentifierTwo)
    {
        $requestData = [
            'json' => [
                'source_user_identifier' => $userIdentifierTwo
            ]
        ];

        $url = $this->apiUrl()->getPrintformerBaseUrl() . '/api-ext/user/' . $userIdentifierOne . '/merge';

        $createdEntry = $this->_logHelper->createPostEntry($url, $requestData);
        $response = $this->getHttpClient()->post($url, $requestData);
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $response->getBody()->getContents()]);
        return json_decode($response->getBody(), true);
    }

    /**
     * @param string $draftHash
     * @param array  $dataParams
     *
     * @return mixed
     */
    public function updatePrintformerDraft($draftHash, $dataParams = [])
    {
        $url = $this->apiUrl()->getDraft($draftHash);
        $requestData = [
            'json' => $dataParams
        ];

        $createdEntry = $this->_logHelper->createPutEntry($url, $requestData);
        $response = $this->getHttpClient()->put($url, $requestData);
        $this->_logHelper->updateEntry($createdEntry, ['response_data' => $response->getBody()->getContents()]);

        if ($response->getStatusCode() == 200) {
            return true;
        }

        return false;
    }

    /**
     * @param string $draftHash
     * @param string $colorVariant
     *
     * @return bool
     */
    public function updateColorVariant($draftHash, $colorVariant)
    {
        if ($draftHash && $colorVariant) {
            $draftData = $this->getPrintformerDraft($draftHash);
            if ($draftData['variant']['version'] != $colorVariant) {
                return $this->updatePrintformerDraft($draftHash, [
                    'version' => $colorVariant
                ]);
            }
        }

        return false;
    }

    /**
     * @param $fileId
     *
     * @return string
     */
    public function getDerivateLink($fileId)
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $derivateDownloadLink = $this->apiUrl()->getDerivat($fileId);

        $postFields = [
            'jwt' => $JWT
        ];

        return $derivateDownloadLink . '?' . http_build_query($postFields);
    }

    /**
     * @param $reviewId
     *
     * @return string
     */
    public function createReviewPdfUrl($reviewId)
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $createReviewPdfUrl = $this->apiUrl()->createReviewPDF($reviewId);

        $postFields = [
            'jwt' => $JWT
        ];

        return $createReviewPdfUrl . '?' . http_build_query($postFields);
    }

    /**
     * @param $reviewId
     *
     * @return string
     */
    public function getReviewPdfUrl($reviewId)
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $createReviewPdfUrl = $this->apiUrl()->getReviewPdf($reviewId);

        $postFields = [
            'jwt' => $JWT
        ];

        return $createReviewPdfUrl . '?' . http_build_query($postFields);
    }

    /**
     * @param $draftId
     *
     * @return string
     */
    public function getIdmlPackage($draftId)
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $getIdmlPackage = $this->apiUrl()->getIdmlPackage($draftId);

        $postFields = [
            'jwt' => $JWT
        ];

        return $getIdmlPackage . '?' . http_build_query($postFields);
    }

    /**
     * @param $draftId
     *
     * @return string
     */
    public function closePagePlanner()
    {
        $issuedAt = new DateTimeImmutable();
        $JWTBuilder = $this->jwtConfig->builder()
            ->issuedAt($issuedAt)
            ->withClaim('client', $this->_config->getClientIdentifier())
            ->expiresAt($this->_config->getExpireDate());
        $JWT = $JWTBuilder->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey())->toString();

        $createReviewPdfUrl = $this->apiUrl()->getPagePlannerApproveUrl();

        $postFields = [
            'jwt' => $JWT
        ];

        return $createReviewPdfUrl . '?' . http_build_query($postFields);
    }

    /**
     * Get printformer identifier from api call and save it to the db
     *
     * @param $customer
     * @return string
     */
    public function loadPrintformerIdentifierOnCustomer($customer)
    {
        $customerUserIdentifier = $this->createUser($customer);
        $connection = $customer->getResource()->getConnection();
        $connection->query("
                    UPDATE " . $connection->getTableName('customer_entity') . "
                    SET
                        `printformer_identification` = '" . $customerUserIdentifier . "'
                    WHERE
                        `entity_id` = " . $customer->getId() . ";
                ");
        $customer->setData('printformer_identification', $customerUserIdentifier);
        $customer->getResource()->saveAttribute($customer, 'printformer_identification');
        return $customerUserIdentifier;
    }

    /**
     * Merge function-params, options and additional draft-fields for the api-call
     *
     * @param $params
     * @return array
     */
    private function mergeAdditionalParamsForApiCall($params)
    {
        $params = $this->printformerProductAttributes->mergeFeedIdentifier($params);

        return $params;
    }

    protected function getPrintformerConfig($storeId, $websiteId): array
    {
        //todo>:> instead create user by sdk here
        //        $config = [
        //            'base_uri' => 'https://printformer.stage-00.aws.rissc.net',
        //            'identifier' => 'vD3rU3Qw',
        //            'api_key' => '04HjIzShfi.............',
        //        ];

        $baseUri = $this->apiUrl()->getPrintformerBaseUrl($storeId, $websiteId);
        $apiKey = $this->_config->getClientApiKey($storeId, $websiteId);
        $identifier = $this->_config->getClientIdentifier($storeId, $websiteId);

        $config = [
            'base_uri' => $baseUri,
            'identifier' => $identifier,
            'api_key' => $apiKey,
        ];

        return $config;
    }

    public function getJwtConfig(): \Lcobucci\JWT\Configuration | null
    {
        $apiKey = $this->_config->getClientApiKey($this->getStoreId());
        if (!empty($apiKey)) {
            return Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($apiKey));
        } else {
            return null;
        }
    }

    /**
     * @return string|void
     */
    public function getMandatorClientName()
    {
        $clientData = $this->printformerSdk->clientFactory()->tenant()->show();
        return $clientData->name;
    }
}
