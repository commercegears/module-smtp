<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Store view name in one short line ("Admin / default" for emails without a store view).
 */
class StoreName extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly StoreManagerInterface $storeManager,
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
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $names = [];
        foreach ($this->storeManager->getStores() as $store) {
            $names[(int)$store->getId()] = (string)$store->getName();
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $id = isset($item['store_id']) && $item['store_id'] !== '' ? (int)$item['store_id'] : 0;
            $item['store_id'] = $id === 0 ? (string)__('Admin / default') : ($names[$id] ?? (string)__('Deleted store view'));
        }
        unset($item);
        return $dataSource;
    }
}
