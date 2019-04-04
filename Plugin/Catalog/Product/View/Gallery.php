<?php

namespace Rissc\Printformer\Plugin\Catalog\Product\View;

use Magento\Catalog\Block\Product\View\Gallery as SubjectGallery;
use Psr\Log\LoggerInterface;
use Rissc\Printformer\Helper\Config as ConfigHelper;
use Rissc\Printformer\Helper\Api as PrintformerApi;
use Rissc\Printformer\Block\Catalog\Product\View\Printformer as PrintformerBlock;
use Rissc\Printformer\Helper\Media;
use Rissc\Printformer\Helper\Api\Url as UrlHelper;
use Magento\Framework\Event\ManagerInterface;

class Gallery
{
    /**
     * @var ConfigHelper
     */
    protected $config;

    /**
     * @var PrintformerApi
     */
    protected $printformerApi;

    /**
     * @var PrintformerBlock
     */
    protected $printformerBlock;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $draftImageCreated = [];

    /**
     * @var Media
     */
    protected $mediaHelper;

    /**
     * @var UrlHelper
     */
    protected $urlHelper;

    /**
     * @var array
     */
    protected $printformerDraft = [];

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * Gallery constructor.
     * @param ConfigHelper $config
     * @param Media $mediaHelper
     * @param UrlHelper $urlHelper
     * @param PrintformerApi $printformerApi
     * @param PrintformerBlock $printformerBlock
     * @param ManagerInterface $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        ConfigHelper $config,
        Media $mediaHelper,
        UrlHelper $urlHelper,
        PrintformerApi $printformerApi,
        PrintformerBlock $printformerBlock,
        ManagerInterface $eventManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->mediaHelper = $mediaHelper;
        $this->urlHelper = $urlHelper;
        $this->printformerApi = $printformerApi;
        $this->printformerBlock = $printformerBlock;
        $this->eventManager = $eventManager;
        $this->logger = $logger;
    }

    /**
     * If printformer images have been loaded, check if one of them is the main image
     * @param SubjectGallery $gallery
     * @param \Closure $proceed
     * @param \Magento\Framework\DataObject $image
     * @return bool
     */
    public function aroundIsMainImage(SubjectGallery $gallery, \Closure $proceed, $image)
    {
        if(count($this->draftImageCreated) > 0) {
            return $image->getIsMainImage();
        }
        return $proceed($image);
    }

    /**
     * @param SubjectGallery $gallery
     * @param \Magento\Framework\Data\Collection $result
     * @return \Magento\Framework\Data\Collection mixed
     */
    public function afterGetGalleryImages(SubjectGallery $gallery, $result)
    {
        $draftIds = $this->getDraftIds();
        $j = 0;
        foreach($draftIds as $draftId) {
            if ($this->getImagePreviewUrl(1, $draftId)) {
                if ($this->config->isV2Enabled()) {
                    $printformerDraft = $this->getPrintformerDraft($draftId);
                    $pages = isset($printformerDraft['pages']) ? $printformerDraft['pages'] : 1;

                    for ($i = 0; $i < $pages; $i++) {
                        try {
                            $result->addItem(new \Magento\Framework\DataObject([
                                'id' => $i + $j,
                                'small_image_url' => $this->getImagePreviewUrl(($i + 1), $draftId),
                                'medium_image_url' => $this->getImagePreviewUrl(($i + 1), $draftId),
                                'large_image_url' => $this->getImagePreviewUrl(($i + 1), $draftId),
                                'is_main_image' => ($i + $j == 0)
                            ]));
                        } catch (\Exception $e) {
                            $this->logger->error($e->getMessage());
                            $this->logger->error($e->getTraceAsString());
                        }
                    }
                } else {
                    try {
                        $result->addItem(new \Magento\Framework\DataObject([
                            'id' => 0,
                            'small_image_url' => $this->getImagePreviewUrl(1, $draftId),
                            'medium_image_url' => $this->getImagePreviewUrl(1, $draftId),
                            'large_image_url' => $this->getImagePreviewUrl(1, $draftId),
                            'is_main_image' => true
                        ]));
                    } catch (\Exception $e) {
                        $this->logger->error($e->getMessage());
                        $this->logger->error($e->getTraceAsString());
                    }
                }
            }
            $j += 100;
        }
        return $result;
    }

    /**
     * @return array
     */
    private function getDraftIds()
    {
        $draftIds = [];
        $catalogProductPrintformerProducts = $this->printformerBlock->getCatalogProductPrintformerProducts();
        foreach($catalogProductPrintformerProducts as $catalogProductPrintformerProduct) {
            $draftIds[] = $this->printformerBlock->getDraftId($catalogProductPrintformerProduct->getPrintformerProduct());
        }
        return $draftIds;
    }

    /**
     * @param string $draftId
     * @return array
     */
    private function getPrintformerDraft($draftId)
    {
        if(!isset($this->printformerDraft[$draftId])) {
            $this->printformerDraft[$draftId] = $this->printformerApi->getPrintformerDraft($draftId);
        }
        return $this->printformerDraft[$draftId];
    }

    /**
     * @param int $page
     * @param string $draftId
     * @return null|string
     */
    private function getImagePreviewUrl($page = 1, $draftId)
    {
        $url = null;
        if ($this->config->isUseImagePreview() && $draftId) {
            if($this->config->isV2Enabled()) {
                try {
                    if (!isset($this->draftImageCreated[$draftId.$page])) {
                        $jpgImg = $this->printformerApi->getThumbnail(
                            $draftId,
                            $this->printformerApi->getUserIdentifier(),
                            $this->config->getImagePreviewWidth(),
                            $this->config->getImagePreviewHeight(),
                            $page
                        );

                        $printformerImage = $jpgImg['content'];

                        $imageFilePath = $this->mediaHelper->getImageFilePath($draftId, $page);

                        $image = imagecreatefromstring($printformerImage);

                        $width = imagesx($image);
                        $height = imagesy($image);

                        $out = imagecreatetruecolor($width, $height);
                        imagealphablending($out, false);
                        $transparentindex = imagecolorallocatealpha($out, 0, 0, 0, 127);
                        imagefill($out, 0, 0, $transparentindex);
                        imagesavealpha($out, true);

                        imagecopyresized($out, $image, 0, 0, 0, 0, $width, $height, $width, $height);
                        imagepng($out, $imageFilePath);

                        $this->eventManager->dispatch('printformer_image_preview_create', [
                            'printformer_image' => $printformerImage,
                            'original_image' => $image,
                            'width' => $width,
                            'height' => $height,
                            'final_image' => $out,
                            'image_path' => $imageFilePath
                        ]);

                        imagedestroy($image);

                        $this->draftImageCreated[$draftId.$page] = true;
                    }

                    $url = $this->mediaHelper->getImageUrl($draftId, $page);
                } catch (\Exception $e) {
                    $this->logger->error($e->getMessage());
                    $this->logger->error($e->getTraceAsString());
                }
            } else {
                $url = $this->urlHelper->getThumbnail($draftId);
            }
        }
        return $url;
    }
}