<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Plugin;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Mailer;
use CommerceGears\Smtp\Model\Message\AddressParser;
use CommerceGears\Smtp\Model\Message\MessageExtractor;
use CommerceGears\Smtp\Model\TemplateContext;
use CommerceGears\Smtp\Plugin\TransportPlugin;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TransportPluginTest extends TestCase
{
    private function plugin(Mailer $mailer, bool $bypassAllowed): TransportPlugin
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getHeloName')->willReturn('shop.example.com');
        $config->method('canBypassDeliveryRules')->willReturn($bypassAllowed);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new TransportPlugin(
            $config,
            $mailer,
            new MessageExtractor(new AddressParser()),
            new TemplateContext(),
            $storeManager,
            new NullLogger()
        );
    }

    private function transport(): TransportInterface
    {
        $message = $this->createStub(EmailMessageInterface::class);
        $message->method('toString')->willReturn("From: shop@example.com\r\nTo: customer@example.com\r\nSubject: Hi\r\n\r\nHello");
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('getMessage')->willReturn($message);
        return $transport;
    }

    public function testUnexpectedErrorFallsBackToMagentoTransport(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('handle')->willThrowException(new \RuntimeException('Database gone'));
        $called = false;
        $this->plugin($mailer, true)->aroundSendMessage($this->transport(), function () use (&$called) {
            $called = true;
            return null;
        });
        $this->assertTrue($called, 'Magento\'s transport must send the email');
    }

    public function testNoFallbackWhileDeliveryRulesApply(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('handle')->willThrowException(new \RuntimeException('Database gone'));
        $called = false;
        try {
            $this->plugin($mailer, false)->aroundSendMessage($this->transport(), function () use (&$called) {
                $called = true;
                return null;
            });
            $this->fail('A MailException is expected');
        } catch (MailException $e) {
            $this->assertStringContainsString('Database gone', $e->getMessage());
        }
        $this->assertFalse($called, 'The staging rules must not be bypassed');
    }

    public function testMailExceptionIsPassedOn(): void
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('handle')->willThrowException(new MailException(__('The email could not be sent: no')));
        $called = false;
        $this->expectException(MailException::class);
        try {
            $this->plugin($mailer, true)->aroundSendMessage($this->transport(), function () use (&$called) {
                $called = true;
                return null;
            });
        } finally {
            $this->assertFalse($called);
        }
    }
}
