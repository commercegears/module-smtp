<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Message;

use CommerceGears\Smtp\Model\Message\MimeParser;
use PHPUnit\Framework\TestCase;

class MimeParserTest extends TestCase
{
    public function testNestedMultipartWithAttachment(): void
    {
        $raw = "Subject: x\r\nContent-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
            . "--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n"
            . "--inner\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nGr=FC=DFe\r\n"
            . "--inner\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('<b>Hi</b>') . "\r\n"
            . "--inner--\r\n"
            . "--outer\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=utf-8''Rechnung%20%C3%BC.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('PDFDATA') . "\r\n"
            . "--outer--\r\n";
        $result = (new MimeParser())->parse($raw);
        $this->assertSame('<b>Hi</b>', $result['html']);
        $this->assertSame("Grüße", $result['text']);
        $this->assertSame('Rechnung ü.pdf', $result['attachments'][0]['name']);
        $this->assertSame(7, $result['attachments'][0]['size']);
    }

    public function testSinglePartHtml(): void
    {
        $result = (new MimeParser())->parse("Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>caf=C3=A9</p>");
        $this->assertSame('<p>café</p>', $result['html']);
        $this->assertSame([], $result['attachments']);
    }
}
