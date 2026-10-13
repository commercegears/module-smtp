<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\Scope;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Base for the module's custom rows on the settings page: knows the scope that is open.
 */
abstract class AbstractField extends Field
{
    public function getScope(): Scope
    {
        $store = (int)$this->getRequest()->getParam('store');
        if ($store > 0) {
            return Scope::store($store);
        }
        $website = (int)$this->getRequest()->getParam('website');
        if ($website > 0) {
            return Scope::website($website);
        }
        return Scope::default();
    }

    /**
     * @return array<string, int>
     */
    public function getScopeParams(): array
    {
        $scope = $this->getScope();
        if ($scope->isStore()) {
            return ['store' => $scope->id];
        }
        if ($scope->isWebsite()) {
            return ['website' => $scope->id];
        }
        return [];
    }

    /**
     * No "Use default" checkbox and no scope label for these rows.
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}
