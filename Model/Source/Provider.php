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

class Provider implements OptionSourceInterface
{
    public function __construct(
        private readonly PresetRegistry $presets
    ) {
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->presets->getAll() as $preset) {
            $options[] = ['value' => $preset->code, 'label' => __($preset->label)];
        }
        return $options;
    }
}
