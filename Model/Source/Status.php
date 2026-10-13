<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use CommerceGears\Smtp\Model\Log;
use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Log::STATUS_SENT, 'label' => __('Sent')],
            ['value' => Log::STATUS_PENDING, 'label' => __('Queued')],
            ['value' => Log::STATUS_SENDING, 'label' => __('Sending')],
            ['value' => Log::STATUS_RETRY, 'label' => __('Waiting to retry')],
            ['value' => Log::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => Log::STATUS_SKIPPED, 'label' => __('Not sent')],
        ];
    }
}
