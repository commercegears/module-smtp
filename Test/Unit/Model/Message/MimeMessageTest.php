<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Message;

use CommerceGears\Smtp\Model\Message\MimeMessage;
use PHPUnit\Framework\TestCase;

class MimeMessageTest extends TestCase
{
    private const RAW = "From: Shop <shop@example.com>\nTo: a@example.com,\n b@example.com\nBcc: hidden@example.com\nSubject: =?UTF-8?B?" . 'R3LDvMOfZQ==' . "?=\nX-Keep: original\n\nBody line\n.second\n";

    public function testReadsAndUnfolds(): void
    {
        $message = new MimeMessage(self::RAW);
        $this->assertSame('a@example.com, b@example.com', $message->get('to'));
        $this->assertSame('Grüße', $message->getDecoded('Subject'));
        $this->assertSame("Body line\r\n.second\r\n", $message->getBody());
    }

    public function testEditKeepsUntouchedHeadersAndBody(): void
    {
        $message = new MimeMessage(self::RAW);
        $message->remove('Bcc');
        $message->set('To', 'qa@example.com');
        $message->setText('Subject', '[TEST] Grüße');
        $out = $message->toString();
        $this->assertStringNotContainsString('Bcc:', $out);
        $this->assertStringContainsString("To: qa@example.com\r\n", $out);
        $this->assertStringContainsString("X-Keep: original\r\n", $out);
        $this->assertSame('[TEST] Grüße', (new MimeMessage($out))->getDecoded('Subject'));
        $this->assertStringEndsWith("\r\n\r\nBody line\r\n.second\r\n", $out);
        $this->assertLessThan(strpos($out, 'X-Keep'), strpos($out, 'To:'), 'Replaced header keeps its position');
    }

    public function testHeaderValueCannotInjectLines(): void
    {
        $message = new MimeMessage(self::RAW);
        $message->set('Reply-To', "a@example.com\r\nBcc: evil@example.com");
        $this->assertStringNotContainsString("\r\nBcc: evil", $message->toString());
    }

    public function testLongAddressListIsFolded(): void
    {
        $message = new MimeMessage(self::RAW);
        $message->set('To', implode(', ', array_map(static fn (int $i): string => "user$i@example.com", range(1, 80))));
        foreach (explode("\r\n", $message->toString()) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line));
        }
        $this->assertCount(80, explode(',', (string)$message->get('To')));
    }
}
