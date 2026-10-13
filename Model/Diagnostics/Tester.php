<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Diagnostics;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\LogFactory;
use CommerceGears\Smtp\Model\Message\AddressParser;
use CommerceGears\Smtp\Model\Message\MessageExtractor;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\Smtp\Client;
use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use CommerceGears\Smtp\Model\Smtp\ErrorExplainer;
use CommerceGears\Smtp\Model\Smtp\Sender;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Check Connection" and "Send Test Email". Both use the saved settings of the scope and a new connection, and
 * return a readable result with the masked SMTP transcript. The test email goes only to the address the admin
 * enters (delivery mode and blocklist do not apply), and is written to the log.
 */
class Tester
{
    public const TEMPLATE_ID = 'commercegears_smtp_test';

    public function __construct(
        private readonly Config $config,
        private readonly Sender $sender,
        private readonly ErrorExplainer $explainer,
        private readonly AddressParser $addressParser,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function checkConnection(Scope $scope): array
    {
        $settings = null;
        $client = null;
        try {
            $settings = $this->sender->getSettings($scope);
            $client = $this->sender->createClient($settings);
            $client->connect();
            $result = $this->success($settings, $client, (string)__('Connected to %1 and signed in.', $settings->describe()));
            if ($settings->authMethod === ConnectionSettings::AUTH_NONE
                || ($settings->username === '' && $settings->password === '')) {
                $result['message'] = (string)__('Connected to %1 (no sign-in).', $settings->describe());
            }
            $client->quit();
            return $result;
        } catch (SmtpException $e) {
            $client?->close();
            return $this->failure($e, $settings ?? $this->safeSettings($scope), $client);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTest(Scope $scope, string $recipient): array
    {
        $recipient = trim($recipient);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => (string)__('Enter a valid email address for the test email.'), 'hint' => '', 'transcript' => '', 'details' => []];
        }
        $fromEmail = $this->config->getStoreSenderEmail($scope);
        $fromName = $this->config->getStoreSenderName($scope) ?: 'Magento';
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => (string)__('The store has no valid sender address (Stores > Configuration > General > Store Email Addresses).'), 'hint' => '', 'transcript' => '', 'details' => []];
        }
        $envelopeFrom = $this->config->getReturnPathEmail($scope) ?: $fromEmail;
        $raw = $this->buildMessage($fromEmail, $fromName, $recipient, $scope);
        $messageId = preg_match('/^Message-ID: (.+)$/mi', $raw, $m) === 1 ? trim($m[1]) : null;
        $subject = $this->getSubject($fromName);

