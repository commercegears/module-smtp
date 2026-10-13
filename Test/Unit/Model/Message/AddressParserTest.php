<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Message;

use CommerceGears\Smtp\Model\Message\AddressParser;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    public function testParsesCommonForms(): void
    {
        $parser = new AddressParser();
        $result = $parser->parse('"Doe, John" <john@example.com>, jane@example.com (Jane), =?utf-8?Q?J=C3=BCrgen?= <j@example.de>, Team: a@x.test, b@x.test;');
        $this->assertSame(['john@example.com', 'jane@example.com', 'j@example.de', 'a@x.test', 'b@x.test'], array_column($result, 'email'));
        $this->assertSame('Doe, John', $result[0]['name']);
        $this->assertSame('Jürgen', $result[2]['name']);
    }

    public function testIgnoresInvalidParts(): void
    {
        $this->assertSame([], (new AddressParser())->parseEmails('undisclosed-recipients:;'));
    }

    public function testFormat(): void
    {
        $parser = new AddressParser();
        $this->assertSame('"Shop, Ltd." <a@example.com>, b@example.com', $parser->format([
            ['email' => 'a@example.com', 'name' => 'Shop, Ltd.'],
            'b@example.com',
        ]));
        $this->assertStringStartsWith('=?UTF-8?Q?', $parser->format([['email' => 'a@example.com', 'name' => 'Grüße']]));
        $this->assertStringNotContainsString("\n", $parser->format([['email' => "a@example.com\r\nBcc: x@y", 'name' => "Evil\r\nBcc: x"]]));
    }
}
