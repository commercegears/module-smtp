<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model\Delivery;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Delivery\Preparer;
use CommerceGears\Smtp\Model\Message\AddressParser;
use CommerceGears\Smtp\Model\Message\MimeMessage;
use CommerceGears\Smtp\Model\Message\OutgoingEmail;
use CommerceGears\Smtp\Model\Scope;
use PHPUnit\Framework\TestCase;

class PreparerTest extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\Stub */
    private Config $config;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getHeloName')->willReturn('shop.example.com');
        $this->config->method('getStoreHost')->willReturn('staging.example.com');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configure(array $values): void
    {
        $defaults = [
            'isMagentoMailDisabled' => false,
            'getBlockedRecipients' => [],
            'getDeliveryMode' => Config::DELIVERY_LIVE,
            'getTestRecipients' => [],
            'getLiveHosts' => [],
            'getAllowedRecipients' => [],
            'getSubjectPrefix' => '',
            'getFromOverride' => '',
            'getReturnPathEmail' => '',
        ];
        foreach (array_merge($defaults, $values) as $method => $value) {
            $this->config->method($method)->willReturn($value);
        }
    }

    private function email(): OutgoingEmail
    {
        $raw = "From: Shop <shop@example.com>\r\nTo: Customer <customer@example.com>\r\nCc: team@company.test\r\nSubject: Your order\r\nMessage-ID: <abc@example.com>\r\n\r\nHello";
        return new OutgoingEmail($raw, 'shop@example.com', ['customer@example.com'], ['team@company.test'], ['archive@company.test'], 'Your order', 'sales_email_order_template');
    }

    private function preparer(): Preparer
    {
        return new Preparer($this->config, new AddressParser());
    }

    public function testLiveKeepsEverything(): void
    {
        $this->configure([]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertTrue($prepared->send);
        $this->assertSame(['customer@example.com', 'team@company.test', 'archive@company.test'], $prepared->getRecipients());
        $this->assertSame('shop@example.com', $prepared->envelopeFrom);
        $this->assertSame([], $prepared->originalRecipients);
        $this->assertSame('<abc@example.com>', $prepared->messageId);
    }

    public function testMagentoMailDisabledSkips(): void
    {
        $this->configure(['isMagentoMailDisabled' => true]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertFalse($prepared->send);
        $this->assertStringContainsString('Disable Email Communications', $prepared->getNote());
    }

    public function testRedirectWithAllowedDomainAndPrefix(): void
    {
        $this->configure([
            'getDeliveryMode' => Config::DELIVERY_REDIRECT,
            'getTestRecipients' => ['qa@example.com'],
            'getAllowedRecipients' => ['@company.test'],
            'getSubjectPrefix' => '[STAGING]',
        ]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertSame(['qa@example.com'], $prepared->to);
        $this->assertSame(['team@company.test'], $prepared->cc);
        $this->assertSame(['archive@company.test'], $prepared->bcc);
        $mime = new MimeMessage($prepared->data);
        $this->assertSame('qa@example.com', $mime->get('To'));
        $this->assertSame('[STAGING] Your order', $mime->getDecoded('Subject'));
        $this->assertStringContainsString('customer@example.com', (string)$mime->get(Preparer::ORIGINAL_HEADER));
        $this->assertContains('customer@example.com', $prepared->originalRecipients);
    }

    public function testLogOnlyWithoutAllowedSkips(): void
    {
        $this->configure(['getDeliveryMode' => Config::DELIVERY_LOG_ONLY]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertFalse($prepared->send);
        $this->assertStringContainsString('log only', $prepared->getNote());
    }

    public function testLogOnlyDeliversToAllowed(): void
    {
        $this->configure(['getDeliveryMode' => Config::DELIVERY_LOG_ONLY, 'getAllowedRecipients' => ['company.test']]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertTrue($prepared->send);
        $this->assertSame(['team@company.test', 'archive@company.test'], $prepared->getRecipients());
        $this->assertNull((new MimeMessage($prepared->data))->get('To'));
    }

    public function testSafetyLockOnOtherHost(): void
    {
        $this->configure(['getLiveHosts' => ['www.example.com'], 'getTestRecipients' => ['qa@example.com']]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertSame(['qa@example.com'], $prepared->getRecipients());
        $this->assertStringContainsString('staging.example.com', $prepared->getNote());
    }

    public function testSafetyLockWithoutTestAddressHoldsEverything(): void
    {
        $this->configure(['getLiveHosts' => ['www.example.com']]);
        $this->assertFalse($this->preparer()->prepare($this->email(), Scope::store(1))->send);
    }

    public function testBlocklist(): void
    {
        $this->configure(['getBlockedRecipients' => ['*@company.test']]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $this->assertSame(['customer@example.com'], $prepared->getRecipients());
        $this->assertNull((new MimeMessage($prepared->data))->get('Cc'));
    }

    public function testFromOverrideAddsReplyToAndEnvelope(): void
    {
        $this->configure(['getFromOverride' => 'mailbox@example.com']);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1));
        $mime = new MimeMessage($prepared->data);
        $this->assertSame('Shop <mailbox@example.com>', $mime->get('From'));
        $this->assertSame('Shop <shop@example.com>', $mime->get('Reply-To'));
        $this->assertSame('mailbox@example.com', $prepared->envelopeFrom);
    }

    public function testReturnPathSetting(): void
    {
        $this->configure(['getReturnPathEmail' => 'bounces@example.com']);
        $this->assertSame('bounces@example.com', $this->preparer()->prepare($this->email(), Scope::store(1))->envelopeFrom);
    }

    public function testResendGetsNewMessageIdAndOverrideRecipient(): void
    {
        $this->configure([]);
        $prepared = $this->preparer()->prepare($this->email(), Scope::store(1), 'other@example.com', true);
        $this->assertSame(['other@example.com'], $prepared->getRecipients());
        $this->assertNotSame('<abc@example.com>', $prepared->messageId);
        $this->assertStringEndsWith('@example.com>', $prepared->messageId);
        $this->assertNull((new MimeMessage($prepared->data))->get('Cc'));
    }

    /**
     * @dataProvider patterns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('patterns')]
    public function testMatchesPattern(string $address, string $pattern, bool $expected): void
    {
        $this->assertSame($expected, Preparer::matchesPattern($address, [$pattern]));
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public static function patterns(): array
    {
        return [
            ['A@Example.com', 'a@example.com', true],
            ['a@example.com', '@example.com', true],
            ['a@sub.example.com', '@example.com', false],
            ['a@example.com', 'example.com', true],
            ['noreply@shop.test', 'noreply@*', true],
            ['a@mail.test.org', '*.test.org', true],
            ['a@test.org', '*@test.*', true],
            ['a@other.com', '*@test.*', false],
        ];
    }
}