        $settings = null;
        $client = null;
        $log = $this->config->isLogEnabled() ? $this->startLog($scope, $fromEmail, $recipient, $subject, strlen($raw)) : null;
        $log?->setData('message_id', $messageId);
        try {
            $settings = $this->sender->getSettings($scope);
            $client = $this->sender->createClient($settings);
            $client->connect();
            $reply = $client->send($envelopeFrom, [$recipient], $raw);
            $result = $this->success($settings, $client, (string)__('Test email sent to %1. Check the inbox (and the spam folder).', $recipient));
            $result['details'][(string)__('Server reply')] = strtok($reply, "\n") ?: $reply;
            $client->quit();
            if ($log !== null) {
                $log->addData([
                    'status' => Log::STATUS_SENT,
                    'sent_at' => $this->dateTime->gmtDate(),
                    'smtp_reply' => mb_substr(str_replace("\n", ' ', $reply), 0, 512),
                    'server' => $settings->describe(),
                ]);
                $this->logResource->save($log);
                if ($this->config->isKeepContent()) {
                    $this->logResource->saveContent((int)$log->getId(), [
                        'from' => $envelopeFrom, 'to' => [$recipient], 'cc' => [], 'bcc' => [], 'template' => self::TEMPLATE_ID,
                    ], $raw);
                }
            }
            return $result;
        } catch (SmtpException $e) {
            $client?->close();
            $settings ??= $this->safeSettings($scope);
            $result = $this->failure($e, $settings, $client);
            if ($log !== null) {
                $log->addData([
                    'status' => Log::STATUS_FAILED,
                    'error_message' => trim($result['message'] . ' ' . $result['hint']),
                    'error_detail' => $result['technical'] . ($result['transcript'] !== '' ? "\n\n" . $result['transcript'] : ''),
                    'server' => $settings?->describe(),
                ]);
                $this->logResource->save($log);
            }
            return $result;
        }
    }

    private function getSubject(string $storeName): string
    {
        return (string)__('Test email from %1', $storeName);
    }

    private function buildMessage(string $fromEmail, string $fromName, string $recipient, Scope $scope): string
    {
        $settings = $this->config->getConnectionSettings($scope);
        $preset = $this->config->getPreset($scope);
        $time = gmdate('Y-m-d H:i') . ' UTC';
        $text = (string)__('This is a test email from your Magento store, sent with CommerceGears SMTP.') . "\n\n"
            . (string)__('Provider: %1', $preset->label) . "\n"
            . (string)__('Server: %1', $settings->describe()) . "\n"
            . (string)__('Sent: %1', $time) . "\n\n"
            . (string)__('If you can read this, your store can send emails.');
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#0f172a;line-height:1.5">'
            . '<h2 style="color:#0f766e;margin:0 0 12px">' . htmlspecialchars((string)__('It works!'), ENT_QUOTES) . '</h2>'
            . '<p>' . htmlspecialchars((string)__('This is a test email from your Magento store, sent with CommerceGears SMTP.'), ENT_QUOTES) . '</p>'
            . '<table style="border-collapse:collapse;font-size:14px">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">' . htmlspecialchars((string)__('Provider'), ENT_QUOTES) . '</td><td>' . htmlspecialchars($preset->label, ENT_QUOTES) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">' . htmlspecialchars((string)__('Server'), ENT_QUOTES) . '</td><td>' . htmlspecialchars($settings->describe(), ENT_QUOTES) . '</td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#475569">' . htmlspecialchars((string)__('Sent'), ENT_QUOTES) . '</td><td>' . htmlspecialchars($time, ENT_QUOTES) . '</td></tr>'
            . '</table><p>' . htmlspecialchars((string)__('If you can read this, your store can send emails.'), ENT_QUOTES) . '</p></body></html>';

        $boundary = '=_cg_' . bin2hex(random_bytes(12));
        $subject = $this->getSubject($fromName);
        $encodedSubject = preg_match('/[\x80-\xFF]/', $subject) === 1
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n", 9)
            : $subject;
        $headers = [
            'From: ' . $this->addressParser->format([['email' => $fromEmail, 'name' => $fromName]]),
            'To: ' . $recipient,
            'Subject: ' . $encodedSubject,
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . MessageExtractor::newMessageId($fromEmail, $this->config->getHeloName($scope)),
            'MIME-Version: 1.0',
            'X-CommerceGears-Test: 1',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace("\n", "\r\n", $text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n"
            . '--' . $boundary . "--\r\n";
    }

    private function startLog(Scope $scope, string $from, string $to, string $subject, int $size): Log
    {
        $log = $this->logFactory->create();
        $log->addData([
            'store_id' => $scope->getStoreId(),
            'status' => Log::STATUS_SENDING,
            'template_id' => self::TEMPLATE_ID,
            'subject' => mb_substr($subject, 0, 512),
            'sender' => $from,
            'recipient_to' => $to,
            'attempts' => 1,
            'size' => $size,
            'note' => (string)__('Test email from the admin (%1).', $scope->toString()),
        ]);
        $this->logResource->save($log);
        return $log;
    }

    /**
     * @return array<string, mixed>
     */
    private function success(ConnectionSettings $settings, Client $client, string $message): array
    {
        $warnings = [];
        if (!$client->isEncrypted()) {
            $warnings[] = (string)__('The connection is not encrypted. Use STARTTLS or SSL/TLS unless the server is on your own network.');
        }
        if (!$settings->verifyTls && $client->isEncrypted()) {
            $warnings[] = (string)__('The server certificate is not verified.');
        }
        $capabilities = array_keys($client->getCapabilities());
        return [
            'success' => true,
            'message' => $message,
            'hint' => '',
            'warnings' => $warnings,
            'details' => [
                (string)__('Server') => $settings->describe(),
                (string)__('Encryption') => $client->isEncrypted() ? 'TLS' : (string)__('None'),
                (string)__('Sign-in') => $client->getAuthMechanism() !== '' ? $client->getAuthMechanism() : (string)__('None'),
                (string)__('Server extensions') => implode(', ', $capabilities),
            ],
            'technical' => '',
            'transcript' => $client->getTranscript(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(SmtpException $e, ?ConnectionSettings $settings, ?Client $client): array
    {
        $explained = $this->explainer->explain($e, $settings);
        return [
            'success' => false,
            'message' => $explained['message'],
            'hint' => $explained['hint'],
            'warnings' => [],
            'details' => $settings !== null ? [(string)__('Server') => $settings->describe()] : [],
            'technical' => $explained['technical'],
            'transcript' => $client?->getTranscript() ?? '',
        ];
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
