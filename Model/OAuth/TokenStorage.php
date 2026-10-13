<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\OAuth;

use CommerceGears\Smtp\Model\Scope;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stores the OAuth refresh token per configuration scope (encrypted with the Magento key). A store view uses its own
 * connection, else its website's, else the default one, like configuration values.
 */
class TokenStorage
{
    public const TABLE = 'commercegears_smtp_oauth_token';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The connection that applies to the scope, with the refresh token decrypted.
     *
     * @return array<string, mixed>|null
     */
    public function findForScope(Scope $scope): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        foreach ($this->getScopeChain($scope) as [$type, $id]) {
            $row = $connection->fetchRow(
                $connection->select()->from($table)->where('scope = ?', $type)->where('scope_id = ?', $id)
            );
            if (is_array($row) && $row !== []) {
                $row['refresh_token'] = $row['refresh_token'] !== null && $row['refresh_token'] !== ''
                    ? $this->encryptor->decrypt((string)$row['refresh_token'])
                    : '';
                return $row;
            }
        }
        return null;
    }

    public function save(Scope $scope, OAuthSettings $settings, #[\SensitiveParameter] string $refreshToken, string $account): void
    {
        $now = $this->dateTime->gmtDate();
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'scope' => $scope->type,
                'scope_id' => $scope->id,
                'provider' => $settings->provider,
                'fingerprint' => $settings->getFingerprint(),
                'account' => mb_substr($account, 0, 255),
                'refresh_token' => $this->encryptor->encrypt($refreshToken),
                'connected_at' => $now,
                'refreshed_at' => null,
                'last_error' => null,
            ],
            ['provider', 'fingerprint', 'account', 'refresh_token', 'connected_at', 'refreshed_at', 'last_error']
        );
    }

    public function updateRefreshToken(int $tokenId, #[\SensitiveParameter] string $refreshToken): void
    {
        $data = ['refreshed_at' => $this->dateTime->gmtDate(), 'last_error' => null];
        if ($refreshToken !== '') {
            $data['refresh_token'] = $this->encryptor->encrypt($refreshToken);
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            $data,
            ['token_id = ?' => $tokenId]
        );
    }

    public function setError(int $tokenId, string $error): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['last_error' => mb_substr($error, 0, 1000)],
            ['token_id = ?' => $tokenId]
        );
    }

    public function delete(Scope $scope): int
    {
        return (int)$this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['scope = ?' => $scope->type, 'scope_id = ?' => $scope->id]
        );
    }

    /**
     * Number of connections with a sign-in error (for the admin notice).
     */
    public function countWithErrors(): int
    {
        $connection = $this->resource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['COUNT(*)'])
                ->where('last_error IS NOT NULL')
        );
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function getScopeChain(Scope $scope): array
    {
        $chain = [];
        if ($scope->isStore()) {
            $chain[] = [$scope->type, $scope->id];
            try {
                $websiteId = (int)$this->storeManager->getStore($scope->id)->getWebsiteId();
                if ($websiteId > 0) {
                    $chain[] = [Scope::website($websiteId)->type, $websiteId];
                }
            } catch (\Exception) {
                // Deleted store: fall back to default.
            }
        } elseif ($scope->isWebsite()) {
            $chain[] = [$scope->type, $scope->id];
        }
        $default = Scope::default();
        $chain[] = [$default->type, 0];
        return $chain;
    }
}
