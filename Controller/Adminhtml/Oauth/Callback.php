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
use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * Where Google or Microsoft send the admin back to. The URL has no admin secret key (it must match the registered
 * redirect URI exactly), so it is protected by the admin session, the ACL and the one-time state with PKCE.
 */
class Callback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::config';
    private const MAX_AGE = 900;

    /**
     * @var string[]
     */
    protected $_publicActions = ['callback'];

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly OAuthClient $client,
        private readonly RedirectUri $redirectUri,
        private readonly TokenStorage $tokenStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $pending = $this->_session->getData(Connect::SESSION_KEY);
        $this->_session->unsetData(Connect::SESSION_KEY);

        if (!is_array($pending) || empty($pending['state'])) {
            $this->messageManager->addErrorMessage(__('The sign-in could not be matched to a request from this admin session. Click Connect Account again.'));
            return $redirect->setUrl($this->getUrl('adminhtml/system_config/edit', ['section' => Config::SECTION]));
        }
        $scope = $this->restoreScope($pending);
        $back = Connect::configUrl($this->_url, $scope);

        $state = (string)$this->getRequest()->getParam('state');
        if ($state === '' || !hash_equals((string)$pending['state'], $state) || time() - (int)$pending['created'] > self::MAX_AGE) {
            $this->messageManager->addErrorMessage(__('The sign-in expired or did not match. Click Connect Account again.'));
            return $redirect->setUrl($back);
        }
        $error = (string)$this->getRequest()->getParam('error');
        if ($error !== '') {
            $description = (string)$this->getRequest()->getParam('error_description');
            $this->messageManager->addErrorMessage(__(
                'The provider did not grant access (%1). %2',
                mb_substr($error, 0, 60),
                mb_substr((string)strtok($description, "\r\n"), 0, 300)
            ));
            return $redirect->setUrl($back);
        }

        $settings = $this->config->getOAuthSettings($scope);
        if ($settings === null || !hash_equals((string)$pending['fingerprint'], $settings->getFingerprint())) {
            $this->messageManager->addErrorMessage(__('The OAuth settings changed during the sign-in. Save them and click Connect Account again.'));
            return $redirect->setUrl($back);
        }
        $code = (string)$this->getRequest()->getParam('code');
        if ($code === '') {
            $this->messageManager->addErrorMessage(__('The provider did not send an authorisation code.'));
            return $redirect->setUrl($back);
        }

        try {
            $tokens = $this->client->exchangeCode($settings, $code, $this->redirectUri->get(), (string)$pending['verifier']);
        } catch (SmtpException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setUrl($back);
        }
        if ($tokens['refresh_token'] === '') {
            $this->messageManager->addErrorMessage(__('The provider did not return a long-lived sign-in (refresh token). Remove the app\'s access in your account security settings and connect again.'));
            return $redirect->setUrl($back);
        }
        $this->tokenStorage->save($scope, $settings, $tokens['refresh_token'], $settings->username);
        $this->messageManager->addSuccessMessage(__('Connected %1. Click "Check Connection" to test the SMTP sign-in.', $settings->username));
        return $redirect->setUrl($back);
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function restoreScope(array $pending): Scope
    {
        $type = (string)($pending['scope_type'] ?? '');
        $id = (int)($pending['scope_id'] ?? 0);
        if ($type === Scope::store(1)->type) {
            return Scope::store($id);
        }
        if ($type === Scope::website(1)->type) {
            return Scope::website($id);
        }
        return Scope::default();
    }
}
