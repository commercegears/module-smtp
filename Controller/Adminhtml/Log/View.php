<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Log;

use CommerceGears\Smtp\Model\LogFactory;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::log';

    public function __construct(
        Context $context,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $log = $this->logFactory->create();
        $this->logResource->load($log, (int)$this->getRequest()->getParam('id'));
        if (!$log->getId()) {
            $this->messageManager->addErrorMessage(__('This email no longer exists in the log.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('CommerceGears_Smtp::log');
        $page->getConfig()->getTitle()->prepend(__('Email #%1', $log->getId()));
        return $page;
    }
}
