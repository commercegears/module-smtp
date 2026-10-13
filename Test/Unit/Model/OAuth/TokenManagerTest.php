<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\OAuth;

use CommerceGears\Smtp\Model\OAuth\OAuthClient;
use CommerceGears\Smtp\Model\OAuth\OAuthSettings;
use CommerceGears\Smtp\Model\OAuth\TokenManager;
use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class TokenManagerTest extends TestCase
{
    private function settings(string $flow = 'delegated', string $user = 'box@example.com'): OAuthSettings
    {
        return new OAuthSettings(OAuthSettings::MICROSOFT, $flow, 'cid', 'secret', 'tenant.example', $user);
    }

    /**
     * @param array<string, mixed>|null $row
     * @param string[] $log
     */
    private function manager(?array $row, OAuthClient $client, array &$log = []): TokenManager
    {
        $storage = $this->createStub(TokenStorage::class);
        $storage->method('findForScope')->willReturn($row);
        $storage->method('updateRefreshToken')->willReturnCallback(function (int $id, string $token) use (&$log): void {
            $log[] = 'update:' . $token;
        });
        $storage->method('setError')->willReturnCallback(function (int $id, string $error) use (&$log): void {
            $log[] = 'error:' . $error;
        });
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnArgument(0);
        $encryptor->method('decrypt')->willReturnArgument(0);
        return new TokenManager($client, $storage, $cache, $encryptor, new Json());
    }

    public function testDelegatedRefreshStoresRotatedTokenAndCachesInMemory(): void
    {
        $settings = $this->settings();
        $row = ['token_id' => 5, 'fingerprint' => $settings->getFingerprint(), 'refresh_token' => 'rt1', 'connected_at' => '2026-10-13 00:00:00'];
        $client = $this->createMock(OAuthClient::class);
        $client->expects($this->once())->method('refresh')->with($settings, 'rt1')
            ->willReturn(['access_token' => 'at1', 'refresh_token' => 'rt2', 'expires_in' => 3600]);
        $log = [];
        $manager = $this->manager($row, $client, $log);
        $this->assertSame('at1', $manager->getAccessToken(Scope::store(1), $settings));
        $this->assertSame('at1', $manager->getAccessToken(Scope::store(1), $settings), 'Second call uses the cached token');
        $this->assertSame(['update:rt2'], $log);
    }

    public function testNotConnected(): void
    {
        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('not connected');
        $this->manager(null, $this->createStub(OAuthClient::class))->getAccessToken(Scope::default(), $this->settings());
    }

    public function testFingerprintMismatch(): void
    {
        $row = ['token_id' => 5, 'fingerprint' => $this->settings('delegated', 'old@example.com')->getFingerprint(), 'refresh_token' => 'rt1', 'connected_at' => ''];
        $this->expectExceptionMessage('another client ID or username');
        $this->manager($row, $this->createStub(OAuthClient::class))->getAccessToken(Scope::default(), $this->settings());
    }

    public function testRefreshErrorIsStored(): void
    {
        $settings = $this->settings();
        $row = ['token_id' => 5, 'fingerprint' => $settings->getFingerprint(), 'refresh_token' => 'rt1', 'connected_at' => ''];
        $client = $this->createStub(OAuthClient::class);
        $client->method('refresh')->willThrowException(new SmtpException('Microsoft no longer accepts the saved sign-in', SmtpException::STAGE_TOKEN));
        $log = [];
        try {
            $this->manager($row, $client, $log)->getAccessToken(Scope::default(), $settings);
            $this->fail('Expected an exception');
        } catch (SmtpException $e) {
            $this->assertSame(['error:Microsoft no longer accepts the saved sign-in'], $log);
        }
    }

    public function testAppOnlyNeedsNoStoredToken(): void
    {
        $client = $this->createMock(OAuthClient::class);
        $client->expects($this->once())->method('clientCredentials')->willReturn(['access_token' => 'app-at', 'refresh_token' => '', 'expires_in' => 3600]);
        $this->assertSame('app-at', $this->manager(null, $client)->getAccessToken(Scope::default(), $this->settings('app')));
    }

    public function testIncompleteSettings(): void
    {
        $this->expectExceptionMessage('client ID and client secret');
        $this->manager(null, $this->createStub(OAuthClient::class))->getAccessToken(
            Scope::default(),
            new OAuthSettings(OAuthSettings::GOOGLE, 'delegated', '', '', '', 'a@b.c')
        );
    }
}
