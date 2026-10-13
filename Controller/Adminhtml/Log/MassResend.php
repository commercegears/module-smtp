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

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $queued = 0;
        $skipped = 0;
        foreach (array_slice($collection->getAllIds(), 0, self::MAX) as $id) {
            try {
                $this->mailer->queueResend((int)$id);
                $queued++;
            } catch (LocalizedException) {
                $skipped++;
            }
        }
        if ($queued > 0) {
            $this->messageManager->addSuccessMessage(__('%1 email(s) were queued and will be sent again by cron within a few minutes.', $queued));
        }
        if ($skipped > 0) {
            $this->messageManager->addWarningMessage(__('%1 email(s) were skipped because their content was not kept.', $skipped));
        }
        if ($collection->getSize() > self::MAX) {
            $this->messageManager->addNoticeMessage(__('Only the first %1 selected emails were queued.', self::MAX));
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
