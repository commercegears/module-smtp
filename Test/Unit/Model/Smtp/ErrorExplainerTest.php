<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Smtp;

use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use CommerceGears\Smtp\Model\Smtp\ErrorExplainer;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use PHPUnit\Framework\TestCase;

class ErrorExplainerTest extends TestCase
{
    private function settings(string $provider, int $port = 587, string $encryption = 'tls'): ConnectionSettings
    {
        return new ConnectionSettings($provider, 'smtp.example.com', $port, $encryption, 'auto', 'u', 'p');
    }

    /**
     * @return array<string, array{0: SmtpException, 1: string, 2: string}>
     */
    public static function cases(): array
    {
        return [
            'gmail app password' => [new SmtpException('Server replied: 535', SmtpException::STAGE_AUTH, 535, '5.7.8 Username and Password not accepted. gsmtp'), 'google', 'app password'],
            'microsoft smtp auth off' => [new SmtpException('Server replied: 535', SmtpException::STAGE_AUTH, 535, '5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.'), 'microsoft', 'Authenticated SMTP'],
            'microsoft send as' => [new SmtpException('Server replied: 554', SmtpException::STAGE_DATA, 554, '5.2.252 SendAsDenied; shop@example.com not allowed to send as other@example.com'), 'microsoft_oauth', 'Send As'],
            'ses not verified' => [new SmtpException('Server replied: 554', SmtpException::STAGE_DATA, 554, 'Message rejected: Email address is not verified.'), 'ses', 'Verify the sender'],
            'refused' => [new SmtpException('Connection refused', SmtpException::STAGE_CONNECT), 'custom', 'refused the connection'],
            'timeout' => [new SmtpException('Connection timed out', SmtpException::STAGE_CONNECT), 'custom', 'did not answer'],
            'dns' => [new SmtpException('php_network_getaddresses: getaddrinfo for smtp.exmaple.com failed: Name or service not known', SmtpException::STAGE_CONNECT), 'custom', 'host name could not be found'],
            'wrong version' => [new SmtpException('SSL operation failed with code 1. OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number', SmtpException::STAGE_TLS), 'custom', 'do not match'],
            'certificate' => [new SmtpException('SSL operation failed: certificate verify failed', SmtpException::STAGE_TLS), 'custom', 'certificate'],
            'unknown user' => [new SmtpException('Server replied: 550', SmtpException::STAGE_RCPT, 550, '5.1.1 User unknown'), 'custom', 'does not exist'],
            'relay' => [new SmtpException('Server replied: 554', SmtpException::STAGE_RCPT, 554, '5.7.1 Relay access denied'), 'custom', 'does not relay'],
            'rate limit' => [new SmtpException('Server replied: 421', SmtpException::STAGE_MAIL_FROM, 421, '4.7.0 Too many messages, try later'), 'custom', 'limits how fast'],
            'dmarc' => [new SmtpException('Server replied: 550', SmtpException::STAGE_DATA, 550, '5.7.26 Unauthenticated email from example.com is not accepted due to DMARC policy'), 'custom', 'not authenticated'],
            'needs auth' => [new SmtpException('Server replied: 530', SmtpException::STAGE_MAIL_FROM, 530, '5.7.0 Authentication required'), 'custom', 'wants a sign-in'],
        ];
    }

    /**
     * @dataProvider cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testExplains(SmtpException $error, string $provider, string $expected): void
    {
        $result = (new ErrorExplainer())->explain($error, $this->settings($provider));
        $this->assertStringContainsString($expected, $result['message'] . ' ' . $result['hint']);
        $this->assertStringContainsString($error->getMessage(), $result['technical']);
    }

    public function testPort465WithStartTls(): void
    {
        $error = new SmtpException('The server did not answer in time', SmtpException::STAGE_IO);
        $result = (new ErrorExplainer())->explain($error, $this->settings('custom', 465, 'tls'));
        $this->assertStringContainsString('SSL/TLS', $result['hint']);
    }

    public function testTemporaryFlag(): void
    {
        $explainer = new ErrorExplainer();
        $this->assertTrue($explainer->explain(new SmtpException('x', SmtpException::STAGE_RCPT, 451, 'try later'))['temporary']);
        $this->assertFalse($explainer->explain(new SmtpException('x', SmtpException::STAGE_AUTH, 535, 'bad'))['temporary']);
        $this->assertTrue($explainer->explain(new SmtpException('x', SmtpException::STAGE_CONNECT))['temporary']);
    }

    public function testUnknownFallsBackToTechnicalText(): void
    {
        $result = (new ErrorExplainer())->explain(new \RuntimeException('Something odd'));
        $this->assertSame('Something odd', $result['message']);
    }
}
