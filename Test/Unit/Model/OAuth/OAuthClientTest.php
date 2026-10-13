<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\OAuth;

use CommerceGears\Smtp\Model\OAuth\OAuthClient;
use CommerceGears\Smtp\Model\OAuth\OAuthSettings;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class OAuthClientTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $captured
     */
    private function client(int $status, string $body, ?array &$captured = null): OAuthClient
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        $curl->method('post')->willReturnCallback(static function (string $url, array $params) use (&$captured): void {
            $captured = ['url' => $url, 'params' => $params];
        });
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        return new OAuthClient($factory, new Json());
    }

    private function microsoft(string $flow = 'delegated'): OAuthSettings
    {
        return new OAuthSettings(OAuthSettings::MICROSOFT, $flow, 'cid', 'secret', 'contoso.onmicrosoft.com', 'box@contoso.com');
    }

    public function testAuthorizeUrls(): void
    {
        $client = $this->client(200, '{}');
        $google = $client->getAuthorizeUrl(new OAuthSettings(OAuthSettings::GOOGLE, 'delegated', 'gid', 's', '', 'me@gmail.com'), 'https://shop/admin/cb/', 'st', 'ch');
        parse_str((string)parse_url($google, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(OAuthClient::GOOGLE_AUTHORIZE, $google);
        $this->assertSame(['gid', 'https://shop/admin/cb/', 'code', 'st', 'ch', 'S256', 'me@gmail.com', 'https://mail.google.com/', 'offline', 'consent'],
            [$query['client_id'], $query['redirect_uri'], $query['response_type'], $query['state'], $query['code_challenge'], $query['code_challenge_method'], $query['login_hint'], $query['scope'], $query['access_type'], $query['prompt']]);

        $ms = $client->getAuthorizeUrl($this->microsoft(), 'https://shop/cb', 'st', 'ch');
        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $ms);
        $this->assertStringContainsString('scope=offline_access%20https%3A%2F%2Foutlook.office.com%2FSMTP.Send', $ms);
    }

    public function testRefreshAndRotation(): void
    {
        $captured = null;
        $result = $this->client(200, '{"access_token":"at","refresh_token":"rt2","expires_in":3599}', $captured)->refresh($this->microsoft(), 'rt1');
        $this->assertSame(['access_token' => 'at', 'refresh_token' => 'rt2', 'expires_in' => 3599], $result);
        $this->assertSame('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token', $captured['url']);
        $this->assertSame('refresh_token', $captured['params']['grant_type']);
    }

    public function testClientCredentialsScope(): void
    {
        $captured = null;
        $this->client(200, '{"access_token":"at","expires_in":3599}', $captured)->clientCredentials($this->microsoft('app'));
        $this->assertSame('https://outlook.office365.com/.default', $captured['params']['scope']);
        $this->assertSame('client_credentials', $captured['params']['grant_type']);
    }

    /**
     * @dataProvider errors
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorsAreReadable(string $body, string $expected): void
    {
        try {
            $this->client(400, $body)->refresh($this->microsoft(), 'rt');
            $this->fail('Expected an exception');
        } catch (SmtpException $e) {
            $this->assertSame(SmtpException::STAGE_TOKEN, $e->getStage());
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errors(): array
    {
        return [
            'invalid grant' => ['{"error":"invalid_grant","error_description":"AADSTS70008: expired"}', 'Connect Account again'],
            'bad secret' => ['{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret provided."}', 'client secret'],
            'tenant' => ['{"error":"invalid_request","error_description":"AADSTS90002: Tenant not found."}', 'tenant'],
            'html' => ['<html>oops</html>', 'unexpected answer'],
        ];
    }
}
