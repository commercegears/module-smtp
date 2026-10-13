<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Provider;

/**
 * One provider preset: where to connect and how to sign in. Hosts can contain {region}.
 */
class Preset
{
    public const AUTH_PASSWORD = 'password';
    public const AUTH_OAUTH_GOOGLE = 'oauth_google';
    public const AUTH_OAUTH_MICROSOFT = 'oauth_microsoft';

    /**
     * @param array<string, string> $regions region code => label (empty when the host is fixed)
     * @param string[] $spfIncludes SPF mechanisms that show the provider is allowed to send for a domain
     * @param string[] $dkimSelectors usual DKIM selectors (empty when the provider generates random ones)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $host,
        public readonly int $defaultPort,
        public readonly string $auth = self::AUTH_PASSWORD,
        public readonly array $regions = [],
        public readonly string $defaultRegion = '',
        public readonly string $fixedUsername = '',
        public readonly bool $usernameIsPassword = false,
        public readonly array $spfIncludes = [],
        public readonly array $dkimSelectors = [],
        public readonly string $help = ''
    ) {
    }

    public function isCustom(): bool
    {
        return $this->code === PresetRegistry::CUSTOM;
    }

    public function isOAuth(): bool
    {
        return $this->auth !== self::AUTH_PASSWORD;
    }

    public function resolveHost(string $region): string
    {
        if ($this->regions === []) {
            return $this->host;
        }
        if (!isset($this->regions[$region])) {
            $region = $this->defaultRegion;
        }
        return str_replace('{region}', $region, $this->host);
    }
}
