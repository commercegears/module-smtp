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
use Magento\Framework\Exception\MailException;
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
            } catch (\Throwable) {
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
            return $this->fallBack($proceed, $scope, 'the email could not be read', $e);
        }

        try {
            $this->mailer->handle($email, $scope, $storeId > 0 ? $storeId : null);
        } catch (MailException $e) {
            // A readable delivery error from the module (no retries left): Magento shows or logs it.
            throw $e;
        } catch (\Throwable $e) {
            return $this->fallBack($proceed, $scope, 'unexpected error', $e);
        }
        return null;
    }

    /**
     * An unexpected problem in the module (unreadable message, database, code) must never lose the email silently:
     * Magento's own transport sends it, unless delivery rules (test addresses, log only, staging lock, blocklist)
     * apply, because Magento's transport would ignore them. Then the email is not sent and Magento gets the error.
     *
     * @return mixed
     * @throws MailException
     */
    private function fallBack(callable $proceed, Scope $scope, string $what, \Throwable $e)
    {
        try {
            $bypassAllowed = $this->config->canBypassDeliveryRules($scope);
        } catch (\Throwable) {
            $bypassAllowed = false;
        }
        if (!$bypassAllowed) {
            $this->logger->critical(
                'CommerceGears SMTP: ' . $what . '; not sent because delivery rules apply: ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new MailException(__('The email could not be sent: %1', $e->getMessage()), $e);
        }
        $this->logger->critical(
            'CommerceGears SMTP: ' . $what . ', Magento\'s own transport sends the email instead: ' . $e->getMessage(),
            ['exception' => $e]
        );
        return $proceed();
    }
}
