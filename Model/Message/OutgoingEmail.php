<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Message;

/**
 * An email as Magento handed it over: the raw MIME message (without a Bcc header) and its recipients by type.
 */
class OutgoingEmail
{
    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     */
    public function __construct(
        public readonly string $raw,
        public readonly string $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly string $subject,
        public readonly ?string $templateId = null
    ) {
    }

    /**
     * @return string[]
     */
    public function getAllRecipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc, $this->bcc)));
    }

    /**
     * @return array{from: string, to: string[], cc: string[], bcc: string[], template: ?string}
     */
    public function getEnvelope(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'cc' => $this->cc, 'bcc' => $this->bcc, 'template' => $this->templateId];
    }

    /**
     * Rebuilds an email from the raw message and envelope kept in the log.
     *
     * @param array<string, mixed> $envelope
     */
    public static function fromStored(string $raw, array $envelope): self
    {
        $list = static fn (mixed $value): array => is_array($value)
            ? array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''))
            : [];
        $message = new MimeMessage($raw);
        return new self(
            $raw,
            (string)($envelope['from'] ?? ''),
            $list($envelope['to'] ?? []),
            $list($envelope['cc'] ?? []),
            $list($envelope['bcc'] ?? []),
            $message->getDecoded('Subject'),
            isset($envelope['template']) && is_string($envelope['template']) ? $envelope['template'] : null
        );
    }
}
