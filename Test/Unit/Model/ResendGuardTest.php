<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Test\Unit\Model;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\ResendGuard;
use CommerceGears\Smtp\Model\Scope;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ResendGuardTest extends TestCase
{
    /**
     * @param int[] $enabledStores store IDs (0 = default) where the module is on
     */
    private function guard(array $enabledStores): ResendGuard
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(
            static fn (?Scope $scope): bool => in_array((int)$scope?->getStoreId(), $enabledStores, true)
        );
        $store = $this->createStub(StoreInterface::class);
        $store->method('getName')->willReturn('German');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static function (int $id) use ($store) {
            if ($id === 99) {
                throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
            }
            return $store;
        });
        return new ResendGuard($config, $storeManager);
    }

    public function testAllowedWhenTheModuleIsOn(): void
    {
        $this->assertNull($this->guard([2])->getBlocker('2'));
        $this->assertNull($this->guard([0])->getBlocker(null));
    }

    public function testRefusedWhenTheModuleIsOffForTheStore(): void
    {
        $blocker = $this->guard([0])->getBlocker(2);
        $this->assertNotNull($blocker);
        $this->assertStringContainsString('switched off', $blocker);
        $this->assertStringContainsString('German', $blocker);
    }

    public function testRefusedForADeletedStore(): void
    {
        $blocker = $this->guard([0, 99])->getBlocker(99);
        $this->assertNotNull($blocker);
        $this->assertStringContainsString('no longer exists', $blocker);
    }
}
