<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use CommerceGears\Smtp\Model\Delivery\PreparedEmail;
use CommerceGears\Smtp\Model\Delivery\Preparer;
use CommerceGears\Smtp\Model\Message\OutgoingEmail;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use CommerceGears\Smtp\Model\Smtp\ErrorExplainer;
use CommerceGears\Smtp\Model\Smtp\Sender;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use CommerceGears\Smtp\Model\Smtp\TransportFailure;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Sends, queues, retries and logs emails.
 */
class Mailer
{
    /** Minutes to wait before attempt 2, 3, 4, ... */
    private const BACKOFF_MINUTES = [5, 15, 60, 240, 720];

    public function __construct(
        private readonly Config $config,
        private readonly Preparer $preparer,
        private readonly Sender $sender,
        private readonly ErrorExplainer $explainer,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handles an email Magento wants to send now.
     *
     * @throws MailException when the email could not be sent and will not be retried
     */
    public function handle(OutgoingEmail $email, Scope $scope, ?int $storeId): void
    {
        $prepared = $this->preparer->prepare($email, $scope);
        $logEnabled = $this->config->isLogEnabled();

        if (!$prepared->send) {
            if ($logEnabled) {
                $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SKIPPED);
                $this->storeContentIfKept($log, $email);
            }
            return;
        }

        if ($this->config->getSendingMode($scope) === Config::SENDING_QUEUE) {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_PENDING);
            $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
            return;
        }

