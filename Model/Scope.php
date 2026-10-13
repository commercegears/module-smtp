<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * A configuration scope (default, website or store view). Emails use the store view they belong to; the admin
 * test buttons use the scope that is open in Stores > Configuration.
 */
class Scope
{
    private function __construct(
        public readonly string $type,
        public readonly int $id
    ) {
    }

    public static function store(int $storeId): self
    {
        return $storeId > 0 ? new self(ScopeInterface::SCOPE_STORES, $storeId) : self::default();
    }

    public static function website(int $websiteId): self
    {
        return $websiteId > 0 ? new self(ScopeInterface::SCOPE_WEBSITES, $websiteId) : self::default();
    }

    /**
     * Scope from the "store" / "website" parameters of the configuration page.
     */
    public static function fromParams(mixed $store, mixed $website): self
    {
        if (is_numeric($store) && (int)$store > 0) {
            return self::store((int)$store);
        }
        if (is_numeric($website) && (int)$website > 0) {
            return self::website((int)$website);
        }
        return self::default();
    }

    public static function default(): self
    {
        return new self(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    /**
     * Scope type as ScopeConfigInterface::getValue() expects it.
     */
    public function getConfigType(): string
    {
        return match ($this->type) {
            ScopeInterface::SCOPE_STORES => ScopeInterface::SCOPE_STORE,
            ScopeInterface::SCOPE_WEBSITES => ScopeInterface::SCOPE_WEBSITE,
            default => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        };
    }

    public function isStore(): bool
    {
        return $this->type === ScopeInterface::SCOPE_STORES;
    }

    public function isWebsite(): bool
    {
        return $this->type === ScopeInterface::SCOPE_WEBSITES;
    }

    public function getStoreId(): ?int
    {
        return $this->isStore() ? $this->id : null;
    }

    public function toString(): string
    {
        return $this->type . ':' . $this->id;
    }
}
