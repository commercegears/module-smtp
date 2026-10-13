<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\System\Message;

use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;

/**
 * Admin notice when emails failed in the last 24 hours or an OAuth sign-in stopped working. Counted at most every
 * five minutes, and only shown to admins who may see the email log.
 */
class DeliveryProblem implements MessageInterface
{
    private const CACHE_KEY = 'commercegears_smtp_problem_counts';

    /** @var array{failed: int, retry: int, oauth: int}|null */
    private ?array $counts = null;

    public function __construct(
        private readonly LogResource $logResource,
        private readonly TokenStorage $tokenStorage,
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getIdentity()
    {
        $counts = $this->getCounts();
        return 'commercegears_smtp_problem_' . md5(implode('-', $counts));
    }

    public function isDisplayed()
    {
        if (!$this->authorization->isAllowed('CommerceGears_Smtp::log')) {
            return false;
        }
        $counts = $this->getCounts();
        return $counts['failed'] > 0 || $counts['oauth'] > 0;
    }

    public function getText()
    {
        $counts = $this->getCounts();
        $parts = [];
        if ($counts['failed'] > 0) {
            $parts[] = (string)__('%1 email(s) could not be sent in the last 24 hours.', $counts['failed']);
        }
        if ($counts['oauth'] > 0) {
            $parts[] = (string)__('An OAuth sign-in for SMTP stopped working: reconnect the account in the SMTP settings.');
        }
        $url = $this->urlBuilder->getUrl('commercegears_smtp/log/index');
        return 'CommerceGears SMTP: ' . htmlspecialchars(implode(' ', $parts), ENT_QUOTES)
            . ' <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars((string)__('Open the email log'), ENT_QUOTES) . '</a>';
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * @return array{failed: int, retry: int, oauth: int}
     */
    private function getCounts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }
        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && preg_match('/^(\d+)-(\d+)-(\d+)$/', $cached, $m) === 1) {
            return $this->counts = ['failed' => (int)$m[1], 'retry' => (int)$m[2], 'oauth' => (int)$m[3]];
        }
        try {
            $byStatus = $this->logResource->countByStatusSince($this->dateTime->gmtDate('Y-m-d H:i:s', time() - 86400));
            $oauth = $this->tokenStorage->countWithErrors();
        } catch (\Exception) {
            $byStatus = [];
            $oauth = 0;
        }
        $this->counts = [
            'failed' => $byStatus[Log::STATUS_FAILED] ?? 0,
            'retry' => $byStatus[Log::STATUS_RETRY] ?? 0,
            'oauth' => $oauth,
        ];
        $this->cache->save(implode('-', $this->counts), self::CACHE_KEY, [], 300);
        return $this->counts;
    }
}
