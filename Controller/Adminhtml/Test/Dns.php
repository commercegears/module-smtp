<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Test;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Diagnostics\DnsChecker;
use Magento\Backend\App\Action\Context;

/**
 * SPF, DKIM, DMARC and MX check of a sender domain.
 */
class Dns extends AbstractTest
{
    public function __construct(
        Context $context,
        private readonly DnsChecker $checker,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $scope = $this->getScope();
        $domain = (string)$this->getRequest()->getParam('domain');
        $selectors = Config::splitList((string)$this->getRequest()->getParam('selector'));
        $checks = $this->checker->check($domain, $this->config->getPreset($scope), array_slice($selectors, 0, 5));
        $labels = [
            DnsChecker::OK => (string)__('OK'),
            DnsChecker::WARNING => (string)__('Check'),
            DnsChecker::ERROR => (string)__('Problem'),
            DnsChecker::INFO => (string)__('Info'),
        ];
        foreach ($checks as &$check) {
            $check['label'] = $labels[$check['status']] ?? $check['status'];
        }
        unset($check);
        $normalised = DnsChecker::normaliseDomain($domain);
        return $this->json([
            'message' => $normalised !== ''
                ? (string)__('Results for %1 (provider: %2).', $normalised, $this->config->getPreset($scope)->label)
                : '',
            'checks' => $checks,
        ]);
    }
}
