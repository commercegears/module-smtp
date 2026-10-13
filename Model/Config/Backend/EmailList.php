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
 * Comma separated email addresses; every one must be valid.
 */
class EmailList extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$this->getValue()) ?: [])));
        foreach ($parts as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new LocalizedException(__('"%1" is not a valid email address.', $email));
            }
        }
        $this->setValue(implode(', ', array_unique($parts)));
        return parent::beforeSave();
    }
}
