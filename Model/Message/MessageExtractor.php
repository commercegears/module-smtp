<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Message;

use Magento\Framework\Mail\Address;

/**
 * Reads a Magento mail message into an OutgoingEmail.
 *
 * Magento 2.4.8+ (Symfony Mime) leaves Bcc out of the serialised message, so Bcc is taken from the message object;
 * 2.4.6/2.4.7 (Laminas) keeps the Bcc header in the raw text, so it is read and then removed there.
 */
class MessageExtractor
{
    public function __construct(
        private readonly AddressParser $addressParser
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the message cannot be read
     */
    public function extract(object $message, ?string $templateId = null, string $domain = 'localhost'): OutgoingEmail
    {
        if (method_exists($message, 'toString')) {
            $raw = (string)$message->toString();
        } elseif (method_exists($message, 'getRawMessage')) {
            $raw = (string)$message->getRawMessage();
        } else {
            throw new \InvalidArgumentException('Unsupported message type ' . get_class($message));
        }
        if (trim($raw) === '') {
            throw new \InvalidArgumentException('The message is empty');
        }

        $mime = new MimeMessage($raw);
        $to = $this->fromObject($message, 'getTo') ?? $this->addressParser->parseEmails((string)$mime->get('To'));
        $cc = $this->fromObject($message, 'getCc') ?? $this->addressParser->parseEmails((string)$mime->get('Cc'));
        $bcc = $this->fromObject($message, 'getBcc') ?? [];
        $bcc = array_values(array_unique(array_merge($bcc, $this->addressParser->parseEmails((string)$mime->get('Bcc')))));
        $mime->remove('Bcc');

        $sender = $this->addressParser->parseEmails((string)$mime->get('Sender'));
        $from = $this->addressParser->parseEmails((string)$mime->get('From'));
        $envelopeFrom = $sender[0] ?? $from[0] ?? '';

        if (!$mime->has('Date')) {
            $mime->set('Date', date(DATE_RFC2822));
        }
        if (!$mime->has('Message-ID')) {
            $mime->set('Message-ID', self::newMessageId($envelopeFrom, $domain));
        }
        if (!$mime->has('MIME-Version')) {
            $mime->set('MIME-Version', '1.0');
        }

        return new OutgoingEmail(
            $mime->toString(),
            $envelopeFrom,
            $to,
            $cc,
            $bcc,
            $mime->getDecoded('Subject'),
            $templateId
        );
    }

    public static function newMessageId(string $from, string $fallbackDomain): string
    {
        $domain = str_contains($from, '@') ? substr($from, (int)strrpos($from, '@') + 1) : $fallbackDomain;
        $domain = preg_replace('/[^A-Za-z0-9.-]/', '', $domain) ?: 'localhost';
        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }

    /**
     * @return string[]|null null when the object does not offer the method
     */
    private function fromObject(object $message, string $method): ?array
    {
        if (!method_exists($message, $method)) {
            return null;
        }
        try {
            $list = $message->{$method}();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($list)) {
            return [];
        }
        $emails = [];
        foreach ($list as $address) {
            if ($address instanceof Address) {
                $emails[] = (string)$address->getEmail();
            } elseif (is_object($address) && method_exists($address, 'getAddress')) {
                $emails[] = (string)$address->getAddress();
            } elseif (is_string($address)) {
                $emails[] = $address;
            }
        }
        return array_values(array_unique(array_filter($emails)));
    }
}
