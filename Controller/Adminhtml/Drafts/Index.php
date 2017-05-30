<?php

namespace Rissc\Printformer\Controller\Adminhtml\Drafts;

use Rissc\Printformer\Controller\Adminhtml\AbstractController;

/**
 * Class Index
 * @package Rissc\Printformer\Controller\Adminhtml\Drafts
 */
class Index
    extends AbstractController
{
    /**
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        if ($this->getRequest()->getQuery('ajax')) {
            $this->_forward('grid');
            return null;
        }

        /** @var \Magento\Backend\Model\View\Result\Page $resultPage */
        $resultPage = $this->_resultPageFactory->create();
        $resultPage->setActiveMenu('Rissc_Printformer::main_menu');
        $resultPage->getConfig()->getTitle()->prepend(__('Printformer Drafts'));

        return $resultPage;
    }
}