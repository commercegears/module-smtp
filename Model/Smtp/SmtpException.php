<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

/**
 * A failed SMTP step. Keeps the stage, the server's reply code and text so that a readable explanation can be built.
 */
class SmtpException extends \RuntimeException
{
    public const STAGE_CONNECT = 'connect';
    public const STAGE_GREETING = 'greeting';
    public const STAGE_EHLO = 'ehlo';
    public const STAGE_TLS = 'tls';
    public const STAGE_AUTH = 'auth';
    public const STAGE_TOKEN = 'token';
    public const STAGE_MAIL_FROM = 'mail_from';
    public const STAGE_RCPT = 'rcpt';
    public const STAGE_DATA = 'data';
    public const STAGE_IO = 'io';
    public const STAGE_MESSAGE = 'message';

    public function __construct(
        string $message,
        private readonly string $stage,
        private readonly int $replyCode = 0,
        private readonly string $replyText = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $replyCode, $previous);
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function getReplyCode(): int
    {
        return $this->replyCode;
    }

    public function getReplyText(): string
    {
        return $this->replyText;
    }

    /**
     * 4xx replies, timeouts and network problems may go away by themselves; 5xx replies and sign-in errors do not.
     */
    public function isTemporary(): bool
    {
        if ($this->replyCode >= 400 && $this->replyCode < 500) {
            return true;
        }
        if ($this->replyCode >= 500) {
            return false;
        }
        return in_array($this->stage, [self::STAGE_CONNECT, self::STAGE_GREETING, self::STAGE_IO, self::STAGE_TOKEN], true);
    }
}
