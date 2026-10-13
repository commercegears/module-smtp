<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Plugin;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Mailer;
use CommerceGears\Smtp\Model\Message\MessageExtractor;
use CommerceGears\Smtp\Model\Scope;
use CommerceGears\Smtp\Model\TemplateContext;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes over sending when the module is on for the email's store view. Runs before Magento's own
 * "Disable Email Communications" plugin, so that emails held back by that switch are logged with the reason.
 * When the module is off, Magento's own transport sends the email unchanged.
 */
class TransportPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly Mailer $mailer,
        private readonly MessageExtractor $extractor,
        private readonly TemplateContext $templateContext,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return mixed
     */
    public function aroundSendMessage(TransportInterface $subject, callable $proceed)
    {
        $context = $this->templateContext->get($subject);
        $storeId = $context['store'];
        if ($storeId === null) {
            try {
                $storeId = (int)$this->storeManager->getStore()->getId();
            } catch (\Exception) {
                $storeId = 0;
            }
        }
        $scope = Scope::store($storeId);
        if (!$this->config->isEnabled($scope)) {
            return $proceed();
        }

        try {
            $email = $this->extractor->extract($subject->getMessage(), $context['template'], $this->config->getHeloName($scope));
        } catch (\Throwable $e) {
            $this->logger->error('CommerceGears SMTP: the email could not be read, Magento\'s own transport is used: ' . $e->getMessage());
            return $proceed();
        }

        $this->mailer->handle($email, $scope, $storeId > 0 ? $storeId : null);
        return null;
    }
}
