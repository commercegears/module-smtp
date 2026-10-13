<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Source;

use CommerceGears\Smtp\Model\EmailType\Resolver;
use Magento\Framework\Data\OptionSourceInterface;

class EmailType implements OptionSourceInterface
{
    public function __construct(
        private readonly Resolver $resolver
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return $this->resolver->getOptions();
    }
}
