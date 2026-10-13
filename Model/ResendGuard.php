<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Decides whether a logged email may be sent again: only while the module is on for the email's store view, and
 * never for a store view that was deleted (its settings no longer exist).
 */
class ResendGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The reason the email cannot be sent again, or null when it can.
     */
    public function getBlocker(mixed $storeId): ?string
    {
        $storeId = $storeId !== null && $storeId !== '' ? (int)$storeId : 0;
        $storeName = (string)__('Admin / default');
        if ($storeId > 0) {
            try {
                $storeName = (string)$this->storeManager->getStore($storeId)->getName();
            } catch (NoSuchEntityException) {
                return (string)__('The store view of this email no longer exists, so it cannot be sent again.');
            }
        }
        if (!$this->config->isEnabled(Scope::store($storeId))) {
            return (string)__('SMTP is switched off for the store view of this email (%1), so it cannot be sent again. Switch the module on for that store view first.', $storeName);
        }
        return null;
    }
}
