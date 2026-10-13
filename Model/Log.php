<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * One email in the log (and queue).
 */
class Log extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @var string
     */
    protected $_eventPrefix = 'commercegears_smtp_log';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\Log::class);
    }
}
