<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Test;

use CommerceGears\Smtp\Model\Scope;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;

/**
 * Base of the settings page's test buttons (POST with form key, JSON answer).
 */
abstract class AbstractTest extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::config';

    protected function getScope(): Scope
    {
        return Scope::fromParams($this->getRequest()->getParam('store'), $this->getRequest()->getParam('website'));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON);
        return $result->setData($data);
    }
}
