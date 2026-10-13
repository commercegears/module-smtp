<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Oauth;

use CommerceGears\Smtp\Model\OAuth\TokenStorage;
use CommerceGears\Smtp\Model\Scope;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Removes the stored OAuth connection of the scope.
 */
class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::config';

    public function __construct(
        Context $context,
        private readonly TokenStorage $tokenStorage
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $scope = Scope::fromParams($this->getRequest()->getParam('store'), $this->getRequest()->getParam('website'));
        $deleted = $this->tokenStorage->delete($scope);
        if ($deleted > 0) {
            $this->messageManager->addSuccessMessage(__('The account was disconnected.'));
        }
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        return $result->setData(['success' => true, 'deleted' => $deleted]);
    }
}
