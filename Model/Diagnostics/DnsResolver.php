<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Diagnostics;

/**
 * DNS lookups through PHP's resolver (replaced by a fake in unit tests).
 */
class DnsResolver
{
    /**
     * @return string[] TXT strings (multi-part records joined)
     */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }
        $result = [];
        foreach ($records as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $result[] = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $result[] = (string)$record['txt'];
            }
        }
        return $result;
    }

    /**
     * @return string[] CNAME targets
     */
    public function cname(string $name): array
    {
        $records = @dns_get_record($name, DNS_CNAME);
        if (!is_array($records)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (array $r): string => (string)($r['target'] ?? ''), $records)));
    }

    /**
     * @return string[] MX hosts
     */
    public function mx(string $name): array
    {
        $records = @dns_get_record($name, DNS_MX);
        if (!is_array($records)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn (array $r): string => (string)($r['target'] ?? ''), $records)));
    }
}
