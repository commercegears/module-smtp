<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\Log;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Counts of the last 24 hours above the email log.
 */
class Summary extends Template
{
    public function __construct(
        Context $context,
        private readonly LogResource $logResource,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{label: string, count: int, status: string}>
     */
    public function getTiles(): array
    {
        $counts = $this->logResource->countByStatusSince($this->dateTime->gmtDate('Y-m-d H:i:s', time() - 86400));
        return [
            ['label' => (string)__('Sent'), 'count' => $counts[Log::STATUS_SENT] ?? 0, 'status' => 'sent'],
            ['label' => (string)__('Queued or waiting to retry'), 'count' => ($counts[Log::STATUS_PENDING] ?? 0) + ($counts[Log::STATUS_RETRY] ?? 0) + ($counts[Log::STATUS_SENDING] ?? 0), 'status' => 'retry'],
            ['label' => (string)__('Failed'), 'count' => $counts[Log::STATUS_FAILED] ?? 0, 'status' => 'failed'],
            ['label' => (string)__('Not sent'), 'count' => $counts[Log::STATUS_SKIPPED] ?? 0, 'status' => 'skipped'],
        ];
    }

    public function isLogEnabled(): bool
    {
        return $this->config->isLogEnabled();
    }

    public function canConfigure(): bool
    {
        return $this->_authorization->isAllowed('CommerceGears_Smtp::config');
    }

    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => Config::SECTION]);
    }
}
