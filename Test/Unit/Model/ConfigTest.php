<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model;

use CommerceGears\Core\Api\LicenceStateInterface;
use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Provider\PresetRegistry;
use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array<string, string> $values path without section => value
     */
    private function config(array $values, bool $licensed = true): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static function (string $path) use ($values) {
            $short = str_starts_with($path, Config::SECTION . '/') ? substr($path, strlen(Config::SECTION) + 1) : $path;
            return $values[$short] ?? $values[$path] ?? null;
        });
        $scopeConfig->method('isSetFlag')->willReturnCallback(static function (string $path) use ($values) {
            $short = str_starts_with($path, Config::SECTION . '/') ? substr($path, strlen(Config::SECTION) + 1) : $path;
            return (bool)($values[$short] ?? $values[$path] ?? false);
        });
        $licence = $this->createStub(LicenceStateInterface::class);
        $licence->method('isFeatureEnabled')->willReturn($licensed);
        return new Config($scopeConfig, new PresetRegistry(), $this->createStub(StoreManagerInterface::class), $licence);
    }

    public function testCustomServer(): void
    {
        $settings = $this->config([
            'connection/provider' => 'custom', 'connection/host' => 'mail.example.com', 'connection/port' => '465',
            'connection/encryption' => 'ssl', 'connection/auth_method' => 'login', 'connection/username' => 'u',
            'connection/password' => ' p w ', 'connection/verify_tls' => '0', 'web/unsecure/base_url' => 'https://Shop.Example.com/',
        ])->getConnectionSettings(Scope::store(1));
        $this->assertSame('mail.example.com', $settings->host);
        $this->assertSame(465, $settings->port);
        $this->assertSame(ConnectionSettings::ENCRYPTION_SSL, $settings->encryption);
        $this->assertSame(ConnectionSettings::AUTH_LOGIN, $settings->authMethod);
        $this->assertSame(' p w ', $settings->password, 'Passwords are not trimmed');
        $this->assertFalse($settings->verifyTls);
        $this->assertSame('shop.example.com', $settings->heloName);
    }

    public function testPresetDefaultsAndPortDerivedEncryption(): void
    {
        $settings = $this->config(['connection/provider' => 'sendgrid', 'connection/password' => 'SG.key', 'connection/username' => 'ignored'])->getConnectionSettings();
        $this->assertSame('smtp.sendgrid.net', $settings->host);
        $this->assertSame(587, $settings->port);
        $this->assertSame('tls', $settings->encryption);
        $this->assertSame('apikey', $settings->username);
        $this->assertTrue($settings->verifyTls);

        $settings = $this->config(['connection/provider' => 'brevo', 'connection/port' => '465'])->getConnectionSettings();
        $this->assertSame('ssl', $settings->encryption);

        $settings = $this->config(['connection/provider' => 'cloudflare'])->getConnectionSettings();
        $this->assertSame(465, $settings->port);
        $this->assertSame('ssl', $settings->encryption);
        $this->assertSame('api_token', $settings->username);
    }

    public function testPostmarkUsesTokenAsUsername(): void
    {
        $settings = $this->config(['connection/provider' => 'postmark', 'connection/password' => 'server-token'])->getConnectionSettings();
        $this->assertSame('server-token', $settings->username);
    }

    public function testRegions(): void
    {
        $this->assertSame('email-smtp.eu-west-2.amazonaws.com', $this->config(['connection/provider' => 'ses', 'connection/region_ses' => 'eu-west-2'])->getConnectionSettings()->host);
        $this->assertSame('email-smtp.eu-west-1.amazonaws.com', $this->config(['connection/provider' => 'ses', 'connection/region_ses' => 'evil.com/'])->getConnectionSettings()->host);
        $this->assertSame('smtp.eu.mailgun.org', $this->config(['connection/provider' => 'mailgun', 'connection/region_mailgun' => 'smtp.eu.mailgun.org'])->getConnectionSettings()->host);
        $this->assertSame('smtp.zoho.com', $this->config(['connection/provider' => 'zoho', 'connection/region_zoho' => 'com'])->getConnectionSettings()->host);
    }

    public function testOAuth(): void
    {
        $config = $this->config([
            'connection/provider' => 'microsoft_oauth', 'connection/oauth_flow' => 'app', 'connection/oauth_client_id' => 'cid',
            'connection/oauth_client_secret' => 'sec', 'connection/oauth_tenant' => 'contoso.onmicrosoft.com', 'connection/username' => 'box@contoso.com',
        ]);
        $settings = $config->getConnectionSettings();
        $this->assertSame(ConnectionSettings::AUTH_XOAUTH2, $settings->authMethod);
        $this->assertSame('', $settings->password);
        $oauth = $config->getOAuthSettings();
        $this->assertNotNull($oauth);
        $this->assertTrue($oauth->isAppOnly());
        $this->assertTrue($oauth->isComplete());
        $this->assertSame('contoso.onmicrosoft.com', $oauth->getTenantForUrl());
        $this->assertNull($this->config(['connection/provider' => 'google'])->getOAuthSettings());
        $google = $this->config(['connection/provider' => 'google_oauth', 'connection/oauth_flow' => 'app'])->getOAuthSettings();
        $this->assertFalse($google->isAppOnly(), 'App-only exists for Microsoft only');
    }

    public function testDeliveryLists(): void
    {
        $config = $this->config([
            'delivery/test_recipients' => 'qa@example.com, not-an-email; dev@example.com',
            'delivery/live_host' => 'https://www.Example.com/path, shop.example.com',
            'delivery/mode' => 'something-else',
        ]);
        $this->assertSame(['qa@example.com', 'dev@example.com'], $config->getTestRecipients());
        $this->assertSame(['www.example.com', 'shop.example.com'], $config->getLiveHosts());
        $this->assertSame(Config::DELIVERY_LIVE, $config->getDeliveryMode());
    }

    public function testLicenceAndRetry(): void
    {
        $this->assertFalse($this->config(['general/enabled' => '1'], false)->isEnabled());
        $this->assertTrue($this->config(['general/enabled' => '1'])->isEnabled());
        $this->assertSame(1, $this->config(['queue/retry' => '0', 'queue/max_attempts' => '5'])->getMaxAttempts());
        $this->assertSame(10, $this->config(['queue/retry' => '1', 'queue/max_attempts' => '99'])->getMaxAttempts());
    }

    public function testReturnPath(): void
    {
        $this->assertSame('', $this->config(['system/smtp/set_return_path' => '1', 'system/smtp/return_path_email' => 'b@example.com'])->getReturnPathEmail());
        $this->assertSame('b@example.com', $this->config(['system/smtp/set_return_path' => '2', 'system/smtp/return_path_email' => 'b@example.com'])->getReturnPathEmail());
    }

    public function testContentRetention(): void
    {
        $this->assertSame(30, $this->config(['log/retention_days' => '30'])->getContentRetentionDays());
        $this->assertSame(7, $this->config(['log/retention_days' => '30', 'log/content_retention_days' => '7'])->getContentRetentionDays());
        $this->assertSame(30, $this->config(['log/retention_days' => '30', 'log/content_retention_days' => '90'])->getContentRetentionDays(), 'Never longer than the log entry');
        $this->assertSame(14, $this->config(['log/retention_days' => '0', 'log/content_retention_days' => '14'])->getContentRetentionDays());
        $this->assertSame(0, $this->config(['log/retention_days' => '0'])->getContentRetentionDays());
    }

    public function testBypassOnlyWithoutDeliveryRules(): void
    {
        $live = ['web/unsecure/base_url' => 'https://www.example.com/'];
        $this->assertTrue($this->config($live)->canBypassDeliveryRules());
        $this->assertFalse($this->config($live + ['delivery/mode' => 'redirect'])->canBypassDeliveryRules());
        $this->assertFalse($this->config($live + ['delivery/mode' => 'log_only'])->canBypassDeliveryRules());
        $this->assertFalse($this->config($live + ['delivery/blocked_recipients' => '@example.org'])->canBypassDeliveryRules());
        $this->assertTrue($this->config($live + ['delivery/live_host' => 'www.example.com'])->canBypassDeliveryRules());
        $this->assertFalse($this->config(['web/unsecure/base_url' => 'https://staging.example.com/', 'delivery/live_host' => 'www.example.com'])->canBypassDeliveryRules());
    }
}
