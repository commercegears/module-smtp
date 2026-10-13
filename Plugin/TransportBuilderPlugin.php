<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Plugin;

use CommerceGears\Smtp\Model\TemplateContext;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;

/**
 * Notes the template identifier and the store view of every email built with the TransportBuilder.
 */
class TransportBuilderPlugin
{
    public function __construct(
        private readonly TemplateContext $templateContext
    ) {
    }

    /**
     * @param mixed $templateIdentifier
     * @return null
     */
    public function beforeSetTemplateIdentifier(TransportBuilder $subject, $templateIdentifier)
    {
        $this->templateContext->setTemplate(
            $subject,
            is_scalar($templateIdentifier) && (string)$templateIdentifier !== '' ? (string)$templateIdentifier : null
        );
        return null;
    }

    /**
     * @param mixed $templateOptions
     * @return null
     */
    public function beforeSetTemplateOptions(TransportBuilder $subject, $templateOptions)
    {
        $store = is_array($templateOptions) ? ($templateOptions['store'] ?? null) : null;
        if (is_object($store) && method_exists($store, 'getId')) {
            $store = $store->getId();
        }
        $this->templateContext->setStore($subject, is_numeric($store) ? (int)$store : null);
        return null;
    }

    public function afterGetTransport(TransportBuilder $subject, TransportInterface $result): TransportInterface
    {
        $this->templateContext->bind($subject, $result);
        return $result;
    }
}
