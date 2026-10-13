<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use CommerceGears\Smtp\Model\Provider\PresetRegistry;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Regions or data centres of one provider (configured per provider in di.xml).
 */
class Region implements OptionSourceInterface
{
    public function __construct(
        private readonly PresetRegistry $presets,
        private readonly string $provider = PresetRegistry::SES
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->presets->get($this->provider)->regions as $code => $label) {
            $options[] = ['value' => (string)$code, 'label' => $label . ($label !== $code && $this->provider === PresetRegistry::SES ? ' (' . $code . ')' : '')];
        }
        return $options;
    }
}
