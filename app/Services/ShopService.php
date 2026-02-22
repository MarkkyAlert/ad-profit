<?php

declare(strict_types=1);

class ShopService
{
    private ShopRepository $shopRepository;
    private ?PDO $db;

    public function __construct(ShopRepository $shopRepository, ?PDO $db = null)
    {
        $this->shopRepository = $shopRepository;
        $this->db = $db;
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

        $startedTransaction = false;
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    $this->lockUserRowForUpdate($userId);
                    $this->shopRepository->countByUserIdForUpdate($userId);
                }
            }

            $existingShop = $this->shopRepository->findByNameAndUserId($shopName, $userId);
            if ($existingShop !== null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                return [
                    'success' => true,
                    'shop_id' => (int)($existingShop['id'] ?? 0),
                    'already_exists' => true,
                ];
            }

            $shopId = $this->shopRepository->create($userId, $shopName);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[shop] createShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถสร้างร้านค้าได้',
            ];
        }

        return [
            'success' => true,
            'shop_id' => $shopId,
            'already_exists' => false,
        ];
    }

    public function renameShop(int $userId, int $shopId, string $name): array
    {
        if ($shopId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบร้านค้าที่ต้องการแก้ไข',
            ];
        }

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

        $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์แก้ไขร้านค้านี้',
            ];
        }

        $existingName = trim((string)($shop['name'] ?? ''));
        if ($existingName === $shopName) {
            return [
                'success' => true,
                'shop' => $shop,
            ];
        }

        try {
            $updated = $this->shopRepository->updateNameByIdAndUserId($shopId, $userId, $shopName);
        } catch (Throwable $exception) {
            error_log('[shop] renameShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตชื่อร้านค้าได้',
            ];
        }

        if (!$updated) {
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตชื่อร้านค้าได้',
            ];
        }

        $updatedShop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($updatedShop === null) {
            return [
                'success' => false,
                'error' => 'ไม่พบร้านค้าที่อัปเดตแล้ว',
            ];
        }

        return [
            'success' => true,
            'shop' => $updatedShop,
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

        $startedTransaction = false;
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    $this->lockUserRowForUpdate($userId);
                }
            }

            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
            if ($shop === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'คุณไม่มีสิทธิ์ลบร้านค้านี้',
                ];
            }

            $shopCount = $canLockRows
                ? $this->shopRepository->countByUserIdForUpdate($userId)
                : $this->shopRepository->countByUserId($userId);

            if ($shopCount <= 1) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถลบร้านสุดท้ายได้',
                ];
            }

            $deleted = $this->shopRepository->deleteByIdAndUserId($shopId, $userId);
            if (!$deleted) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบร้านค้าที่ต้องการลบ',
                ];
            }

            $nextShop = $this->shopRepository->getFirstByUserId($userId);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[shop] deleteShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถลบร้านค้าได้',
            ];
        }

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

    private function lockUserRowForUpdate(int $userId): void
    {
        if (!$this->db instanceof PDO || $userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $userId]);
    }
}
