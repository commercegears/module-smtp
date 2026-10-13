<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\OAuth;

use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Hands out OAuth access tokens for SMTP sign-in. Access tokens are cached (encrypted) for their real lifetime minus
 * two minutes; refresh tokens that the provider rotates are stored again.
 */
class TokenManager
{
    public const CACHE_TAG = 'COMMERCEGEARS_SMTP_TOKEN';
    private const CACHE_PREFIX = 'commercegears_smtp_token_';

    /** @var array<string, array{token: string, expires: int}> */
    private array $memory = [];

    public function __construct(
        private readonly OAuthClient $client,
        private readonly TokenStorage $storage,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    /**
     * @throws SmtpException
     */
    public function getAccessToken(Scope $scope, OAuthSettings $settings, bool $forceRefresh = false): string
    {
        if (!$settings->isComplete()) {
            throw new SmtpException(
                $settings->isAppOnly()
                    ? (string)__('Enter the OAuth client ID, client secret and tenant ID, then save.')
                    : (string)__('Enter the OAuth client ID and client secret, save, then click Connect Account.'),
                SmtpException::STAGE_TOKEN
            );
        }
        if ($settings->username === '') {
            throw new SmtpException((string)__('Enter the mailbox address as the username.'), SmtpException::STAGE_TOKEN);
        }

        $row = null;
        if (!$settings->isAppOnly()) {
            $row = $this->storage->findForScope($scope);
            if ($row === null || ($row['refresh_token'] ?? '') === '') {
                throw new SmtpException(
                    (string)__('The account is not connected yet. Save the settings, then click Connect Account.'),
                    SmtpException::STAGE_TOKEN
                );
            }
            if (!hash_equals((string)$row['fingerprint'], $settings->getFingerprint())) {
                throw new SmtpException(
                    (string)__('The connected account was set up with another client ID or username. Click Connect Account again.'),
                    SmtpException::STAGE_TOKEN
                );
            }
        }

        $cacheKey = self::CACHE_PREFIX . hash('sha256', $settings->getFingerprint() . '|' . ($row['token_id'] ?? 'app') . '|' . ($row['connected_at'] ?? ''));
        if (!$forceRefresh) {
            $cached = $this->loadCached($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            if ($settings->isAppOnly()) {
                $result = $this->client->clientCredentials($settings);
            } else {
                $result = $this->client->refresh($settings, (string)$row['refresh_token']);
                $this->storage->updateRefreshToken((int)$row['token_id'], $result['refresh_token']);
            }
        } catch (SmtpException $e) {
            if ($row !== null) {
                $this->storage->setError((int)$row['token_id'], $e->getMessage());
            }
            throw $e;
        }

        $expires = time() + $result['expires_in'] - 120;
        $this->memory[$cacheKey] = ['token' => $result['access_token'], 'expires' => $expires];
        $this->cache->save(
            $this->encryptor->encrypt($this->json->serialize(['token' => $result['access_token'], 'expires' => $expires])),
            $cacheKey,
            [self::CACHE_TAG],
            max(60, $result['expires_in'] - 120)
        );
        return $result['access_token'];
    }

    private function loadCached(string $key): ?string
    {
        $entry = $this->memory[$key] ?? null;
        if ($entry === null) {
            $raw = $this->cache->load($key);
            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = $this->json->unserialize($this->encryptor->decrypt($raw));
                } catch (\InvalidArgumentException) {
                    $decoded = null;
                }
                if (is_array($decoded) && isset($decoded['token'], $decoded['expires'])) {
                    $entry = ['token' => (string)$decoded['token'], 'expires' => (int)$decoded['expires']];
                }
            }
        }
        if ($entry === null || $entry['expires'] <= time()) {
            return null;
        }
        $this->memory[$key] = $entry;
        return $entry['token'];
    }
}
