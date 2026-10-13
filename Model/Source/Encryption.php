<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Encryption implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'tls', 'label' => __('STARTTLS (usually port 587)')],
            ['value' => 'ssl', 'label' => __('SSL/TLS (usually port 465)')],
            ['value' => 'none', 'label' => __('None (not recommended)')],
        ];
    }
}
