<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SendingMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'immediate', 'label' => __('Immediately')],
            ['value' => 'queue', 'label' => __('In the background (by cron, within a minute)')],
        ];
    }
}
