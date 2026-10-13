<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Controller\Adminhtml\Test;

use CommerceGears\Smtp\Model\Diagnostics\Tester;
use Magento\Backend\App\Action\Context;

/**
 * Connects and signs in without sending.
 */
class Connection extends AbstractTest
{
    public function __construct(
        Context $context,
        private readonly Tester $tester
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        return $this->json($this->tester->checkConnection($this->getScope()));
    }
}
