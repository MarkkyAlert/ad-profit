<?php

declare(strict_types=1);

class ShopService
{
    /** จำนวนร้านสูงสุดต่อผู้ใช้ 1 คน */
    public const MAX_SHOPS_PER_USER = 20;

    /**
     * ความยาวชื่อร้านสูงสุด — ต้องเท่ากับ `shops.name` ใน schema เป๊ะ ๆ
     * (`SchemaContractTest` ล็อกคู่นี้ไว้ · เดิมเป็นเลข 100 ลอย ๆ ใน 2 ที่)
     */
    public const MAX_SHOP_NAME_LENGTH = 100;

    /** ความยาวสูงสุดของข้อความยืนยันตอนลบร้าน (ชื่อยาวกว่านี้ให้พิมพ์แค่ส่วนต้น) */
    public const CONFIRM_NAME_MAX_LENGTH = 20;

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

    /**
     * ข้อความที่ผู้ใช้ต้องพิมพ์เพื่อยืนยันการลบร้าน (ชื่อร้าน หรือ 20 ตัวแรกถ้ายาวกว่านั้น)
     *
     * ต้อง trim หลังตัดด้วย — ถ้าตัวที่ 20 พอดีเป็นช่องว่าง ค่าที่ได้จะลงท้ายด้วยช่องว่าง
     * ขณะที่เบราว์เซอร์ส่งค่าที่ trim แล้วเสมอ → ไม่มีวันตรงกัน ลบร้านนั้นไม่ได้ตลอดกาล
     * (เดิมตรรกะนี้อยู่ที่ api/shops.php และตัดโดยไม่ trim)
     */
    public static function confirmationNameFor(string $shopName): string
    {
        $name = trim_unicode_whitespace($shopName);
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($length <= self::CONFIRM_NAME_MAX_LENGTH) {
            return $name;
        }

        $truncated = function_exists('mb_substr')
            ? mb_substr($name, 0, self::CONFIRM_NAME_MAX_LENGTH)
            : substr($name, 0, self::CONFIRM_NAME_MAX_LENGTH);

        return trim_unicode_whitespace($truncated);
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
        $shopName = trim_unicode_whitespace($name);

        if ($shopName === '') {
            return [
                'success' => false,
                'error' => 'กรุณาระบุชื่อร้านค้า',
            ];
        }

        $nameLength = function_exists('mb_strlen') ? mb_strlen($shopName) : strlen($shopName);
        if ($nameLength > self::MAX_SHOP_NAME_LENGTH) {
            return [
                'success' => false,
                'error' => 'ชื่อร้านค้ายาวเกิน ' . self::MAX_SHOP_NAME_LENGTH . ' ตัวอักษร',
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

            // ⭐ เช็ก "ชื่อซ้ำ" ก่อนเช็กโควตาเสมอ
            //
            // ชื่อที่มีอยู่แล้ว = สลับไปร้านนั้นให้ ไม่ได้สร้างแถวใหม่ โควตาจึงไม่เกี่ยว
            // เดิมเช็กโควตาก่อน ทำให้ผู้ใช้ที่มีครบ 20 ร้านพิมพ์ชื่อร้านที่ตัวเองมีอยู่แล้ว
            // ได้ข้อความ "สร้างร้านเพิ่มไม่ได้ (จำกัด 20 ร้าน)" ทั้งที่ไม่ได้จะสร้างอะไรเลย
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

            // โควตา — อ่านใต้ล็อกเมื่อล็อกได้ (สองแท็บที่กดพร้อมกันตอนมี 19-20 ร้าน
            // เคยทะลุ 20 ได้ เพราะไม่มี constraint ระดับ DB มากั้น)
            $shopCount = $canLockRows
                ? $this->shopRepository->countByUserIdForUpdate($userId)
                : $this->shopRepository->countByUserId($userId);

            if ($shopCount >= self::MAX_SHOPS_PER_USER) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => self::SHOP_LIMIT_ERROR,
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

        $shopName = trim_unicode_whitespace($name);
        if ($shopName === '') {
            return [
                'success' => false,
                'error' => 'กรุณาระบุชื่อร้านค้า',
            ];
        }

        $nameLength = function_exists('mb_strlen') ? mb_strlen($shopName) : strlen($shopName);
        if ($nameLength > self::MAX_SHOP_NAME_LENGTH) {
            return [
                'success' => false,
                'error' => 'ชื่อร้านค้ายาวเกิน ' . self::MAX_SHOP_NAME_LENGTH . ' ตัวอักษร',
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

            // ⚠️⚠️ ต้องเทียบกับชื่อ **ที่เก็บอยู่จริง** ไม่ใช่ชื่อที่ normalize แล้ว
            //
            // ร้านเก่าที่ชื่อติดช่องว่างยูนิโค้ด (NBSP จากการก๊อปมาจาก LINE/Word) เทียบแบบ
            // normalize สองฝั่งแล้วจะ "เท่ากันเสมอ" → คืนว่าสำเร็จโดยไม่ UPDATE อะไรเลย
            // ผู้ใช้จึงล้างช่องว่างที่มองไม่เห็นออกจากชื่อร้านไม่ได้ตลอดกาล
            // ทั้งที่ระบบขึ้นว่า "อัปเดตชื่อร้านค้าเรียบร้อยแล้ว" ทุกครั้ง
            $storedName = (string)($shop['name'] ?? '');
            if ($storedName === $shopName) {
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

    /**
     * @param string $confirmName ชื่อร้านที่ผู้ใช้พิมพ์ยืนยัน — ต้องตรงกับ confirmationNameFor()
     */
    public function deleteShop(int $userId, int $shopId, string $confirmName = ''): array
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

                // ⚠️ ตั้งใจไม่ทำ idempotent ที่นี่ ต่างจาก deleteRecord/deleteGoal เพราะ:
                // 1) กรณีนี้แยกไม่ออกระหว่าง "เพิ่งลบไปเอง" กับ "ร้านของผู้ใช้คนอื่น"
                //    (record/goal แยกออกเพราะ shopId มาจาก session ที่ผ่าน userCanAccessShop แล้ว)
                // 2) controller พึ่ง deleted_shop/next_shop ในผลลัพธ์ที่สำเร็จ — ตอบสำเร็จลอย ๆ
                //    จะทำให้ api/shops.php ตอบ 500 แล้วเด้งไป /login.php ซึ่งแย่กว่าเดิม
                // ปรับเฉพาะข้อความให้ไม่กล่าวหาว่าเป็นปัญหาสิทธิ์ เพราะกดลบซ้ำก็มาทางนี้
                return [
                    'success' => false,
                    'error' => 'ไม่พบร้านค้านี้ (อาจถูกลบไปแล้ว)',
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

            // ยืนยันด้วยการพิมพ์ชื่อร้าน — เทียบกับชื่อจากแถวที่ล็อกไว้แล้ว
            // ⚠️ เทียบหลังตัดช่องว่างยูนิโค้ดทั้งสองฝั่ง — ร้านที่ถูกสร้างไว้ก่อนแก้เรื่องนี้
            // (ชื่อยังมี NBSP ติดอยู่) จึงลบได้ทันทีโดยไม่ต้องแก้ข้อมูลเก่า
            if (trim_unicode_whitespace($confirmName) !== self::confirmationNameFor((string)($shop['name'] ?? ''))) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'กรุณาพิมพ์ชื่อร้านให้ตรง เพื่อยืนยันการลบร้าน',
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

    /**
     * ⚠️ เดิมเมธอดนี้เขียน SQL เองซ้ำกับ `ShopRepository::lockForWrite()` ทุกตัวอักษร
     * ซึ่งผิดกฎ "Repository = SQL ล้วน" และทำให้แก้ที่เดียวไม่ครบ
     */
    private function lockShopRowForUpdate(int $shopId, int $userId): void
    {
        if (!$this->db instanceof PDO || $shopId <= 0 || $userId <= 0) {
            return;
        }

        $this->shopRepository->lockForWrite($shopId, $userId);
    }
}
