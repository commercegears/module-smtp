<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\Provider\PresetRegistry;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Notes for the selected provider (where to find the credentials, which server is used). Switches with the
 * provider select without saving.
 */
class ProviderHelp extends FullWidthField
{
    /**
     * @var string
     */
    protected $_template = 'CommerceGears_Smtp::system/config/provider-help.phtml';

    public function __construct(
        Context $context,
        private readonly PresetRegistry $presets,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('element', $element);
        return $this->_toHtml();
    }

    /**
     * @return array<string, array{label: string, help: string, server: string}>
     */
    public function getPresetsData(): array
    {
        $data = [];
        foreach ($this->presets->getAll() as $preset) {
            $server = '';
            if (!$preset->isCustom()) {
                $server = $preset->regions === []
                    ? $preset->host . ':' . $preset->defaultPort
                    : str_replace('{region}', '<' . __('region') . '>', $preset->host) . ':' . $preset->defaultPort;
            }
            $data[$preset->code] = [
                'label' => (string)__($preset->label),
                'help' => (string)__($preset->help),
                'server' => $server,
            ];
        }
        return $data;
    }

    public function getProviderSelectId(): string
    {
        return 'commercegears_smtp_connection_provider';
    }
}
