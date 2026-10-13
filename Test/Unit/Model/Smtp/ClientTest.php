<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Smtp;

use CommerceGears\Smtp\Model\Smtp\Client;
use CommerceGears\Smtp\Model\Smtp\ConnectionSettings;
use CommerceGears\Smtp\Model\Smtp\SmtpException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private function settings(string $auth = 'auto', string $encryption = 'tls', string $user = 'user@example.com', string $password = 'secret'): ConnectionSettings
    {
        return new ConnectionSettings('custom', 'smtp.example.com', $encryption === 'ssl' ? 465 : 587, $encryption, $auth, $user, $password, 10, true, 'shop.example.com');
    }

    private function ehlo(bool $starttls, string $auth = 'PLAIN LOGIN'): array
    {
        $lines = ['250-smtp.example.com', '250-SIZE 1000', '250-8BITMIME'];
        if ($starttls) {
            $lines[] = '250-STARTTLS';
        }
        $lines[] = '250 AUTH ' . $auth;
        return $lines;
    }

    public function testStartTlsAndPlainAuth(): void
    {
        $socket = (new FakeSocket())
            ->queue(['220 ready'])
            ->queue($this->ehlo(true))
            ->queue(['220 go ahead'])
            ->queue($this->ehlo(false))
            ->queue(['235 ok']);
        $client = new Client($this->settings(), $socket);
        $client->connect();

        $this->assertSame('tcp://smtp.example.com:587', $socket->remote);
        $this->assertTrue($socket->crypto);
        $this->assertSame('PLAIN', $client->getAuthMechanism());
        $this->assertSame("AUTH PLAIN " . base64_encode("\0user@example.com\0secret") . "\r\n", $socket->written[3]);
        $this->assertStringNotContainsString('secret', $client->getTranscript());
        $this->assertStringNotContainsString(base64_encode("\0user@example.com\0secret"), $client->getTranscript());
    }

    public function testImplicitTlsUsesSslScheme(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready'])->queue($this->ehlo(false))->queue(['235 ok']);
        $client = new Client($this->settings('auto', 'ssl'), $socket);
        $client->connect();
        $this->assertSame('ssl://smtp.example.com:465', $socket->remote);
        $this->assertFalse($socket->crypto);
        $this->assertTrue($client->isEncrypted());
    }

    public function testMissingStartTlsFails(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready'])->queue($this->ehlo(false));
        $client = new Client($this->settings(), $socket);
        $this->expectException(SmtpException::class);
        $this->expectExceptionMessage('does not offer STARTTLS');
        $client->connect();
    }

    public function testLoginAuthMasksBothSteps(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready'])->queue($this->ehlo(false, 'LOGIN'))
            ->queue(['334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 ok']);
        $client = new Client($this->settings('auto', 'none'), $socket);
        $client->connect();
        $this->assertSame('LOGIN', $client->getAuthMechanism());
        $transcript = $client->getTranscript();
        $this->assertStringContainsString('[username hidden]', $transcript);
        $this->assertStringContainsString('[password hidden]', $transcript);
        $this->assertStringNotContainsString(base64_encode('secret'), $transcript);
    }

    public function testCramMd5(): void
    {
        $challenge = '<123.456@server>';
        $socket = (new FakeSocket())->queue(['220 ready'])->queue($this->ehlo(false, 'CRAM-MD5'))
            ->queue(['334 ' . base64_encode($challenge), '235 ok']);
        $client = new Client($this->settings('cram-md5', 'none'), $socket);
        $client->connect();
        $expected = base64_encode('user@example.com ' . hash_hmac('md5', $challenge, 'secret')) . "\r\n";
        $this->assertSame($expected, $socket->written[2]);
    }

    public function testXoauth2ErrorDance(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready'])->queue($this->ehlo(false, 'XOAUTH2'))
            ->queue(['334 ' . base64_encode('{"status":"400","schemes":"Bearer","scope":"https://mail.google.com/"}'), '535 5.7.8 Username and Password not accepted']);
        $client = new Client($this->settings('xoauth2', 'none', 'box@example.com', 'token-abc'), $socket);
        try {
            $client->connect();
            $this->fail('Expected an exception');
        } catch (SmtpException $e) {
            $this->assertSame(535, $e->getReplyCode());
            $this->assertSame(SmtpException::STAGE_AUTH, $e->getStage());
            $this->assertStringContainsString('mail.google.com', $e->getReplyText());
        }
        $this->assertSame("\r\n", $socket->written[2], 'An empty line answers the 334 error challenge');
        $this->assertStringContainsString(base64_encode("user=box@example.com\x01auth=Bearer token-abc\x01\x01"), $socket->written[1]);
        $this->assertStringNotContainsString('token-abc', $client->getTranscript());
    }

    public function testAutoAuthWithoutOfferExplains(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250-smtp.example.com', '250 SIZE 1000']);
        $client = new Client($this->settings('auto', 'none'), $socket);
        $this->expectExceptionMessage('does not offer sign-in');
        $client->connect();
    }

    public function testNoAuthWhenNoCredentials(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250 smtp.example.com']);
        $client = new Client($this->settings('auto', 'none', '', ''), $socket);
        $client->connect();
        $this->assertSame('', $client->getAuthMechanism());
    }

    public function testHeloFallback(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '502 unknown', '250 hello']);
        $client = new Client($this->settings('none', 'none'), $socket);
        $client->connect();
        $this->assertSame("HELO shop.example.com\r\n", $socket->written[1]);
        $this->assertSame([], $client->getCapabilities());
    }

    public function testSendDotStuffingSizeAndRecipients(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250-smtp.example.com', '250-SIZE 100000', '250 8BITMIME'])
            ->queue(['250 sender ok', '250 rcpt ok', '251 forwarded', '354 go', '250 queued as ABC']);
        $client = new Client($this->settings('none', 'none'), $socket);
        $client->connect();
        $reply = $client->send('shop@example.com', ['a@example.com', 'b@example.com'], "Subject: x\n\n.leading dot\nGrüße\n");
        $this->assertSame('queued as ABC', $reply);
        $this->assertStringStartsWith('MAIL FROM:<shop@example.com> SIZE=', $socket->written[1]);
        $this->assertStringContainsString('BODY=8BITMIME', $socket->written[1]);
        $this->assertSame("RCPT TO:<a@example.com>\r\n", $socket->written[2]);
        $data = $socket->written[5];
        $this->assertStringContainsString("\r\n..leading dot\r\n", $data);
        $this->assertStringEndsWith("\r\n.\r\n", $data);
        $this->assertSame(1, $client->getMessageCount());
    }

    public function testRejectedRecipient(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250 hello', '250 ok', '550 5.1.1 User unknown']);
        $client = new Client($this->settings('none', 'none'), $socket);
        $client->connect();
        try {
            $client->send('shop@example.com', ['nobody@example.com'], "Subject: x\r\n\r\nbody");
            $this->fail('Expected an exception');
        } catch (SmtpException $e) {
            $this->assertSame(SmtpException::STAGE_RCPT, $e->getStage());
            $this->assertFalse($e->isTemporary());
        }
    }

    public function testMessageTooLarge(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250-hello', '250 SIZE 10']);
        $client = new Client($this->settings('none', 'none'), $socket);
        $client->connect();
        $this->expectExceptionMessage('accepts at most');
        $client->send('shop@example.com', ['a@example.com'], str_repeat('x', 100));
    }

    public function testHeaderInjectionInAddressIsStripped(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready', '250 hello', '250 ok', '250 ok', '354 go', '250 ok']);
        $client = new Client($this->settings('none', 'none'), $socket);
        $client->connect();
        $client->send("shop@example.com>\r\nRCPT TO:<evil@example.com", ['a@example.com'], "Subject: x\r\n\r\nbody");
        $this->assertSame("MAIL FROM:<shop@example.comRCPTTO:evil@example.com>\r\n", $socket->written[1]);
    }

    public function testClosedConnectionIsTemporary(): void
    {
        $socket = (new FakeSocket())->queue(['220 ready']);
        $client = new Client($this->settings('none', 'none'), $socket);
        try {
            $client->connect();
            $this->fail('Expected an exception');
        } catch (SmtpException $e) {
            $this->assertSame(SmtpException::STAGE_IO, $e->getStage());
            $this->assertTrue($e->isTemporary());
        }
    }
}
