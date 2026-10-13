<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Microsoft tenant: a GUID, a domain, or one of common/organizations/consumers.
 */
class Tenant extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '' && preg_match('/^[A-Za-z0-9.-]{2,100}$/', $value) !== 1) {
            throw new LocalizedException(__('The tenant ID must be a directory ID (GUID) or a domain such as example.onmicrosoft.com.'));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
