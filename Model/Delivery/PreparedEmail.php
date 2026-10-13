<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Delivery;

/**
 * The email as it will really be sent (after delivery mode, blocklist and sender rules), or the reason it is not.
 */
class PreparedEmail
{
    /**
     * @param string[] $to
     * @param string[] $cc
     * @param string[] $bcc
     * @param string[] $originalRecipients set when the recipients were changed
     * @param string[] $notes
     */
    public function __construct(
        public readonly bool $send,
        public readonly string $data,
        public readonly string $envelopeFrom,
        public readonly string $fromHeader,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly array $originalRecipients,
        public readonly array $notes,
        public readonly string $messageId
    ) {
    }

    /**
     * @return string[]
     */
    public function getRecipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc, $this->bcc)));
    }

    public function getNote(): string
    {
        return implode(' ', $this->notes);
    }
}
