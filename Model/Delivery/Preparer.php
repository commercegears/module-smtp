<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Delivery;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Message\AddressParser;
use CommerceGears\Smtp\Model\Message\MessageExtractor;
use CommerceGears\Smtp\Model\Message\MimeMessage;
use CommerceGears\Smtp\Model\Message\OutgoingEmail;
use CommerceGears\Smtp\Model\Scope;

/**
 * Applies the delivery rules of a scope to an email right before it is sent: Magento's "Disable Email
 * Communications", the recipient blocklist, the delivery mode (live, test addresses, log only), the staging safety
 * lock, the sender override and the envelope sender. The rules are applied at send time, so queued, retried and
 * resent emails follow the settings that are in force then.
 */
class Preparer
{
    public const ORIGINAL_HEADER = 'X-CommerceGears-Original-To';

    public function __construct(
        private readonly Config $config,
        private readonly AddressParser $addressParser
    ) {
    }

    public function prepare(OutgoingEmail $email, Scope $scope, ?string $overrideRecipient = null, bool $resend = false): PreparedEmail
    {
        $mime = new MimeMessage($email->raw);
        $notes = [];
        $to = $email->to;
        $cc = $email->cc;
        $bcc = $email->bcc;
        $original = $email->getAllRecipients();
        $changed = false;

        if ($this->config->isMagentoMailDisabled($scope)) {
            return $this->skip($mime, $email, (string)__('Not sent: "Disable Email Communications" is on in Stores > Configuration > Advanced > System > Mail Sending Settings.'));
        }

        if ($overrideRecipient !== null && $overrideRecipient !== '') {
            $to = [$overrideRecipient];
            $cc = [];
            $bcc = [];
            $changed = true;
            $notes[] = (string)__('Sent again to %1.', $overrideRecipient);
        }

        $blocklist = $this->config->getBlockedRecipients($scope);
        if ($blocklist !== []) {
            $blocked = [];
            $lists = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
            foreach ($lists as $type => $addresses) {
                $lists[$type] = [];
                foreach ($addresses as $address) {
                    if (self::matchesPattern($address, $blocklist)) {
                        $blocked[] = $address;
                    } else {
                        $lists[$type][] = $address;
                    }
                }
            }
            ['to' => $to, 'cc' => $cc, 'bcc' => $bcc] = $lists;
            if ($blocked !== []) {
                $changed = true;
                $notes[] = (string)__('Blocked recipients left out: %1.', implode(', ', array_unique($blocked)));
            }
            if ($to === [] && $cc === [] && $bcc === []) {
                return $this->skip($mime, $email, (string)__('Not sent: every recipient is on the "Never Send To" list.'));
            }
        }

        $mode = $this->config->getDeliveryMode($scope);
        $testRecipients = $this->config->getTestRecipients($scope);
        $liveHosts = $this->config->getLiveHosts($scope);
        if ($liveHosts !== []) {
            $storeHost = $this->config->getStoreHost($scope);
            // Fails closed: a store whose address cannot be read is treated as a copy, not as the live store.
            if (!in_array($storeHost, $liveHosts, true) && $mode === Config::DELIVERY_LIVE) {
                $mode = $testRecipients !== [] ? Config::DELIVERY_REDIRECT : Config::DELIVERY_LOG_ONLY;
                $notes[] = (string)__('Staging safety lock: %1 is not the live domain.', $storeHost !== '' ? $storeHost : (string)__('the store address'));
            }
        }

        if ($mode !== Config::DELIVERY_LIVE) {
            $allowedList = $this->config->getAllowedRecipients($scope);
            $allowed = static fn (array $list): array => array_values(array_filter(
                $list,
                static fn (string $address): bool => self::matchesPattern($address, $allowedList)
            ));
            $allowedTo = $allowed($to);
            $allowedCc = $allowed($cc);
            $allowedBcc = $allowed($bcc);
            $others = array_diff(array_merge($to, $cc, $bcc), array_merge($allowedTo, $allowedCc, $allowedBcc));

            if ($others !== []) {
                $changed = true;
                if ($mode === Config::DELIVERY_REDIRECT && $testRecipients !== []) {
                    $to = array_values(array_unique(array_merge($allowedTo, $testRecipients)));
                    $notes[] = (string)__('Sent to the test address instead of %1.', implode(', ', array_unique($others)));
                    $prefix = $this->config->getSubjectPrefix($scope);
                    if ($prefix !== '') {
                        $mime->setText('Subject', $prefix . ' ' . $mime->getDecoded('Subject'));
                    }
                } else {
                    $to = $allowedTo;
                    if ($allowedTo === [] && $allowedCc === [] && $allowedBcc === []) {
                        $notes[] = (string)__('Not sent: delivery is set to log only.');
                        return $this->skip($mime, $email, implode(' ', $notes));
                    }
                    $notes[] = (string)__('Log only: not sent to %1.', implode(', ', array_unique($others)));
                }
                $cc = $allowedCc;
                $bcc = $allowedBcc;
                $mime->set(self::ORIGINAL_HEADER, $this->addressParser->format($original));
            }
        }

        if ($changed) {
            if ($to !== []) {
                $mime->set('To', $this->addressParser->format($to));
            } else {
                $mime->remove('To');
            }
            if ($cc !== []) {
                $mime->set('Cc', $this->addressParser->format($cc));
            } else {
                $mime->remove('Cc');
            }
        }

        $fromHeader = $this->addressParser->parse((string)$mime->get('From'));
        $fromEmail = $fromHeader[0]['email'] ?? $email->from;
        $override = $this->config->getFromOverride($scope);
        if ($override !== '' && strcasecmp($override, $fromEmail) !== 0) {
            $name = $fromHeader[0]['name'] ?? '';
            $mime->set('From', $this->addressParser->format([['email' => $override, 'name' => $name]]));
            if (!$mime->has('Reply-To') && $fromEmail !== '') {
                $mime->set('Reply-To', $this->addressParser->format([['email' => $fromEmail, 'name' => $name]]));
            }
            $fromEmail = $override;
        }

        $sender = $this->addressParser->parseEmails((string)$mime->get('Sender'));
        $envelopeFrom = $this->config->getReturnPathEmail($scope) ?: ($sender[0] ?? $fromEmail);

        if ($resend) {
            // A new Message-ID: mailboxes drop a message whose ID they have seen before.
            $mime->set('Message-ID', MessageExtractor::newMessageId($fromEmail, $this->config->getHeloName($scope)));
            $mime->set('Date', date(DATE_RFC2822));
        }

        return new PreparedEmail(
            true,
            $mime->toString(),
            $envelopeFrom,
            $fromEmail,
            $to,
            $cc,
            $bcc,
            $changed ? $original : [],
            $notes,
            trim((string)$mime->get('Message-ID'))
        );
    }

    /**
     * True when the address matches one of the patterns: name@example.com, @example.com, example.com or with *.
     *
     * @param string[] $patterns lower-case
     */
    public static function matchesPattern(string $address, array $patterns): bool
    {
        $address = strtolower(trim($address));
        $domain = (string)substr($address, (int)strrpos($address, '@') + 1);
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
                $subject = str_contains($pattern, '@') ? $address : $domain;
                if (preg_match($regex, $subject) === 1) {
                    return true;
                }
                continue;
            }
            if (str_starts_with($pattern, '@')) {
                if ($domain === substr($pattern, 1)) {
                    return true;
                }
                continue;
            }
            if (str_contains($pattern, '@') ? $address === $pattern : $domain === $pattern) {
                return true;
            }
        }
        return false;
    }

    private function skip(MimeMessage $mime, OutgoingEmail $email, string $note): PreparedEmail
    {
        $from = $this->addressParser->parseEmails((string)$mime->get('From'));
        return new PreparedEmail(
            false,
            $mime->toString(),
            $email->from,
            $from[0] ?? $email->from,
            $email->to,
            $email->cc,
            $email->bcc,
            [],
            [$note],
            trim((string)$mime->get('Message-ID'))
        );
    }
}
