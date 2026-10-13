<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Diagnostics\DnsChecker;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Send Test Email", "Check Connection" and "Check DNS Records" panel.
 */
class Diagnostics extends FullWidthField
{
    /**
     * @var string
     */
    protected $_template = 'CommerceGears_Smtp::system/config/diagnostics.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Session $authSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('element', $element);
        return $this->_toHtml();
    }

    public function getDefaultRecipient(): string
    {
        $user = $this->authSession->getUser();
        return $user ? (string)$user->getEmail() : '';
    }

    public function getSenderDomain(): string
    {
        return DnsChecker::normaliseDomain($this->config->getStoreSenderEmail($this->getScope()));
    }

    public function getSenderEmail(): string
    {
        return $this->config->getStoreSenderEmail($this->getScope());
    }

    public function isModuleEnabled(): bool
    {
        return $this->config->isEnabledSetting($this->getScope());
    }

    /**
     * @return array<string, string>
     */
    public function getUrls(): array
    {
        return [
            'test' => $this->getUrl('commercegears_smtp/test/send'),
            'connection' => $this->getUrl('commercegears_smtp/test/connection'),
            'dns' => $this->getUrl('commercegears_smtp/test/dns'),
            'log' => $this->getUrl('commercegears_smtp/log/index'),
        ];
    }

    public function getScopeLabel(): string
    {
        $scope = $this->getScope();
        try {
            if ($scope->isStore()) {
                return (string)__('Store view: %1', $this->_storeManager->getStore($scope->id)->getName());
            }
            if ($scope->isWebsite()) {
                return (string)__('Website: %1', $this->_storeManager->getWebsite($scope->id)->getName());
            }
        } catch (\Exception) {
            return $scope->toString();
        }
        return (string)__('Default Config');
    }
}
