<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\System\Config;

use CommerceGears\Smtp\Model\OAuth\RedirectUri as RedirectUriModel;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Shows the OAuth redirect URI to register with Google or Microsoft, with a copy button.
 */
class RedirectUri extends AbstractField
{
    public function __construct(
        Context $context,
        private readonly RedirectUriModel $redirectUri,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $uri = $this->redirectUri->get();
        $id = $element->getHtmlId() . '_value';
        return '<div class="commercegears-smtp-copy">'
            . '<input type="text" readonly="readonly" class="input-text admin__control-text" id="' . $this->escapeHtmlAttr($id) . '" value="' . $this->escapeHtmlAttr($uri) . '"/>'
            . '<button type="button" class="action-default" data-cg-smtp-copy="' . $this->escapeHtmlAttr($id) . '">' . $this->escapeHtml(__('Copy')) . '</button>'
            . '</div><p class="note"><span>' . $this->escapeHtml(__('Add this exact address to the OAuth client (Google: Authorised redirect URIs; Microsoft: Authentication > Web > Redirect URIs).')) . '</span></p>';
    }
}
