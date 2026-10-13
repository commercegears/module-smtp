<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Diagnostics;

use CommerceGears\Smtp\Model\Provider\Preset;

/**
 * Plain-language checks of the sender domain's SPF, DKIM, DMARC and MX records. Only reads public DNS.
 */
class DnsChecker
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const INFO = 'info';

    public function __construct(
        private readonly DnsResolver $resolver
    ) {
    }

    /**
     * @param string[] $extraSelectors DKIM selectors entered by the admin
     * @return array<int, array{check: string, status: string, message: string, record: string}>
     */
    public function check(string $domain, Preset $preset, array $extraSelectors = []): array
    {
        $domain = self::normaliseDomain($domain);
        if ($domain === '') {
            return [[
                'check' => 'Domain',
                'status' => self::ERROR,
                'message' => (string)__('Enter a valid domain, for example example.com.'),
                'record' => '',
            ]];
        }
        return [
            $this->checkSpf($domain, $preset),
            $this->checkDkim($domain, $preset, $extraSelectors),
            $this->checkDmarc($domain),
            $this->checkMx($domain),
        ];
    }

    public static function normaliseDomain(string $input): string
    {
        $input = strtolower(trim($input));
        if (str_contains($input, '@')) {
            $input = substr($input, (int)strrpos($input, '@') + 1);
        }
        $input = rtrim($input, '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $input) === 1) {
            $ascii = idn_to_ascii($input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $input = is_string($ascii) ? $ascii : '';
        }
        if ($input === '' || strlen($input) > 253
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $input) !== 1) {
            return '';
        }
        return $input;
    }

    /**
     * @return array{check: string, status: string, message: string, record: string}
     */
    private function checkSpf(string $domain, Preset $preset): array
    {
        $records = array_values(array_filter(
            $this->resolver->txt($domain),
            static fn (string $txt): bool => stripos(trim($txt), 'v=spf1') === 0
        ));
        if ($records === []) {
            return $this->row('SPF', self::WARNING, (string)__('No SPF record found for %1. Add one that allows your email provider, otherwise many mailboxes treat your emails as spam.', $domain), '');
        }
        if (count($records) > 1) {
            return $this->row('SPF', self::ERROR, (string)__('%1 has %2 SPF records. Only one is allowed: merge them into a single record, or SPF fails everywhere.', $domain, count($records)), implode("\n", $records));
        }
        $record = $records[0];
        $lower = strtolower($record);
        if ($preset->spfIncludes !== []) {
            $found = false;
            foreach ($preset->spfIncludes as $include) {
                if (str_contains($lower, strtolower($include))) {
                    $found = true;
                    break;
                }
            }
            if (!$found && !str_contains($lower, 'redirect=')) {
                return $this->row('SPF', self::WARNING, (string)__('The SPF record does not include %1. Add "%2" before the "all" at the end, unless the provider uses its own bounce domain (for example a custom MAIL FROM domain).', $preset->label, $preset->spfIncludes[0]), $record);
            }
        }
        $lookups = preg_match_all('/\b(include:|a\b|a:|mx\b|mx:|ptr|exists:|redirect=)/i', $record);
        if ($lookups > 10) {
            return $this->row('SPF', self::ERROR, (string)__('The SPF record needs more than 10 DNS lookups, which makes it invalid.'), $record);
        }
        if (preg_match('/\+all\b/i', $record) === 1) {
            return $this->row('SPF', self::ERROR, (string)__('The SPF record ends in "+all", which allows anyone to send as your domain. Use "~all" or "-all".'), $record);
        }
        return $this->row('SPF', self::OK, (string)__('SPF record found.'), $record);
    }

    /**
     * @param string[] $extraSelectors
     * @return array{check: string, status: string, message: string, record: string}
     */
    private function checkDkim(string $domain, Preset $preset, array $extraSelectors): array
    {
        $selectors = [];
        foreach (array_merge($extraSelectors, $preset->dkimSelectors) as $selector) {
            $selector = strtolower(trim($selector));
            if ($selector !== '' && preg_match('/^[a-z0-9._-]{1,63}$/', $selector) === 1) {
                $selectors[] = $selector;
            }
        }
        $selectors = array_values(array_unique($selectors));
        if ($selectors === []) {
            return $this->row('DKIM', self::INFO, (string)__('No DKIM selector to check. Enter the selector from your email provider\'s domain settings (the part before ._domainkey).'), '');
        }
        foreach ($selectors as $selector) {
            $name = $selector . '._domainkey.' . $domain;
            foreach ($this->resolver->txt($name) as $txt) {
                if (stripos($txt, 'v=DKIM1') !== false || stripos($txt, 'p=') !== false) {
                    if (preg_match('/\bp=\s*(;|$)/i', $txt) === 1) {
                        return $this->row('DKIM', self::ERROR, (string)__('The DKIM key for selector "%1" is empty (revoked).', $selector), $name . ' TXT ' . $txt);
                    }
                    return $this->row('DKIM', self::OK, (string)__('DKIM key found for selector "%1".', $selector), $name . ' TXT ' . mb_strimwidth($txt, 0, 120, '...'));
                }
            }
            $cname = $this->resolver->cname($name);
            if ($cname !== []) {
                return $this->row('DKIM', self::OK, (string)__('DKIM record found for selector "%1" (points to your provider).', $selector), $name . ' CNAME ' . $cname[0]);
            }
        }
        return $this->row('DKIM', self::WARNING, (string)__('No DKIM record found for selector(s) %1. Set up domain authentication (DKIM) with your email provider, or enter your selector.', implode(', ', $selectors)), '');
    }

    /**
     * @return array{check: string, status: string, message: string, record: string}
     */
    private function checkDmarc(string $domain): array
    {
        $records = array_values(array_filter(
            $this->resolver->txt('_dmarc.' . $domain),
            static fn (string $txt): bool => stripos(trim($txt), 'v=DMARC1') === 0
        ));
        if ($records === []) {
            return $this->row('DMARC', self::WARNING, (string)__('No DMARC record found. Gmail and Yahoo expect one from senders: start with "v=DMARC1; p=none; rua=mailto:you@%1" at _dmarc.%1.', $domain), '');
        }
        if (count($records) > 1) {
            return $this->row('DMARC', self::ERROR, (string)__('More than one DMARC record: keep only one.'), implode("\n", $records));
        }
        $policy = preg_match('/\bp=\s*([a-z]+)/i', $records[0], $m) === 1 ? strtolower($m[1]) : '';
        $message = match ($policy) {
            'reject' => (string)__('DMARC policy "reject": emails that fail SPF and DKIM are refused. Make sure both pass.'),
            'quarantine' => (string)__('DMARC policy "quarantine": emails that fail SPF and DKIM go to spam.'),
            'none' => (string)__('DMARC policy "none": you get reports, nothing is blocked.'),
            default => (string)__('DMARC record found, but the policy (p=) is missing or invalid.'),
        };
        return $this->row('DMARC', in_array($policy, ['reject', 'quarantine', 'none'], true) ? self::OK : self::WARNING, $message, $records[0]);
    }

    /**
     * @return array{check: string, status: string, message: string, record: string}
     */
    private function checkMx(string $domain): array
    {
        $mx = $this->resolver->mx($domain);
        if ($mx === []) {
            return $this->row('MX', self::WARNING, (string)__('%1 has no MX record, so replies and bounces to this address cannot be delivered.', $domain), '');
        }
        return $this->row('MX', self::OK, (string)__('The domain can receive email (replies and bounces).'), implode(', ', array_slice($mx, 0, 5)));
    }

    /**
     * @return array{check: string, status: string, message: string, record: string}
     */
    private function row(string $check, string $status, string $message, string $record): array
    {
        return ['check' => $check, 'status' => $status, 'message' => $message, 'record' => $record];
    }
}
