<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Log;

use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\Mailer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Sends one logged email again now, optionally to another address.
 */
class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::log_manage';

    public function __construct(
        Context $context,
        private readonly Mailer $mailer
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $recipient = trim((string)$this->getRequest()->getParam('recipient'));
        if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('"%1" is not a valid email address.', $recipient));
            return $redirect->setPath('*/*/view', ['id' => $id]);
        }
        try {
            $log = $this->mailer->resend($id, $recipient !== '' ? $recipient : null);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/view', ['id' => $id]);
        }
        $status = (string)$log->getData('status');
        if ($status === Log::STATUS_SENT) {
            $this->messageManager->addSuccessMessage(__('The email was sent again to %1.', (string)$log->getData('recipient_to')));
        } elseif ($status === Log::STATUS_SKIPPED) {
            $this->messageManager->addWarningMessage(__('The email was not sent: %1', (string)$log->getData('note')));
        } else {
            $this->messageManager->addErrorMessage(__('The email could not be sent: %1', (string)$log->getData('error_message')));
        }
        return $redirect->setPath('*/*/view', ['id' => $log->getId()]);
    }
}
