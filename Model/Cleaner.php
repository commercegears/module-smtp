<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Deletes log entries older than the retention period (queued emails are kept).
 */
class Cleaner
{
    public function __construct(
        private readonly Config $config,
        private readonly LogResource $logResource,
        private readonly DateTime $dateTime
    ) {
    }

    public function clean(?int $days = null): int
    {
        $days ??= $this->config->getRetentionDays();
        if ($days <= 0) {
            return 0;
        }
        return $this->logResource->deleteOlderThan($this->dateTime->gmtDate('Y-m-d H:i:s', time() - $days * 86400));
    }
}
