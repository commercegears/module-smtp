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
use Magento\Store\Model\StoreManagerInterface;
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
        private readonly LoggerInterface $logger,
        private readonly ResendGuard $resendGuard,
        private readonly StoreManagerInterface $storeManager
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
                $this->safely('the email could not be logged', function () use ($email, $prepared, $storeId): void {
                    $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SKIPPED);
                    $this->storeContentIfKept($log, $email);
                });
            }
            return;
        }

        if ($this->config->getSendingMode($scope) === Config::SENDING_QUEUE && $this->enqueue($email, $prepared, $storeId)) {
            return;
        }

        try {
            $result = $this->sender->send($scope, $prepared->envelopeFrom, $prepared->getRecipients(), $prepared->data);
        } catch (SmtpException $e) {
            $this->handleFailure($e, $email, $prepared, $storeId, $scope, $logEnabled);
            return;
        }
        if ($logEnabled) {
            // The email is out: a logging problem must never make Magento think it failed (or send it twice).
            $this->safely('the sent email could not be logged', function () use ($email, $prepared, $storeId, $result): void {
                $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_SENT, 1);
                $this->markSent($log, $result);
                $this->storeContentIfKept($log, $email);
            });
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
        } catch (\Throwable $e) {
            $this->markFailed($log, self::toSmtpException($e), Log::STATUS_FAILED, $this->safeSettings($scope));
            return $log;
        }
        $this->markSentSafely($log, $result, !$this->config->isKeepContent() ? fn () => $this->logResource->deleteContent((int)$log->getId()) : null);
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
            try {
                $status = $this->processOne($logId);
            } catch (\Throwable $e) {
                $status = $this->markBroken($logId, $e);
            }
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
        if ($storeId !== null && $storeId > 0 && !$this->storeExists($storeId)) {
            $log->addData([
                'status' => Log::STATUS_FAILED,
                'error_message' => (string)__('The store view of this email no longer exists, so it cannot be sent.'),
                'next_attempt_at' => null,
            ]);
            $this->logResource->save($log);
            $this->cleanupAfterFinal($log);
            return 'failed';
        }
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
        } catch (\Throwable $e) {
            $error = self::toSmtpException($e);
            $settings = $this->safeSettings($scope);
            if ($attempts < $this->config->getMaxAttempts()) {
                $this->markFailed($log, $error, Log::STATUS_RETRY, $settings, $attempts);
                return 'retry';
            }
            $this->markFailed($log, $error, Log::STATUS_FAILED, $settings);
            $this->cleanupAfterFinal($log);
            return 'failed';
        }
        $this->markSentSafely($log, $result, fn () => $this->cleanupAfterFinal($log));
        return 'sent';
    }

    /**
     * After a successful send: the row must never be picked up by the queue again (that would send it twice), even
     * if the full update fails.
     *
     * @param array{reply: string, server: string, rejected?: array<string, string>} $result
     */
    private function markSentSafely(Log $log, array $result, ?callable $then = null): void
    {
        try {
            $this->markSent($log, $result);
            if ($then !== null) {
                $then();
            }
        } catch (\Throwable $e) {
            $this->logger->error('CommerceGears SMTP: the sent email could not be logged: ' . $e->getMessage());
            $this->safely('the sent email could not be marked as sent', fn () => $this->logResource->getConnection()->update(
                $this->logResource->getMainTable(),
                ['status' => Log::STATUS_SENT, 'next_attempt_at' => null],
                ['log_id = ?' => (int)$log->getId()]
            ));
        }
    }

    /**
     * A queued email whose processing failed with an unexpected error (not an SMTP error): retried with the usual
     * back-off, then marked failed, instead of being left in "sending" for ever.
     */
    private function markBroken(int $logId, \Throwable $e): string
    {
        $this->logger->error('CommerceGears SMTP: queued email ' . $logId . ' could not be processed: ' . $e->getMessage());
        try {
            $connection = $this->logResource->getConnection();
            $attempts = (int)$connection->fetchOne(
                $connection->select()->from($this->logResource->getMainTable(), ['attempts'])->where('log_id = ?', $logId)
            ) + 1;
            $retry = $attempts < $this->config->getMaxAttempts();
            $minutes = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
            $connection->update(
                $this->logResource->getMainTable(),
                [
                    'status' => $retry ? Log::STATUS_RETRY : Log::STATUS_FAILED,
                    'attempts' => $attempts,
                    'next_attempt_at' => $retry ? $this->dateTime->gmtDate('Y-m-d H:i:s', time() + $minutes * 60) : null,
                    'error_message' => (string)__('The email could not be processed: %1', mb_substr($e->getMessage(), 0, 500)),
                ],
                ['log_id = ?' => $logId]
            );
            return $retry ? 'retry' : 'failed';
        } catch (\Throwable $inner) {
            $this->logger->error('CommerceGears SMTP: queued email ' . $logId . ' could not be updated: ' . $inner->getMessage());
            return 'failed';
        }
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
            $log = null;
            try {
                $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_RETRY, 1);
                $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
                $this->markFailed($log, $e, Log::STATUS_RETRY, $settings, 1);
                return;
            } catch (\Throwable $saveError) {
                // Cannot be kept for a retry: tell Magento it failed instead of losing it silently.
                $this->logger->error('CommerceGears SMTP: the email could not be queued for a retry: ' . $saveError->getMessage());
                if ($log !== null && $log->getId()) {
                    $this->safely('the log entry could not be removed', fn () => $this->logResource->deleteByIds([(int)$log->getId()]));
                }
            }
        } elseif ($logEnabled) {
            $this->safely('the failed email could not be logged', function () use ($email, $prepared, $storeId, $e, $settings): void {
                $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_FAILED, 1);
                $this->markFailed($log, $e, Log::STATUS_FAILED, $settings);
                $this->storeContentIfKept($log, $email);
            });
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
     * Puts an email in the queue for cron. False when it cannot be stored, so it is sent right away instead.
     */
    private function enqueue(OutgoingEmail $email, PreparedEmail $prepared, ?int $storeId): bool
    {
        $log = null;
        try {
            $log = $this->createLog($email, $prepared, $storeId, Log::STATUS_PENDING);
            $this->logResource->saveContent((int)$log->getId(), $email->getEnvelope(), $email->raw);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('CommerceGears SMTP: the email could not be queued and is sent now: ' . $e->getMessage());
            if ($log !== null && $log->getId()) {
                $this->safely('the log entry could not be removed', fn () => $this->logResource->deleteByIds([(int)$log->getId()]));
            }
            return false;
        }
    }

    /**
     * @param array{reply: string, server: string, rejected?: array<string, string>} $result
     */
    private function markSent(Log $log, array $result): void
    {
        $rejected = $result['rejected'] ?? [];
        if ($rejected !== []) {
            $parts = [];
            foreach ($rejected as $address => $reply) {
                $parts[] = $address . ($reply !== '' ? ' (' . $reply . ')' : '');
            }
            $note = trim((string)$log->getData('note') . ' ' . (string)__('Refused by the server and left out: %1.', implode(', ', $parts)));
            $log->setData('note', mb_substr($note, 0, 60000));
        }
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
        $blocker = $this->resendGuard->getBlocker($original->getData('store_id'));
        if ($blocker !== null) {
            throw new LocalizedException(__('%1', $blocker));
        }
        $content = $this->logResource->loadContent($logId);
        if ($content === null) {
            throw new LocalizedException(__('The content of this email was not kept, so it cannot be sent again.'));
        }
        return [$original, OutgoingEmail::fromStored($content['raw'], $content['envelope'])];
    }

    /**
     * Runs a bookkeeping step whose failure must not change what happens to the email.
     */
    private function safely(string $what, callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            $this->logger->error('CommerceGears SMTP: ' . $what . ': ' . $e->getMessage());
        }
    }

    private static function toSmtpException(\Throwable $e): SmtpException
    {
        return $e instanceof SmtpException ? $e : new SmtpException($e->getMessage(), SmtpException::STAGE_MESSAGE, 0, '', $e);
    }

    private function storeExists(int $storeId): bool
    {
        try {
            $this->storeManager->getStore($storeId);
            return true;
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return false;
        }
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
