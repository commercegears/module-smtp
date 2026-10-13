<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Cron;

use CommerceGears\Smtp\Model\Mailer;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Sends queued emails and retries failed ones (every minute; one run at a time).
 */
class ProcessQueue
{
    private const LOCK = 'commercegears_smtp_queue';

    public function __construct(
        private readonly Mailer $mailer,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return;
        }
        try {
            $this->mailer->processQueue(200);
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }
}
