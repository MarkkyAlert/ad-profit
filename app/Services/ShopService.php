<?php

declare(strict_types=1);

class ShopService
{
    private ShopRepository $shopRepository;

    public function __construct(ShopRepository $shopRepository)
    {
        $this->shopRepository = $shopRepository;
    }

    public function getShopContext(int $userId, ?int $currentShopId): array
    {
        $shops = $this->shopRepository->listByUserId($userId);
        $currentShop = null;

        if ($currentShopId !== null) {
            foreach ($shops as $shop) {
                if ((int)$shop['id'] === $currentShopId) {
                    $currentShop = $shop;
                    break;
                }
            }
        }

        if ($currentShop === null && !empty($shops)) {
            $currentShop = $shops[0];
        }

        return [
            'shops' => $shops,
            'current_shop' => $currentShop,
        ];
    }

    public function createShop(int $userId, string $name): array
    {
        $shopName = trim($name);

        if ($shopName === '') {
            return [
                'success' => false,
                'error' => 'กรุณาระบุชื่อร้านค้า',
            ];
        }

        $nameLength = function_exists('mb_strlen') ? mb_strlen($shopName) : strlen($shopName);
        if ($nameLength > 100) {
            return [
                'success' => false,
                'error' => 'ชื่อร้านค้ายาวเกิน 100 ตัวอักษร',
            ];
        }

        try {
            $shopId = $this->shopRepository->create($userId, $shopName);
        } catch (Throwable $exception) {
            error_log('[shop] createShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถสร้างร้านค้าได้',
            ];
        }

        return [
            'success' => true,
            'shop_id' => $shopId,
        ];
    }

    public function switchShop(int $userId, int $shopId): array
    {
        if ($shopId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบร้านค้าที่ต้องการสลับ',
            ];
        }

        $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        return [
            'success' => true,
            'shop' => $shop,
        ];
    }

    public function deleteShop(int $userId, int $shopId): array
    {
        if ($shopId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบร้านค้าที่ต้องการลบ',
            ];
        }

        $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์ลบร้านค้านี้',
            ];
        }

        if (!$this->canDeleteShop($userId)) {
            return [
                'success' => false,
                'error' => 'ไม่สามารถลบร้านสุดท้ายได้',
            ];
        }

        try {
            $deleted = $this->shopRepository->deleteByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[shop] deleteShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถลบร้านค้าได้',
            ];
        }

        if (!$deleted) {
            return [
                'success' => false,
                'error' => 'ไม่พบร้านค้าที่ต้องการลบ',
            ];
        }

        $nextShop = $this->shopRepository->getFirstByUserId($userId);

        return [
            'success' => true,
            'deleted_shop' => $shop,
            'next_shop' => $nextShop,
        ];
    }

    public function canDeleteShop(int $userId): bool
    {
        return $this->shopRepository->countByUserId($userId) > 1;
    }
}
