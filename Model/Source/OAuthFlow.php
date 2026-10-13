<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OAuthFlow implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'delegated', 'label' => __('Sign in with the mailbox (delegated)')],
            ['value' => 'app', 'label' => __('App-only (client credentials, no sign-in)')],
        ];
    }
}
