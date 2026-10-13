<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model;

use CommerceGears\Smtp\Model\TemplateContext;
use PHPUnit\Framework\TestCase;

class TemplateContextTest extends TestCase
{
    public function testBindsBuilderDataToTransport(): void
    {
        $context = new TemplateContext();
        $builder = new \stdClass();
        $transport = new \stdClass();
        $context->setTemplate($builder, 'sales_email_order_template');
        $context->setStore($builder, 3);
        $context->bind($builder, $transport);
        $this->assertSame(['template' => 'sales_email_order_template', 'store' => 3], $context->get($transport));

        $second = new \stdClass();
        $context->bind($builder, $second);
        $this->assertSame(['template' => null, 'store' => null], $context->get($second), 'Builder data is used once');
    }
}
