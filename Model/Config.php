<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use CommerceGears\Core\Api\LicenceStateInterface;
use CommerceGears\Smtp\Model\OAuth\OAuthSettings;
use CommerceGears\Smtp\Model\Provider\Preset;
use CommerceGears\Smtp\Model\Provider\PresetRegistry;
use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Typed access to Stores > Configuration > CommerceGears > SMTP (section commercegears_smtp).
 */
class Config
{
    public const SECTION = 'commercegears_smtp';
    public const PACKAGE = 'commercegears/module-smtp';

    public const DELIVERY_LIVE = 'live';
    public const DELIVERY_REDIRECT = 'redirect';
    public const DELIVERY_LOG_ONLY = 'log_only';

    public const SENDING_IMMEDIATE = 'immediate';
    public const SENDING_QUEUE = 'queue';

    public const OAUTH_FLOW_DELEGATED = 'delegated';
    public const OAUTH_FLOW_APP = 'app';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PresetRegistry $presets,
        private readonly StoreManagerInterface $storeManager,
        private readonly LicenceStateInterface $licenceState
    ) {
    }

    public function getValue(string $path, ?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        return trim((string)$this->scopeConfig->getValue(self::SECTION . '/' . $path, $scope->getConfigType(), $scope->id ?: null));
    }

    public function getFlag(string $path, ?Scope $scope = null): bool
    {
        $scope ??= Scope::default();
        return $this->scopeConfig->isSetFlag(self::SECTION . '/' . $path, $scope->getConfigType(), $scope->id ?: null);
    }

    public function getInt(string $path, int $min, int $max, int $default, ?Scope $scope = null): int
    {
        $value = $this->getValue($path, $scope);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }

    /**
     * True when emails of this scope go through the module (setting on and licence not disabled).
     */
    public function isEnabled(?Scope $scope = null): bool
    {
        return $this->getFlag('general/enabled', $scope) && $this->licenceState->isFeatureEnabled(self::PACKAGE);
    }

    public function isEnabledSetting(?Scope $scope = null): bool
    {
        return $this->getFlag('general/enabled', $scope);
    }

    public function getPreset(?Scope $scope = null): Preset
    {
        return $this->presets->get($this->getValue('connection/provider', $scope) ?: PresetRegistry::CUSTOM);
    }

    /**
     * Connection settings for the scope. For OAuth providers the password is empty: the access token is added by
     * ConnectionFactory just before connecting.
     */
    public function getConnectionSettings(?Scope $scope = null): ConnectionSettings
    {
        $preset = $this->getPreset($scope);
        $portValue = $this->getValue('connection/port', $scope);
        $port = ctype_digit($portValue) ? (int)$portValue : $preset->defaultPort;

        if ($preset->isCustom()) {
            $host = $this->getValue('connection/host', $scope);
            $encryption = $this->getValue('connection/encryption', $scope) ?: ConnectionSettings::ENCRYPTION_STARTTLS;
            if (!in_array($encryption, [ConnectionSettings::ENCRYPTION_NONE, ConnectionSettings::ENCRYPTION_STARTTLS, ConnectionSettings::ENCRYPTION_SSL], true)) {
                $encryption = ConnectionSettings::ENCRYPTION_STARTTLS;
            }
            $auth = $this->getValue('connection/auth_method', $scope) ?: ConnectionSettings::AUTH_AUTO;
            if (!in_array($auth, [ConnectionSettings::AUTH_AUTO, ConnectionSettings::AUTH_NONE, ConnectionSettings::AUTH_PLAIN, ConnectionSettings::AUTH_LOGIN, ConnectionSettings::AUTH_CRAM_MD5], true)) {
                $auth = ConnectionSettings::AUTH_AUTO;
            }
            $verify = $this->getFlag('connection/verify_tls', $scope);
        } else {
            $host = $preset->resolveHost($this->getValue('connection/region_' . $preset->code, $scope));
            $encryption = in_array($port, [465, 2465], true)
                ? ConnectionSettings::ENCRYPTION_SSL
                : ConnectionSettings::ENCRYPTION_STARTTLS;
            $auth = $preset->isOAuth() ? ConnectionSettings::AUTH_XOAUTH2 : ConnectionSettings::AUTH_AUTO;
            $verify = true;
        }

        $password = $preset->isOAuth() ? '' : $this->getPassword($scope);
        $username = $this->getValue('connection/username', $scope);
        if ($preset->fixedUsername !== '') {
            $username = $preset->fixedUsername;
        } elseif ($preset->usernameIsPassword) {
            $username = $password;
        }

        return new ConnectionSettings(
            $preset->code,
            $host,
            $port,
            $encryption,
            $auth,
            $username,
            $password,
            $this->getInt('connection/timeout', 5, 120, 20, $scope),
            $verify,
            $this->getHeloName($scope)
        );
    }

    public function getOAuthSettings(?Scope $scope = null): ?OAuthSettings
    {
        $preset = $this->getPreset($scope);
        if (!$preset->isOAuth()) {
            return null;
        }
        $isMicrosoft = $preset->auth === Preset::AUTH_OAUTH_MICROSOFT;
        $flow = $isMicrosoft && $this->getValue('connection/oauth_flow', $scope) === self::OAUTH_FLOW_APP
            ? self::OAUTH_FLOW_APP
            : self::OAUTH_FLOW_DELEGATED;
        return new OAuthSettings(
            $isMicrosoft ? OAuthSettings::MICROSOFT : OAuthSettings::GOOGLE,
            $flow,
            $this->getValue('connection/oauth_client_id', $scope),
            $this->getValue('connection/oauth_client_secret', $scope),
            $isMicrosoft ? ($this->getValue('connection/oauth_tenant', $scope) ?: '') : '',
            $this->getValue('connection/username', $scope)
        );
    }

    public function getFromOverride(?Scope $scope = null): string
    {
        $value = $this->getValue('sender/from_email', $scope);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    public function getDeliveryMode(?Scope $scope = null): string
    {
        $mode = $this->getValue('delivery/mode', $scope);
        return in_array($mode, [self::DELIVERY_REDIRECT, self::DELIVERY_LOG_ONLY], true) ? $mode : self::DELIVERY_LIVE;
    }

    /**
     * @return string[]
     */
    public function getTestRecipients(?Scope $scope = null): array
    {
        return array_values(array_filter(
            self::splitList($this->getValue('delivery/test_recipients', $scope)),
            static fn (string $email): bool => (bool)filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
    }

    /**
     * Addresses (name@example.com) and domains (@example.com or example.com) that always get their emails.
     *
     * @return string[]
     */
    public function getAllowedRecipients(?Scope $scope = null): array
    {
        return array_map('strtolower', self::splitList($this->getValue('delivery/allowed_recipients', $scope)));
    }

    /**
     * Patterns of recipients that never get emails (addresses, domains, * wildcards), lower-case.
     *
     * @return string[]
     */
    public function getBlockedRecipients(?Scope $scope = null): array
    {
        return array_map('strtolower', self::splitList($this->getValue('delivery/blocked_recipients', $scope)));
    }

    public function getSubjectPrefix(?Scope $scope = null): string
    {
        return $this->getValue('delivery/subject_prefix', $scope);
    }

    /**
     * @return string[] host names the store may deliver to real recipients from (empty: no check)
     */
    public function getLiveHosts(?Scope $scope = null): array
    {
        $hosts = [];
        foreach (self::splitList($this->getValue('delivery/live_host', $scope)) as $entry) {
            $host = parse_url(str_contains($entry, '://') ? $entry : 'https://' . $entry, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        return array_values(array_unique($hosts));
    }

    /**
     * True when no delivery rule of the scope holds emails back (live delivery, no blocklist, no active staging
     * lock). Only then may Magento's own transport send an email the module could not handle.
     */
    public function canBypassDeliveryRules(?Scope $scope = null): bool
    {
        if ($this->getDeliveryMode($scope) !== self::DELIVERY_LIVE || $this->getBlockedRecipients($scope) !== []) {
            return false;
        }
        $liveHosts = $this->getLiveHosts($scope);
        return $liveHosts === [] || in_array($this->getStoreHost($scope), $liveHosts, true);
    }

    public function getSendingMode(?Scope $scope = null): string
    {
        return $this->getValue('queue/sending', $scope) === self::SENDING_QUEUE ? self::SENDING_QUEUE : self::SENDING_IMMEDIATE;
    }

    public function isRetryEnabled(): bool
    {
        return $this->getFlag('queue/retry');
    }

    public function getMaxAttempts(): int
    {
        return $this->isRetryEnabled() ? $this->getInt('queue/max_attempts', 1, 10, 4) : 1;
    }

    public function isLogEnabled(): bool
    {
        return $this->getFlag('log/enabled');
    }

    public function isKeepContent(): bool
    {
        return $this->getFlag('log/keep_content');
    }

    public function getRetentionDays(): int
    {
        return $this->getInt('log/retention_days', 0, 3650, 30);
    }

    /**
     * Days the content of sent emails is kept: the own setting, else the log retention (0 = no limit).
     */
    public function getContentRetentionDays(): int
    {
        $value = $this->getValue('log/content_retention_days');
        if ($value === '' || !ctype_digit($value) || (int)$value < 1) {
            return $this->getRetentionDays();
        }
        $days = min(3650, (int)$value);
        $logDays = $this->getRetentionDays();
        return $logDays > 0 ? min($days, $logDays) : $days;
    }

    /**
     * Magento's own "Disable Email Communications" switch (Advanced > System > Mail Sending Settings).
     */
    public function isMagentoMailDisabled(?Scope $scope = null): bool
    {
        $scope ??= Scope::default();
        return $this->scopeConfig->isSetFlag('system/smtp/disable', $scope->getConfigType(), $scope->id ?: null);
    }

    /**
     * Envelope sender from Magento's "Set Return-Path" setting: '' = use the sender, or a fixed address.
     */
    public function getReturnPathEmail(?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        $mode = (int)$this->scopeConfig->getValue('system/smtp/set_return_path', $scope->getConfigType(), $scope->id ?: null);
        if ($mode !== 2) {
            return '';
        }
        $email = trim((string)$this->scopeConfig->getValue('system/smtp/return_path_email', $scope->getConfigType(), $scope->id ?: null));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Store sender used to suggest a domain for the DNS check.
     */
    public function getStoreSenderEmail(?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        $override = $this->getFromOverride($scope);
        if ($override !== '') {
            return $override;
        }
        return trim((string)$this->scopeConfig->getValue('trans_email/ident_general/email', $scope->getConfigType(), $scope->id ?: null));
    }

    public function getStoreSenderName(?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        return trim((string)$this->scopeConfig->getValue('trans_email/ident_general/name', $scope->getConfigType(), $scope->id ?: null));
    }

    /**
     * Host name of the store, used in EHLO and generated Message-IDs.
     */
    public function getHeloName(?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        $url = (string)$this->scopeConfig->getValue('web/unsecure/base_url', $scope->getConfigType(), $scope->id ?: null);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return 'localhost';
        }
        return strtolower($host);
    }

    /**
     * Base URL host of the store view (for the staging safety check).
     */
    public function getStoreHost(?Scope $scope = null): string
    {
        $scope ??= Scope::default();
        try {
            if ($scope->isStore()) {
                $store = $this->storeManager->getStore($scope->id);
                $url = $store instanceof \Magento\Store\Model\Store
                    ? $store->getBaseUrl()
                    : (string)$this->scopeConfig->getValue('web/unsecure/base_url', $scope->getConfigType(), $scope->id);
            } else {
                $url = (string)$this->scopeConfig->getValue('web/unsecure/base_url', $scope->getConfigType(), $scope->id ?: null);
            }
        } catch (\Exception) {
            return '';
        }
        $host = parse_url((string)$url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Encrypted fields are declared with their backend model in config.xml, so Magento decrypts them on load.
     */
    private function getPassword(?Scope $scope): string
    {
        $scope ??= Scope::default();
        // Not trimmed: spaces can be part of a password.
        return (string)$this->scopeConfig->getValue(self::SECTION . '/connection/password', $scope->getConfigType(), $scope->id ?: null);
    }

    /**
     * @return string[]
     */
    public static function splitList(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== '')));
    }
}