        try {
            $result = $this->sender->send($scope, $prepared->envelopeFrom, $prepared->getRecipients(), $prepared->data);
        } catch (SmtpException $e) {
            $this->handleFailure($e, $email, $prepared, $storeId, $scope, $logEnabled);
            return;
        }
        if ($logEnabled) {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SENT, 1);
            $this->markSent($log, $result);
            $this->storeContentIfKept($log, $email);
        }
    }

    /**
     * Sends a logged email again, right away. Returns the new log entry.
     *
     * @throws LocalizedException
     */
    public function resend(int $logId, ?string $recipient = null): Log
    {
        [$original, $email] = $this->loadForResend($logId);
        $storeId = $original->getData('store_id') !== null ? (int)$original->getData('store_id') : null;
        $scope = Scope::store((int)$storeId);
        $prepared = $this->preparer->prepare($email, $scope, $recipient, true);
        if (!$prepared->send) {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SKIPPED, 0, $logId);
            $this->storeContentIfKept($log, $email);
            return $log;
        }
        $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SENDING, 1, $logId);
        $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
        try {
            $result = $this->sender->send($scope, $prepared->envelopeFrom, $prepared->getRecipients(), $prepared->data);
            $this->markSent($log, $result);
            if (!$this->config->isKeepContent()) {
                $this->logResource->deleteContent((int)$log->getId());
            }
        } catch (SmtpException $e) {
            $settings = $this->safeSettings($scope);
            $this->markFailed($log, $e, Log::STATUS_FAILED, $settings);
        }
        return $log;
    }

    /**
     * Puts a logged email in the queue to be sent again by cron. Returns the new log ID.
     *
     * @throws LocalizedException
     */
    public function queueResend(int $logId): int
    {
        [$original, $email] = $this->loadForResend($logId);
        $log = $this->logFactory->create();
        $log->addData([
            'store_id' => $original->getData('store_id'),
            'status' => Log::STATUS_PENDING,
            'template_id' => $original->getData('template_id'),
            'subject' => $original->getData('subject'),
            'sender' => $original->getData('sender'),
            'recipient_to' => implode(', ', $email->to),
            'recipient_cc' => implode(', ', $email->cc),
            'recipient_bcc' => implode(', ', $email->bcc),
            'size' => strlen($email->raw),
            'resent_from' => $logId,
            'note' => (string)__('Queued to be sent again.'),
        ]);
        $this->logResource->save($log);
        $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope() + ['resend' => true], $email->raw);
        return (int)$log->getId();
    }

    /**
     * Sends queued emails and retries that are due.
     *
     * @return array{sent: int, retry: int, failed: int, skipped: int}
     */
    public function processQueue(int $limit = 100): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
        $this->logResource->releaseStale($this->dateTime->gmtDate('Y-m-d H:i:s', time() - 900));
        foreach ($this->logResource->getDueIds($this->dateTime->gmtDate(), $limit) as $logId) {
            if (!$this->logResource->claim($logId)) {
                continue;
            }
            $status = $this->processOne($logId);
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }
        $this->sender->closeAll();
        return $stats;
    }

    private function processOne(int $logId): string
    {
        $log = $this->logFactory->create();
        $this->logResource->load($log, $logId);
        $content = $this->logResource->loadContent($logId);
        if ($content === null) {
            $log->addData([
                'status' => Log::STATUS_FAILED,
                'error_message' => (string)__('The email content is missing, so it cannot be sent.'),
            ]);
            $this->logResource->save($log);
            return 'failed';
        }
        $storeId = $log->getData('store_id') !== null ? (int)$log->getData('store_id') : null;
        $scope = Scope::store((int)$storeId);
        $email = OutgoingEmail::fromStored($content['raw'], $content['envelope']);
        $prepared = $this->preparer->prepare($email, $scope, null, !empty($content['envelope']['resend']));
        $attempts = (int)$log->getData('attempts') + 1;
        $this->applyPrepared($log, $prepared);

        if (!$prepared->send) {
            $log->setData('status', Log::STATUS_SKIPPED);
            $this->logResource->save($log);
            $this->cleanupAfterFinal($log);
            return 'skipped';
        }

        $log->setData('attempts', $attempts);
        try {
            $result = $this->sender->send($scope, $prepared->envelopeFrom, $prepared->getRecipients(), $prepared->data);
        } catch (SmtpException $e) {
            $settings = $this->safeSettings($scope);
            if ($attempts < $this->config->getMaxAttempts()) {
                $this->markFailed($log, $e, Log::STATUS_RETRY, $settings, $attempts);
                return 'retry';
            }
            $this->markFailed($log, $e, Log::STATUS_FAILED, $settings);
            $this->cleanupAfterFinal($log);
            return 'failed';
        }
        $this->markSent($log, $result);
        $this->cleanupAfterFinal($log);
        return 'sent';
    }

    /**
     * @throws MailException
     */
    private function handleFailure(
        SmtpException $e,
        OutgoingEmail $email,
        PreparedEmail $prepared,
        ?int $storeId,
        Scope $scope,
        bool $logEnabled
    ): void {
        $settings = $this->safeSettings($scope);
        $summary = $this->explainer->summarise($e, $settings);
        $this->logger->warning('CommerceGears SMTP: email could not be sent: ' . $e->getMessage());

        if ($this->config->getMaxAttempts() > 1) {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_RETRY, 1);
            $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
            $this->markFailed($log, $e, Log::STATUS_RETRY, $settings, 1);
            return;
        }
        if ($logEnabled) {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_FAILED, 1);
            $this->markFailed($log, $e, Log::STATUS_FAILED, $settings);
            $this->storeContentIfKept($log, $email);
        }
        throw new MailException(__('The email could not be sent: %1', $summary), $e);
    }

    private function createLog(
        OutgoingEmail $email,
        PreparedEmail $prepared,
        ?int $storeId,
        string $status,
        int $attempts = 0,
        ?int $resentFrom = null
    ): Log {
        $log = $this->logFactory->create();
        $log->addData([
            'store_id' => $storeId ?: null,
            'status' => $status,
            'template_id' => $email->templateId !== null ? mb_substr($email->templateId, 0, 255) : null,
            'subject' => mb_substr($email->subject, 0, 512),
            'attempts' => $attempts,
            'resent_from' => $resentFrom,
            'next_attempt_at' => $status === Log::STATUS_PENDING ? $this->dateTime->gmtDate() : null,
        ]);
        $this->applyPrepared($log, $prepared);
        $this->logResource->save($log);
        return $log;
    }

    private function applyPrepared(Log $log, PreparedEmail $prepared): void
    {
        $log->addData([
            'sender' => mb_substr($prepared->fromHeader, 0, 255),
            'recipient_to' => implode(', ', $prepared->to),
            'recipient_cc' => implode(', ', $prepared->cc),
            'recipient_bcc' => implode(', ', $prepared->bcc),
            'original_recipients' => $prepared->originalRecipients !== [] ? implode(', ', $prepared->originalRecipients) : null,
            'note' => $prepared->getNote() !== '' ? $prepared->getNote() : null,
            'message_id' => mb_substr($prepared->messageId, 0, 255),
            'size' => strlen($prepared->data),
        ]);
    }

    /**
     * @param array{reply: string, server: string} $result
     */
    private function markSent(Log $log, array $result): void
    {
        $log->addData([
            'status' => Log::STATUS_SENT,
            'sent_at' => $this->dateTime->gmtDate(),
            'smtp_reply' => mb_substr(str_replace("\n", ' ', $result['reply']), 0, 512),
            'server' => mb_substr($result['server'], 0, 255),
            'error_message' => null,
            'error_detail' => null,
            'next_attempt_at' => null,
        ]);
        $this->logResource->save($log);
    }

    private function markFailed(
        Log $log,
        SmtpException $e,
        string $status,
        ?ConnectionSettings $settings,
        int $attempt = 0
    ): void {
        $explained = $this->explainer->explain($e, $settings);
        $detail = $explained['technical'];
        if ($e instanceof TransportFailure && $e->getTranscript() !== '') {
            $detail .= "\n\n" . $e->getTranscript();
        }
        $next = null;
        if ($status === Log::STATUS_RETRY) {
            $minutes = self::BACKOFF_MINUTES[min(max(0, $attempt - 1), count(self::BACKOFF_MINUTES) - 1)];
            $next = $this->dateTime->gmtDate('Y-m-d H:i:s', time() + $minutes * 60);
        }
        $log->addData([
            'status' => $status,
            'error_message' => trim($explained['message'] . ' ' . $explained['hint']),
            'error_detail' => $detail,
            'server' => $settings !== null ? mb_substr($settings->describe(), 0, 255) : null,
            'next_attempt_at' => $next,
        ]);
        $this->logResource->save($log);
    }

    private function storeContentIfKept(Log $log, OutgoingEmail $email): void
    {
        if ($this->config->isKeepContent()) {
            $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
        }
    }

    /**
     * After the last attempt: drop the content if it is not kept, and the row if logging is off.
     */
    private function cleanupAfterFinal(Log $log): void
    {
        if (!$this->config->isLogEnabled()) {
            $this->logResource->deleteByIds([(int)$log->getId()]);
            return;
        }
        if (!$this->config->isKeepContent()) {
            $this->logResource->deleteContent((int)$log->getId());
        }
    }

    /**
     * @return array{0: Log, 1: OutgoingEmail}
     * @throws LocalizedException
     */
    private function loadForResend(int $logId): array
    {
        $original = $this->logFactory->create();
        $this->logResource->load($original, $logId);
        if (!$original->getId()) {
            throw new LocalizedException(__('This email no longer exists in the log.'));
        }
        $content = $this->logResource->loadContent($logId);
        if ($content === null) {
            throw new LocalizedException(__('The content of this email was not kept, so it cannot be sent again.'));
        }
        return [$original, OutgoingEmail::fromStored($content['raw'], $content['envelope'])];
    }

    private function safeSettings(Scope $scope): ?ConnectionSettings
    {
        try {
            return $this->config->getConnectionSettings($scope);
        } catch (\Throwable) {
            return null;
        }
    }
}
