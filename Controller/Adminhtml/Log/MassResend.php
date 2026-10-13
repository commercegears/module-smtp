<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Log;

use CommerceGears\Smtp\Model\Mailer;
use CommerceGears\Smtp\Model\ResourceModel\Log\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Queues the selected emails to be sent again by cron (so a large selection never blocks the admin).
 */
class MassResend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::log_manage';
    private const MAX = 500;

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly Mailer $mailer
    ) {
        parent::__construct($context);
    }

    /**
     * Sending again reveals the content (to another address), so it also needs "View Email Content".
     */
    protected function _isAllowed()
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('CommerceGears_Smtp::log_content');
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $queued = 0;
        /** @var array<string, int> $skipped reason => count */
        $skipped = [];
        foreach (array_slice($collection->getAllIds(), 0, self::MAX) as $id) {
            try {
                $this->mailer->queueResend((int)$id);
                $queued++;
            } catch (LocalizedException $e) {
                $reason = $e->getMessage();
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
            }
        }
        if ($queued > 0) {
            $this->messageManager->addSuccessMessage(__('%1 email(s) were queued and will be sent again by cron within a few minutes.', $queued));
        }
        foreach ($skipped as $reason => $count) {
            $this->messageManager->addWarningMessage(__('%1 email(s) were skipped: %2', $count, $reason));
        }
        if ($collection->getSize() > self::MAX) {
            $this->messageManager->addNoticeMessage(__('Only the first %1 selected emails were queued.', self::MAX));
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
