<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DeliveryMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'live', 'label' => __('Send to the real recipients')],
            ['value' => 'redirect', 'label' => __('Send everything to the test addresses')],
            ['value' => 'log_only', 'label' => __('Do not send, only log')],
        ];
    }
}
