<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\OAuth;

/**
 * OAuth 2.0 client settings of one scope.
 */
class OAuthSettings
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';

    public function __construct(
        public readonly string $provider,
        public readonly string $flow,
        public readonly string $clientId,
        #[\SensitiveParameter] public readonly string $clientSecret,
        public readonly string $tenant,
        public readonly string $username
    ) {
    }

    public function isAppOnly(): bool
    {
        return $this->flow === 'app';
    }

    public function isComplete(): bool
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            return false;
        }
        return !$this->isAppOnly() || $this->tenant !== '';
    }

    /**
     * Microsoft tenant for the URLs: the configured tenant ID or domain, or "common" for delegated sign-in.
     */
    public function getTenantForUrl(): string
    {
        $tenant = trim($this->tenant);
        if ($tenant === '' || preg_match('/^[A-Za-z0-9.-]+$/', $tenant) !== 1) {
            return 'common';
        }
        return $tenant;
    }

    /**
     * Identifies the client, so tokens of another client or account are never used.
     */
    public function getFingerprint(): string
    {
        return hash('sha256', implode("\0", [$this->provider, $this->flow, $this->clientId, $this->getTenantForUrl(), strtolower($this->username)]));
    }
}
