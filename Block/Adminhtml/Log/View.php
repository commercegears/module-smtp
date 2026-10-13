<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Block\Adminhtml\Log;

use CommerceGears\Smtp\Model\EmailType\Resolver;
use CommerceGears\Smtp\Model\Log;
use CommerceGears\Smtp\Model\LogFactory;
use CommerceGears\Smtp\Model\Message\MimeMessage;
use CommerceGears\Smtp\Model\Message\MimeParser;
use CommerceGears\Smtp\Model\ResendGuard;
use CommerceGears\Smtp\Model\ResourceModel\Log as LogResource;
use CommerceGears\Smtp\Model\Smtp\Client;
use CommerceGears\Smtp\Model\Source\Status;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Details of one logged email: status, recipients, safe HTML preview, text, headers, attachments and the SMTP
 * transcript of a failure.
 */
class View extends Template
{
    /**
     * @var string
     */
    protected $_template = 'CommerceGears_Smtp::log/view.phtml';

    private ?Log $log = null;
    /** @var array{envelope: array<string, mixed>, raw: string}|null|false */
    private array|null|false $content = false;
    /** @var array{html: string, text: string, attachments: array<int, array{name: string, type: string, size: int}>}|null */
    private ?array $parsed = null;

    public function __construct(
        Context $context,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource,
        private readonly MimeParser $mimeParser,
        private readonly Resolver $emailTypes,
        private readonly Status $statusSource,
        private readonly ResendGuard $resendGuard,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getLog(): Log
    {
        if ($this->log === null) {
            $this->log = $this->logFactory->create();
            $this->logResource->load($this->log, (int)$this->getRequest()->getParam('id'));
        }
        return $this->log;
    }

    /**
     * @return array{envelope: array<string, mixed>, raw: string}|null
     */
    public function getContent(): ?array
    {
        if ($this->content === false) {
            // Never loaded without "View Email Content": it can hold personal data and password reset links.
            $this->content = $this->getLog()->getId() && $this->canViewContent()
                ? $this->logResource->loadContent((int)$this->getLog()->getId())
                : null;
        }
        return $this->content;
    }

    public function canViewContent(): bool
    {
        return $this->_authorization->isAllowed('CommerceGears_Smtp::log_content');
    }

    public function isContentKept(): bool
    {
        return $this->getLog()->getId() && $this->logResource->hasContent((int)$this->getLog()->getId());
    }

    /**
     * Why the email cannot be sent again (module off for its store view, store view deleted), or null.
     */
    public function getResendBlocker(): ?string
    {
        return $this->resendGuard->getBlocker($this->getLog()->getData('store_id'));
    }

    /**
     * Remote images and styles load only when the admin asks for it on this page (tracking pixels would
     * otherwise tell the sender's servers the admin's IP address).
     */
    public function isRemoteContentAllowed(): bool
    {
        return (string)$this->getRequest()->getParam('remote') === '1';
    }

    public function getRemoteContentUrl(bool $allow): string
    {
        $params = ['id' => $this->getLog()->getId()];
        if ($allow) {
            $params['remote'] = 1;
        }
        return $this->getUrl('*/*/view', $params);
    }

    public function hasRemoteContent(): bool
    {
        return preg_match(
            '/(?:\b(?:src|background|poster)\s*=\s*["\']?\s*(?:https?:)?\/\/|url\(\s*["\']?\s*(?:https?:)?\/\/|@import|<link\b[^>]*stylesheet)/i',
            $this->getParsed()['html']
        ) === 1;
    }

    /**
     * @return array{html: string, text: string, attachments: array<int, array{name: string, type: string, size: int}>}
     */
    public function getParsed(): array
    {
        if ($this->parsed === null) {
            $content = $this->getContent();
            $this->parsed = $content !== null ? $this->mimeParser->parse($content['raw']) : ['html' => '', 'text' => '', 'attachments' => []];
        }
        return $this->parsed;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    public function getHeaders(): array
    {
        $content = $this->getContent();
        if ($content === null) {
            return [];
        }
        $message = new MimeMessage($content['raw']);
        $headers = [];
        foreach ($message->getHeaderList() as $header) {
            if (in_array(strtolower($header['name']), ['content-type', 'content-transfer-encoding', 'mime-version'], true)) {
                continue;
            }
            $headers[] = ['name' => $header['name'], 'value' => MimeMessage::decode($header['value'])];
        }
        return $headers;
    }

    public function getStatusLabel(): string
    {
        $status = (string)$this->getLog()->getData('status');
        foreach ($this->statusSource->toOptionArray() as $option) {
            if ($option['value'] === $status) {
                return (string)$option['label'];
            }
        }
        return $status;
    }

    public function getEmailType(): string
    {
        return $this->emailTypes->getLabel($this->getLog()->getData('template_id'));
    }

    public function getStoreName(): string
    {
        $storeId = $this->getLog()->getData('store_id');
        if ($storeId === null) {
            return (string)__('Admin / default');
        }
        try {
            return (string)$this->_storeManager->getStore((int)$storeId)->getName();
        } catch (\Exception) {
            return (string)__('Deleted store view');
        }
    }

    public function formatLogDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return $this->_localeDate->formatDateTime($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::MEDIUM);
    }

    public function formatSize(int $bytes): string
    {
        return Client::formatBytes($bytes);
    }

    public function canManage(): bool
    {
        return $this->_authorization->isAllowed('CommerceGears_Smtp::log_manage');
    }

    public function getResendUrl(): string
    {
        return $this->getUrl('*/*/resend', ['id' => $this->getLog()->getId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getLogUrl(int $id): string
    {
        return $this->getUrl('*/*/view', ['id' => $id]);
    }

    /**
     * The HTML body for a sandboxed iframe: scripts and forms are blocked by the sandbox; the CSP blocks remote
     * images, styles and fonts unless the admin chose "Load remote content" on this page.
     */
    public function getPreviewDocument(): string
    {
        $html = $this->getParsed()['html'];
        if ($html === '') {
            return '';
        }
        $policy = $this->isRemoteContentAllowed()
            ? "default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline' https: http:; font-src data: https: http:"
            : "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:";
        $csp = '<meta http-equiv="Content-Security-Policy" content="' . $policy . '">';
        if (preg_match('/<head\b[^>]*>/i', $html) === 1) {
            return (string)preg_replace('/<head\b[^>]*>/i', '$0' . $csp, $html, 1);
        }
        return '<head>' . $csp . '</head>' . $html;
    }
}
