<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Ui\Component\Listing\Column;

use CommerceGears\Smtp\Model\Source\Status;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class StatusBadge extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Status $statusSource,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $labels = [];
        foreach ($this->statusSource->toOptionArray() as $option) {
            $labels[$option['value']] = (string)$option['label'];
        }
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $status = (string)($item['status'] ?? '');
            $item['status'] = '<span class="commercegears-smtp-status is-' . $this->escaper->escapeHtmlAttr($status) . '">'
                . $this->escaper->escapeHtml($labels[$status] ?? $status) . '</span>';
        }
        unset($item);
        return $dataSource;
    }
}
