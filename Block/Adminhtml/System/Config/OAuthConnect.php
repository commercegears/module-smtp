<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\Scope;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Connection state of the OAuth account plus Connect / Disconnect buttons.
 */
class OAuthConnect extends AbstractField
{
    /**
     * @var string
     */
    protected $_template = 'CommerceGears_Smtp::system/config/oauth-connect.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly TokenStorage $tokenStorage,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('element', $element);
        return $this->_toHtml();
    }

    /**
     * @return array{connected: bool, account: string, when: string, inherited: string, error: string, matches: bool}
     */
    public function getStatus(): array
    {
        $scope = $this->getScope();
        $row = $this->tokenStorage->findForScope($scope);
        if ($row === null) {
            return ['connected' => false, 'account' => '', 'when' => '', 'inherited' => '', 'error' => '', 'matches' => false];
        }
        $inherited = '';
        if ((string)$row['scope'] !== $scope->type || (int)$row['scope_id'] !== $scope->id) {
            $inherited = (string)__('Inherited from %1', $this->describeScope((string)$row['scope'], (int)$row['scope_id']));
        }
        $oauth = $this->config->getOAuthSettings($scope);
        $when = $row['connected_at'] ? $this->timezone->formatDateTime((string)$row['connected_at'], \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) : '';
        return [
            'connected' => true,
            'account' => (string)$row['account'],
            'when' => $when,
            'inherited' => $inherited,
            'error' => (string)($row['last_error'] ?? ''),
            'matches' => $oauth !== null && hash_equals((string)$row['fingerprint'], $oauth->getFingerprint()),
        ];
    }

    public function getConnectUrl(): string
    {
        return $this->getUrl('commercegears_smtp/oauth/connect', $this->getScopeParams());
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('commercegears_smtp/oauth/disconnect');
    }

    public function isAppOnly(): bool
    {
        $oauth = $this->config->getOAuthSettings($this->getScope());
        return $oauth !== null && $oauth->isAppOnly();
    }

    private function describeScope(string $type, int $id): string
    {
        $scope = $type === Scope::default()->type ? Scope::default() : ($type === 'websites' ? Scope::website($id) : Scope::store($id));
        try {
            if ($scope->isStore()) {
                return (string)__('store view %1', $this->_storeManager->getStore($id)->getName());
            }
            if ($scope->isWebsite()) {
                return (string)__('website %1', $this->_storeManager->getWebsite($id)->getName());
            }
        } catch (\Exception) {
            return $type . ' ' . $id;
        }
        return (string)__('Default Config');
    }
}
