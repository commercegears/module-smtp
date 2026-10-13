<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Config\Backend;

use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * SMTP port: empty (the provider's usual port) or 1 to 65535, but not a port of a service that is never SMTP.
 */
class Port extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());
        if ($value !== '') {
            if (!ctype_digit($value) || (int)$value < 1 || (int)$value > 65535) {
                throw new LocalizedException(__('The port must be a number between 1 and 65535.'));
            }
            if (ConnectionSettings::isNonMailPort((int)$value)) {
                throw new LocalizedException(__('Port %1 is not an email port. Use the SMTP port of your provider: usually 587 (STARTTLS) or 465 (SSL/TLS), or 25 or 2525.', (int)$value));
            }
            $value = (string)(int)$value;
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
