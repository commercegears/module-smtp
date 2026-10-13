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
 * SMTP host: trims "smtp://", "https://" and paths that are often pasted in, and checks the result.
 */
class Host extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = strtolower(trim((string)$this->getValue()));
        $value = (string)preg_replace('#^[a-z]+://#', '', $value);
        $value = rtrim((string)strtok($value, '/'), '.');
        if (str_contains($value, ':') && !str_contains($value, '[')) {
            $value = (string)strtok($value, ':');
        }
        if ($value !== '' && preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)$/', $value) !== 1) {
            throw new LocalizedException(__('"%1" is not a valid SMTP host name.', $this->getValue()));
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
