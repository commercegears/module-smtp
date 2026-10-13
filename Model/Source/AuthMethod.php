<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AuthMethod implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic')],
            ['value' => 'plain', 'label' => __('PLAIN')],
            ['value' => 'login', 'label' => __('LOGIN')],
            ['value' => 'cram-md5', 'label' => __('CRAM-MD5')],
            ['value' => 'none', 'label' => __('None (no sign-in)')],
        ];
    }
}
