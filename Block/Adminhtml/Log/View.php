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
            $this->content = $this->getLog()->getId() ? $this->logResource->loadContent((int)$this->getLog()->getId()) : null;
        }
        return $this->content;
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
     * The HTML body for a sandboxed iframe: scripts, forms and remote loading are blocked by the sandbox and CSP.
     */
    public function getPreviewDocument(): string
    {
        $html = $this->getParsed()['html'];
        if ($html === '') {
            return '';
        }
        $csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: https: http:; style-src \'unsafe-inline\' https:; font-src https: data:">';
        if (stripos($html, '<head>') !== false) {
            return (string)preg_replace('/<head>/i', '<head>' . $csp, $html, 1);
        }
        return $csp . $html;
    }
}
