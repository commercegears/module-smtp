<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\OAuth;

use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Talks to the Google and Microsoft identity platforms: authorisation URL (with PKCE), code exchange, refresh and
 * the Microsoft client credentials flow. Errors are thrown as SmtpException (stage "token") with a readable text;
 * tokens are never logged.
 */
class OAuthClient
{
    public const GOOGLE_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
    public const GOOGLE_SCOPE = 'https://mail.google.com/';
    public const MICROSOFT_LOGIN = 'https://login.microsoftonline.com/';
    public const MICROSOFT_DELEGATED_SCOPE = 'offline_access https://outlook.office.com/SMTP.Send';
    public const MICROSOFT_APP_SCOPE = 'https://outlook.office365.com/.default';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json
    ) {
    }

    public function getAuthorizeUrl(OAuthSettings $settings, string $redirectUri, string $state, string $codeChallenge): string
    {
        $params = [
            'client_id' => $settings->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        if ($settings->username !== '') {
            $params['login_hint'] = $settings->username;
        }
        if ($settings->provider === OAuthSettings::GOOGLE) {
            $params += ['scope' => self::GOOGLE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent'];
            return self::GOOGLE_AUTHORIZE . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        $params += ['scope' => self::MICROSOFT_DELEGATED_SCOPE, 'response_mode' => 'query', 'prompt' => 'select_account'];
        return self::MICROSOFT_LOGIN . rawurlencode($settings->getTenantForUrl()) . '/oauth2/v2.0/authorize?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws SmtpException
     */
    public function exchangeCode(OAuthSettings $settings, string $code, string $redirectUri, string $codeVerifier): array
    {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $settings->clientId,
            'client_secret' => $settings->clientSecret,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];
        if ($settings->provider === OAuthSettings::MICROSOFT) {
            $params['scope'] = self::MICROSOFT_DELEGATED_SCOPE;
        }
        return $this->request($settings, $params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws SmtpException
     */
    public function refresh(OAuthSettings $settings, #[\SensitiveParameter] string $refreshToken): array
    {
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $settings->clientId,
            'client_secret' => $settings->clientSecret,
        ];
        if ($settings->provider === OAuthSettings::MICROSOFT) {
            $params['scope'] = self::MICROSOFT_DELEGATED_SCOPE;
        }
        return $this->request($settings, $params);
    }

    /**
     * Microsoft app-only token (client credentials).
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws SmtpException
     */
    public function clientCredentials(OAuthSettings $settings): array
    {
        return $this->request($settings, [
            'grant_type' => 'client_credentials',
            'client_id' => $settings->clientId,
            'client_secret' => $settings->clientSecret,
            'scope' => self::MICROSOFT_APP_SCOPE,
        ]);
    }

    public function getTokenUrl(OAuthSettings $settings): string
    {
        if ($settings->provider === OAuthSettings::GOOGLE) {
            return self::GOOGLE_TOKEN;
        }
        return self::MICROSOFT_LOGIN . rawurlencode($settings->getTenantForUrl()) . '/oauth2/v2.0/token';
    }

    /**
     * @param array<string, string> $params
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws SmtpException
     */
    private function request(OAuthSettings $settings, array $params): array
    {
        $name = $settings->provider === OAuthSettings::GOOGLE ? 'Google' : 'Microsoft';
        $curl = $this->curlFactory->create();
        $curl->setTimeout(15);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->addHeader('Accept', 'application/json');
        try {
            $curl->post($this->getTokenUrl($settings), $params);
        } catch (\Exception $e) {
            throw new SmtpException(
                (string)__('%1 could not be reached to get an access token: %2', $name, $e->getMessage()),
                SmtpException::STAGE_TOKEN
            );
        }
        $status = $curl->getStatus();
        try {
            $data = $this->json->unserialize((string)$curl->getBody());
        } catch (\InvalidArgumentException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new SmtpException(
                (string)__('%1 sent an unexpected answer (HTTP %2) when asked for an access token.', $name, $status),
                SmtpException::STAGE_TOKEN
            );
        }
        if ($status >= 400 || empty($data['access_token']) || !is_string($data['access_token'])) {
            $error = is_string($data['error'] ?? null) ? $data['error'] : 'unknown_error';
            $description = is_string($data['error_description'] ?? null) ? $data['error_description'] : '';
            throw new SmtpException(
                $this->describeError($name, $error, $description),
                SmtpException::STAGE_TOKEN,
                0,
                $error . ($description !== '' ? ': ' . strtok($description, "\r\n") : '')
            );
        }
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : '',
            'expires_in' => max(60, (int)($data['expires_in'] ?? 3600)),
        ];
    }

    private function describeError(string $name, string $error, string $description): string
    {
        $aadCode = preg_match('/\b(AADSTS\d+)\b/', $description, $m) === 1 ? $m[1] : '';
        return match (true) {
            $error === 'invalid_grant' => (string)__('%1 no longer accepts the saved sign-in (it expired or was revoked). Click Connect Account again.', $name),
            $error === 'invalid_client' || $aadCode === 'AADSTS7000215' => (string)__('%1 rejected the client ID or client secret. Check them (a Microsoft client secret value, not its ID) and save.', $name),
            $error === 'unauthorized_client' => (string)__('%1 does not allow this app to use this sign-in type. Check the app registration.', $name),
            $aadCode === 'AADSTS700016' => (string)__('Microsoft cannot find the app in this tenant. Check the client ID and tenant ID.'),
            $aadCode === 'AADSTS90002' => (string)__('Microsoft cannot find the tenant. Check the tenant ID.'),
            $aadCode === 'AADSTS50011' => (string)__('The redirect URI does not match the app registration. Add the redirect URI shown in the settings.'),
            $error === 'invalid_scope' => (string)__('%1 rejected the requested permission. Microsoft app-only: grant SMTP.SendAsApp with admin consent.', $name),
            default => (string)__('%1 refused the access token request (%2).', $name, $aadCode !== '' ? $aadCode : $error),
        };
    }
}
