<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\ResourceModel\Log;

use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'log_id';

    protected function _construct(): void
    {
        $this->_init(Log::class, LogResource::class);
    }
}
