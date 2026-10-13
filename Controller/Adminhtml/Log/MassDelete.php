<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Log;

use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use CommerceGears\Smtp\Model\ResourceModel\Log\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Deletes the selected log entries (and their content). Queued emails that are deleted are not sent.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_Smtp::log_manage';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly LogResource $logResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;
        foreach (array_chunk($collection->getAllIds(), 1000) as $ids) {
            $deleted += $this->logResource->deleteByIds($ids);
        }
        $this->messageManager->addSuccessMessage(__('%1 log entries were deleted.', $deleted));
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
