<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Diagnostics;

use CommerceGears\Smtp\Model\Diagnostics\DnsChecker;
use CommerceGears\Smtp\Model\Diagnostics\DnsResolver;
use CommerceGears\Smtp\Model\Provider\PresetRegistry;
use PHPUnit\Framework\TestCase;

class DnsCheckerTest extends TestCase
{
    /**
     * @param array<string, string[]> $txt
     * @param array<string, string[]> $cname
     * @param array<string, string[]> $mx
     */
    private function checker(array $txt, array $cname = [], array $mx = []): DnsChecker
    {
        $resolver = $this->createStub(DnsResolver::class);
        $resolver->method('txt')->willReturnCallback(static fn (string $name): array => $txt[$name] ?? []);
        $resolver->method('cname')->willReturnCallback(static fn (string $name): array => $cname[$name] ?? []);
        $resolver->method('mx')->willReturnCallback(static fn (string $name): array => $mx[$name] ?? []);
        return new DnsChecker($resolver);
    }

    /**
     * @param array<int, array{check: string, status: string, message: string, record: string}> $rows
     * @return array<string, string>
     */
    private function statuses(array $rows): array
    {
        return array_column($rows, 'status', 'check');
    }

    public function testAllGoodForGoogle(): void
    {
        $rows = $this->checker(
            [
                'example.com' => ['google-site-verification=x', 'v=spf1 include:_spf.google.com ~all'],
                'google._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIIBIjAN'],
                '_dmarc.example.com' => ['v=DMARC1; p=quarantine; rua=mailto:d@example.com'],
            ],
            [],
            ['example.com' => ['aspmx.l.google.com']]
        )->check('shop@Example.com', (new PresetRegistry())->get(PresetRegistry::GOOGLE));
        $this->assertSame(['SPF' => 'ok', 'DKIM' => 'ok', 'DMARC' => 'ok', 'MX' => 'ok'], $this->statuses($rows));
    }

    public function testProblems(): void
    {
        $rows = $this->checker([
            'example.com' => ['v=spf1 include:a.com ~all', 'v=spf1 mx -all'],
        ])->check('example.com', (new PresetRegistry())->get(PresetRegistry::SENDGRID));
        $this->assertSame(['SPF' => 'error', 'DKIM' => 'warning', 'DMARC' => 'warning', 'MX' => 'warning'], $this->statuses($rows));
    }

    public function testMissingProviderInclude(): void
    {
        $rows = $this->checker(['example.com' => ['v=spf1 include:spf.protection.outlook.com -all']])
            ->check('example.com', (new PresetRegistry())->get(PresetRegistry::SENDGRID));
        $this->assertSame('warning', $rows[0]['status']);
        $this->assertStringContainsString('include:sendgrid.net', $rows[0]['message']);
    }

    public function testDkimCnameAndCustomSelector(): void
    {
        $rows = $this->checker([], ['k9._domainkey.example.com' => ['k9.dkim.provider.net']])
            ->check('example.com', (new PresetRegistry())->get(PresetRegistry::CUSTOM), ['k9']);
        $this->assertSame('ok', $rows[1]['status']);
    }

    public function testPlusAllAndInvalidDomain(): void
    {
        $rows = $this->checker(['example.com' => ['v=spf1 +all']])->check('example.com', (new PresetRegistry())->get(PresetRegistry::CUSTOM));
        $this->assertSame('error', $rows[0]['status']);
        $this->assertSame('error', $this->checker([])->check('not a domain', (new PresetRegistry())->get(PresetRegistry::CUSTOM))[0]['status']);
        $this->assertSame('', DnsChecker::normaliseDomain('evil.com/../x'));
    }
}
