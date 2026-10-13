<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Oauth;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\OAuth\OAuthClient;
use CommerceGears\Smtp\Model\OAuth\RedirectUri;
use CommerceGears\Smtp\Model\Scope;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * Starts the OAuth sign-in: remembers state and PKCE verifier in the admin session, then sends the admin to Google
 * or Microsoft.
 */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::config';
    public const SESSION_KEY = 'commercegears_smtp_oauth';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly OAuthClient $client,
        private readonly RedirectUri $redirectUri
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $scope = Scope::fromParams($this->getRequest()->getParam('store'), $this->getRequest()->getParam('website'));
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $settings = $this->config->getOAuthSettings($scope);
        if ($settings === null) {
            $this->messageManager->addErrorMessage(__('Choose an OAuth provider and save the settings first.'));
            return $redirect->setUrl(self::configUrl($this->_url, $scope));
        }
        if ($settings->isAppOnly()) {
            $this->messageManager->addNoticeMessage(__('App-only sign-in does not need a connected account. Use "Check Connection".'));
            return $redirect->setUrl(self::configUrl($this->_url, $scope));
        }
        if (!$settings->isComplete() || $settings->username === '') {
            $this->messageManager->addErrorMessage(__('Enter and save the OAuth client ID, client secret and username before connecting.'));
            return $redirect->setUrl(self::configUrl($this->_url, $scope));
        }

        $verifier = self::base64Url(random_bytes(48));
        $state = bin2hex(random_bytes(24));
        $this->_session->setData(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'fingerprint' => $settings->getFingerprint(),
            'created' => time(),
        ]);
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        return $redirect->setUrl($this->client->getAuthorizeUrl($settings, $this->redirectUri->get(), $state, $challenge));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function configUrl(\Magento\Framework\UrlInterface $url, Scope $scope): string
    {
        $params = ['section' => Config::SECTION];
        if ($scope->isStore()) {
            $params['store'] = $scope->id;
        } elseif ($scope->isWebsite()) {
            $params['website'] = $scope->id;
        }
        return $url->getUrl('adminhtml/system_config/edit', $params);
    }
}
