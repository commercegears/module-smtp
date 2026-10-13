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
 * Comma separated host names (URLs are reduced to their host).
 */
class HostList extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $hosts = [];
        foreach (array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$this->getValue()) ?: [])) as $entry) {
            $host = parse_url(str_contains($entry, '://') ? $entry : 'https://' . $entry, PHP_URL_HOST);
            if (!is_string($host) || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
                throw new LocalizedException(__('"%1" is not a valid domain.', $entry));
            }
            $hosts[] = strtolower($host);
        }
        $this->setValue(implode(', ', array_unique($hosts)));
        return parent::beforeSave();
    }
}
