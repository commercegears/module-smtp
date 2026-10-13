<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\EmailType;

use CommerceGears\Smtp\Model\Diagnostics\Tester;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as TemplateCollectionFactory;
use Magento\Email\Model\Template\Config as TemplateConfig;

/**
 * Turns a template identifier into a readable email type ("New Order", "Reset Password", ...).
 */
class Resolver
{
    /** @var array<string, string> */
    private array $cache = [];
    /** @var array<string, string>|null */
    private ?array $dbTemplates = null;

    public function __construct(
        private readonly TemplateConfig $templateConfig,
        private readonly TemplateCollectionFactory $templateCollectionFactory
    ) {
    }

    public function getLabel(?string $templateId): string
    {
        if ($templateId === null || $templateId === '') {
            return '';
        }
        if (isset($this->cache[$templateId])) {
            return $this->cache[$templateId];
        }
        if ($templateId === Tester::TEMPLATE_ID) {
            return $this->cache[$templateId] = (string)__('SMTP test email');
        }
        $label = $templateId;
        if (ctype_digit($templateId)) {
            $label = $this->getDbTemplates()[$templateId] ?? $templateId;
        } else {
            try {
                $label = (string)$this->templateConfig->getTemplateLabel($templateId);
            } catch (\UnexpectedValueException) {
                $label = $templateId;
            }
        }
        return $this->cache[$templateId] = $label;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array
    {
        $options = [['value' => Tester::TEMPLATE_ID, 'label' => (string)__('SMTP test email')]];
        foreach ($this->templateConfig->getAvailableTemplates() as $template) {
            $options[] = ['value' => (string)$template['value'], 'label' => (string)$template['label']];
        }
        foreach ($this->getDbTemplates() as $id => $code) {
            $options[] = ['value' => (string)$id, 'label' => $code . ' ' . (string)__('(custom)')];
        }
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function getDbTemplates(): array
    {
        if ($this->dbTemplates === null) {
            $this->dbTemplates = [];
            foreach ($this->templateCollectionFactory->create() as $template) {
                $this->dbTemplates[(string)$template->getId()] = (string)$template->getTemplateCode();
            }
        }
        return $this->dbTemplates;
    }
}
