<?php

declare(strict_types=1);

class ShopService
{
    /** จำนวนร้านสูงสุดต่อผู้ใช้ 1 คน */
    public const MAX_SHOPS_PER_USER = 20;

    private const SHOP_LIMIT_ERROR = 'ไม่สามารถสร้างร้านค้าเพิ่มได้ (จำกัดสูงสุด '
        . self::MAX_SHOPS_PER_USER . ' ร้านต่อผู้ใช้งาน)';

    private ShopRepository $shopRepository;
    private UserRepository $userRepository;
    private ?PDO $db;

    public function __construct(ShopRepository $shopRepository, UserRepository $userRepository, ?PDO $db = null)
    {
        $this->shopRepository = $shopRepository;
        $this->userRepository = $userRepository;
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

        // ตรวจเบื้องต้นนอกล็อก เพื่อตอบเร็วโดยไม่ต้องเปิดทรานแซกชัน
        // (ค่าที่ใช้ตัดสินจริงคือค่าที่อ่านใต้ล็อกด้านล่าง)
        if ($this->shopRepository->countByUserId($userId) >= self::MAX_SHOPS_PER_USER) {
            return [
                'success' => false,
                'error' => self::SHOP_LIMIT_ERROR,
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
                    $this->userRepository->lockForUpdate($userId);

                    // ต้องเทียบซ้ำใต้ล็อก — เดิมเรียกแล้วทิ้งค่าไป ทำให้ค่าที่ใช้ตัดสินเป็นค่าที่
                    // อ่านก่อนเข้าล็อก สองแท็บที่กดพร้อมกันตอนมี 19-20 ร้านจึงทะลุ 20 ได้
                    // (ไม่มี constraint ระดับ DB มากั้น)
                    if ($this->shopRepository->countByUserIdForUpdate($userId) >= self::MAX_SHOPS_PER_USER) {
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }

                        return [
                            'success' => false,
                            'error' => self::SHOP_LIMIT_ERROR,
                        ];
                    }
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
                    // Prevent race conditions: rename-shop must be serialized per user/shop.
                    $this->userRepository->lockForUpdate($userId);
                    $this->lockShopRowForUpdate($shopId, $userId);
                }
            }

            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
            if ($shop === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'คุณไม่มีสิทธิ์แก้ไขร้านค้านี้',
                ];
            }

            $existingName = trim((string)($shop['name'] ?? ''));
            if ($existingName === $shopName) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                return [
                    'success' => true,
                    'shop' => $shop,
                ];
            }

            $updated = $this->shopRepository->updateNameByIdAndUserId($shopId, $userId, $shopName);
            if (!$updated) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถอัปเดตชื่อร้านค้าได้',
                ];
            }

            $updatedShop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
            if ($updatedShop === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบร้านค้าที่อัปเดตแล้ว',
                ];
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'shop' => $updatedShop,
            ];
        } catch (PDOException $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ((string)$exception->getCode() === '23000') {
                return [
                    'success' => false,
                    'error' => 'ชื่อร้านนี้มีอยู่แล้ว กรุณาใช้ชื่ออื่น',
                ];
            }

            error_log('[shop] renameShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตชื่อร้านค้าได้',
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[shop] renameShop failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตชื่อร้านค้าได้',
            ];
        }
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
                    $this->userRepository->lockForUpdate($userId);
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

    private function lockShopRowForUpdate(int $shopId, int $userId): void
    {
        if (!$this->db instanceof PDO || $shopId <= 0 || $userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM shops WHERE id = :id AND user_id = :user_id LIMIT 1 FOR UPDATE');
        $stmt->execute([
            ':id' => $shopId,
            ':user_id' => $userId,
        ]);
    }
}
