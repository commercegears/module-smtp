<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * A settings row that uses the whole width (panels and notices).
 */
abstract class FullWidthField extends AbstractField
{
    public function render(AbstractElement $element)
    {
        $html = $this->_getElementHtml($element);
        if ($html === '') {
            return '';
        }
        return '<tr id="row_' . $this->escapeHtmlAttr($element->getHtmlId()) . '"><td colspan="4" class="commercegears-smtp-fullrow">'
            . $html . '</td></tr>';
    }
}
