<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Warnings at the top of the settings: Magento's mail switch is off, test delivery mode, OAuth sign-in failing,
 * queued emails waiting because cron does not run.
 */
class Notices extends FullWidthField
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly TokenStorage $tokenStorage,
        private readonly LogResource $logResource,
        private readonly DateTime $dateTime,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $scope = $this->getScope();
        $notices = [];
        if ($this->config->isMagentoMailDisabled($scope)) {
            $url = $this->getUrl('adminhtml/system_config/edit', ['section' => 'system'] + $this->getScopeParams());
            $notices[] = ['error', (string)__('Magento\'s "Disable Email Communications" is on for this scope (Advanced > System > Mail Sending Settings). Store emails are not sent; with this module on they are logged as "Not sent".')
                . ' <a href="' . $this->escapeUrl($url) . '">' . $this->escapeHtml(__('Open Mail Sending Settings')) . '</a>'];
        }
        $mode = $this->config->getDeliveryMode($scope);
        if ($mode === Config::DELIVERY_REDIRECT) {
            $notices[] = ['warning', $this->escapeHtml(__('Delivery is set to "Send everything to the test addresses". Customers do not get emails.'))];
        } elseif ($mode === Config::DELIVERY_LOG_ONLY) {
            $notices[] = ['warning', $this->escapeHtml(__('Delivery is set to "Do not send, only log". No emails leave the store.'))];
        }
        $liveHosts = $this->config->getLiveHosts($scope);
        $storeHost = $this->config->getStoreHost($scope);
        if ($liveHosts !== [] && !in_array($storeHost, $liveHosts, true)) {
            $notices[] = ['warning', $this->escapeHtml(__('Staging safety lock is active: %1 is not the live domain (%2), so emails go to the test addresses or are only logged.', $storeHost !== '' ? $storeHost : __('the store address'), implode(', ', $liveHosts)))];
        }
        if ($this->config->getPreset($scope)->isOAuth()) {
            $row = $this->tokenStorage->findForScope($scope);
            if ($row !== null && !empty($row['last_error'])) {
                $notices[] = ['error', $this->escapeHtml(__('The OAuth sign-in failed: %1', (string)$row['last_error']))];
            }
        }
        $waiting = $this->countStuckQueue();
        if ($waiting > 0) {
            $notices[] = ['warning', $this->escapeHtml(__('%1 queued email(s) have been waiting for more than 15 minutes. Check that Magento cron runs (bin/magento cron:run every minute).', $waiting))];
        }
        if ($notices === []) {
            return '';
        }
        $html = '';
        foreach ($notices as [$type, $text]) {
            $html .= '<div class="message message-' . $type . ' commercegears-smtp-notice">' . $text . '</div>';
        }
        return $html;
    }

    private function countStuckQueue(): int
    {
        $connection = $this->logResource->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->logResource->getMainTable(), ['COUNT(*)'])
                ->where('status IN (?)', [Log::STATUS_PENDING, Log::STATUS_RETRY])
                ->where('next_attempt_at < ?', $this->dateTime->gmtDate('Y-m-d H:i:s', time() - 900))
        );
    }
}
