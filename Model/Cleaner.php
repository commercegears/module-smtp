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
 * Deletes log entries older than the retention period and email content that is no longer needed (queued emails
 * are kept).
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

    /**
     * Deletes stored email content (personal data, links) that is no longer needed: all of it when "Keep Email
     * Content" is off, else what is older than the content retention. Queued emails keep theirs. Returns the
     * number of emails whose content was deleted.
     */
    public function cleanContent(): int
    {
        if (!$this->config->isLogEnabled() || !$this->config->isKeepContent()) {
            return $this->logResource->deleteFinishedContent();
        }
        $days = $this->config->getContentRetentionDays();
        if ($days <= 0) {
            return 0;
        }
        return $this->logResource->deleteFinishedContent($this->dateTime->gmtDate('Y-m-d H:i:s', time() - $days * 86400));
    }
}
