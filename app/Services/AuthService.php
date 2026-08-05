<?php

declare(strict_types=1);

class AuthService
{
    private const DEFAULT_SHOP_NAME = 'ร้านค้าของฉัน';

    /** bucket ที่ผูกกับ IP ล้วน — กัน password spraying ที่ bucket ต่ออีเมลจับไม่ได้ */
    private const LOGIN_IP_ACTION = 'login_ip';

    /**
     * bucket ที่ผูกกับ IP ล้วนของการสมัคร — คู่แฝดของ LOGIN_IP_ACTION ที่ตกหล่น
     *
     * bucket เดิมผูกกับ (IP + อีเมล) ซึ่งเปลี่ยนกุญแจทุกครั้งที่เปลี่ยนอีเมลที่ลอง
     * เครื่องเดียวจึงยิงทดสอบได้ไม่จำกัดว่าอีเมลไหนมีในระบบแล้วบ้าง
     */
    private const REGISTER_IP_ACTION = 'register_ip';

    /**
     * bucket ที่ผูกกับ **อีเมลอย่างเดียว** — ไม่สนใจว่ามาจากเครื่องไหน
     *
     * bucket อีก 2 ตัวผูกกับ IP ทั้งคู่ คนร้ายที่หมุนเปลี่ยน IP ไปเรื่อย ๆ จึงไล่เดา
     * บัญชีเดียวได้ไม่จำกัด (แต่ละ IP ใหม่เริ่มนับ 0)
     *
     * ⚠️⚠️ ตัวนี้ **ห้ามปฏิเสธคำขอ** — ให้หน่วงเวลาอย่างเดียว
     * ถ้าปฏิเสธ คนร้ายจะพิมพ์รหัสผิดรัว ๆ เพื่อล็อกบัญชีของเหยื่อไม่ให้เข้าใช้เองได้
     * (เปลี่ยนจากช่องโหว่หนึ่งไปเป็นอีกช่องโหว่หนึ่ง) · การหน่วงทำให้การไล่เดาแพงขึ้น
     * มากโดยที่เจ้าของบัญชีที่พิมพ์รหัส **ถูก** ยังเข้าได้เสมอ
     */
    private const LOGIN_ACCOUNT_ACTION = 'login_account';

    /** เวลารอตั้งต้นเมื่อเกินเพดานครั้งแรก (คูณสองไปเรื่อย ๆ จนถึงเพดาน) */
    private const THROTTLE_BASE_DELAY_MS = 500;

    /** cache ของ dummy hash ต่อ process — คิดครั้งเดียวแล้วใช้ซ้ำ */
    private static ?string $dummyPasswordHash = null;

    private PDO $db;
    private UserRepository $userRepository;
    private ShopRepository $shopRepository;
    private ?PasswordResetRepository $passwordResetRepository;
    private ?EmailService $emailService;
    private ?bool $databaseRateLimitReady = null;

    public function __construct(
        PDO $db,
        UserRepository $userRepository,
        ShopRepository $shopRepository,
        ?PasswordResetRepository $passwordResetRepository = null,
        ?EmailService $emailService = null
    ) {
        $this->db = $db;
        $this->userRepository = $userRepository;
        $this->shopRepository = $shopRepository;
        $this->passwordResetRepository = $passwordResetRepository;
        $this->emailService = $emailService;
    }

    public function register(string $email, string $password, string $passwordConfirm, string $clientIp): array
    {
        $normalizedEmail = normalize_email($email);

        // จองก่อนเหมือน login — หน้าต่างระหว่างอ่านกับเขียนมี `password_hash()` อยู่
        // (ดูคำอธิบายเต็มที่ `login()`) · สมัครสำเร็จจะคืนโควตาของ bucket ต่อ IP ให้
        $registerAttempts = $this->reserveAttempt('register', $clientIp, $normalizedEmail);
        $registerIpAttempts = $this->reserveAttempt(self::REGISTER_IP_ACTION, $clientIp);

        if ($registerAttempts > $this->getMaxAttemptsForAction('register')
            || $registerIpAttempts > $this->getMaxAttemptsForAction(self::REGISTER_IP_ACTION)) {
            return [
                'success' => false,
                'error' => 'ลองสมัครบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || !is_valid_email($normalizedEmail)) {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
            ];
        }

        if (strlen($normalizedEmail) > 255) {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            return [
                'success' => false,
                'error' => 'อีเมลยาวเกินไป',
            ];
        }

        $passwordError = validate_password_length($password);
        if ($passwordError !== null) {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            return [
                'success' => false,
                'error' => $passwordError,
            ];
        }

        if ($password !== $passwordConfirm) {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            return [
                'success' => false,
                'error' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        if ($this->userRepository->findByEmail($normalizedEmail) !== null) {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            return [
                'success' => false,
                'error' => 'ไม่สามารถสมัครสมาชิกได้ กรุณาตรวจสอบข้อมูลแล้วลองใหม่อีกครั้ง',
            ];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            error_log('[auth] Unable to create password hash during register');
            return [
                'success' => false,
                'error' => 'ไม่สามารถสร้างบัญชีได้ในขณะนี้',
            ];
        }

        $sessionVersion = 1;

        try {
            $this->db->beginTransaction();

            $userId = $this->userRepository->create($normalizedEmail, $passwordHash);
            $shopId = $this->shopRepository->create($userId, self::DEFAULT_SHOP_NAME);
            $this->userRepository->updateLastLoginAt($userId);
            $sessionVersion = $this->userRepository->getSessionVersion($userId);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ
            error_log('[auth][register] ' . $exception->getMessage());

            $isDuplicateUser = $exception instanceof PDOException && $exception->getCode() === '23000';
            if ($isDuplicateUser) {
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถสมัครสมาชิกได้ กรุณาตรวจสอบข้อมูลแล้วลองใหม่อีกครั้ง',
                ];
            }

            return [
                'success' => false,
                'error' => 'ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่อีกครั้ง',
            ];
        }

        $this->establishSession($userId, $normalizedEmail, $shopId, self::DEFAULT_SHOP_NAME, $sessionVersion);
        $this->releaseAttempt(self::REGISTER_IP_ACTION, $clientIp);
        $this->clearRateLimit('register', $clientIp, $normalizedEmail);
        // ไม่ล้าง bucket ของ IP โดยตั้งใจ — เหตุผลเดียวกับฝั่งล็อกอิน:
        // สมัครสำเร็จ 1 ครั้งไม่ควรล้างประวัติการไล่ทดสอบอีเมลอื่นทิ้ง

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    public function login(string $email, string $password, string $clientIp): array
    {
        $normalizedEmail = normalize_email($email);

        // ⚠️⚠️ ต้อง **นับก่อน แล้วค่อยตรวจรหัสผ่าน** ไม่ใช่ "ถามว่าเกินหรือยัง" แล้วค่อยนับทีหลัง
        //
        // เดิมเป็น check-then-act โดยมี `password_verify()` (bcrypt ~100ms) คั่นกลาง
        // คำขอที่ยิงพร้อมกันจึงอ่านค่าเดียวกันหมดก่อนที่ใครจะเพิ่มเลข · วัดจริงแล้ว:
        // เพดาน 5 ครั้ง แต่ยิงพร้อมกัน 40 ครั้งผ่านเข้าไปตรวจรหัสผ่านได้ **28 ครั้ง**
        // (ยิงทีละครั้งผ่าน 5 ตามที่ควร) — bucket ต่อ IP ก็ทะลุพร้อมกัน ไม่มีตัวรอง
        //
        // การเพิ่มเลขเป็น atomic อยู่แล้ว (`ON DUPLICATE KEY UPDATE attempts + 1`)
        // จองคิวก่อนแล้วดูเลขที่ได้ จึงนับได้แม่นแม้ยิงพร้อมกัน · สำเร็จแล้วล้าง bucket
        // ต่อบัญชีทิ้ง (ทำอยู่แล้วด้านล่าง) คนที่รหัสถูกจึงไม่โดนกักจากความพยายามของตัวเอง
        // 2 ตัว: ต่อ (IP + อีเมล) กันเดารหัสบัญชีเดียว · ต่อ IP ล้วนกัน password spraying
        $accountAttempts = $this->reserveAttempt('login', $clientIp, $normalizedEmail);
        $ipAttempts = $this->reserveAttempt(self::LOGIN_IP_ACTION, $clientIp);

        if ($accountAttempts > $this->getMaxAttemptsForAction('login')
            || $ipAttempts > $this->getMaxAttemptsForAction(self::LOGIN_IP_ACTION)) {
            return [
                'success' => false,
                'error' => 'ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || $password === '') {
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลและรหัสผ่าน',
            ];
        }

        // bucket ต่อบัญชี (ไม่ผูกกับ IP) — จองก่อนตรวจเหมือนตัวอื่น แต่ผลของมันคือ
        // **เวลาที่ต้องรอ** ไม่ใช่การปฏิเสธ · คำนวณไว้ก่อน ใช้ก็ต่อเมื่อรหัสผิดจริง
        // (รหัสถูก = ไม่ต้องรอ แล้วล้าง bucket ทิ้ง)
        $accountThrottleAttempts = $this->reserveAttempt(self::LOGIN_ACCOUNT_ACTION, '', $normalizedEmail);

        $user = $this->userRepository->findByEmail($normalizedEmail);
        if ($user === null) {
            // เผาเวลาเท่ากับการ verify จริง ไม่งั้นเวลาตอบบอกได้ว่าอีเมลไหนมีในระบบ
            password_verify($password, self::dummyPasswordHash());
            // ⚠️ ต้องหน่วงเท่ากับทางที่ "รหัสผิด" เป๊ะ ๆ ไม่งั้นเวลาที่ใช้ตอบจะบอกได้
            // ว่าอีเมลนี้มีบัญชีอยู่จริงไหม (ซึ่งเป็นสิ่งที่ข้อความตอบกลับตั้งใจปิดไว้)
            $this->applyAccountThrottleDelay($accountThrottleAttempts);
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจรหัสผ่าน
            return [
                'success' => false,
                'error' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $passwordHash = (string)($user['password_hash'] ?? '');
        if (!password_verify($password, $passwordHash)) {
            $this->applyAccountThrottleDelay($accountThrottleAttempts);

            return [
                'success' => false,
                'error' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $userId = (int)$user['id'];
        $userEmail = (string)$user['email'];
        $shop = $this->shopRepository->getFirstByUserId($userId);

        if ($shop === null) {
            try {
                $shopId = $this->shopRepository->create($userId, self::DEFAULT_SHOP_NAME);
                $shopName = self::DEFAULT_SHOP_NAME;
            } catch (Throwable $exception) {
                $isDuplicateShop = $exception instanceof PDOException && (string)$exception->getCode() === '23000';
                if ($isDuplicateShop) {
                    $shop = $this->shopRepository->getFirstByUserId($userId);
                    if ($shop !== null) {
                        $shopId = (int)$shop['id'];
                        $shopName = (string)$shop['name'];
                    } else {
                        error_log('[auth][login] Duplicate shop creation detected but no shop found: ' . $exception->getMessage());
                        return [
                            'success' => false,
                            'error' => 'ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่อีกครั้ง',
                        ];
                    }
                } else {
                    error_log('[auth][login] Unable to create default shop: ' . $exception->getMessage());
                    return [
                        'success' => false,
                        'error' => 'ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่อีกครั้ง',
                    ];
                }
            }
        } else {
            $shopId = (int)$shop['id'];
            $shopName = (string)$shop['name'];
        }

        $sessionVersion = 1;
        try {
            $sessionVersion = $this->userRepository->getSessionVersion($userId);
        } catch (Throwable $exception) {
            error_log('[auth][login] Unable to load session_version: ' . $exception->getMessage());
        }

        try {
            $this->userRepository->updateLastLoginAt($userId);
        } catch (Throwable $exception) {
            error_log('[auth][login] updateLastLoginAt failed: ' . $exception->getMessage());
        }
        $this->establishSession($userId, $userEmail, $shopId, $shopName, $sessionVersion);
        // ⚠️⚠️ ต้องคืนโควตาที่จองไว้ของ bucket ต่อ IP ด้วย
        //
        // เราจองก่อนตรวจรหัสผ่าน (เพื่อกันการยิงพร้อมกัน) แปลว่าการล็อกอิน **ที่สำเร็จ**
        // ก็กินโควตาไปด้วย · bucket ต่อ IP ตั้งใจไม่ล้างตอนสำเร็จ (ไม่งั้นคนที่ไล่เดา
        // บัญชีอื่นจะล้างประวัติตัวเองทิ้งได้) ผลคือออฟฟิศที่ใช้เน็ตร่วมกัน พอมีคน
        // ล็อกอินสำเร็จครบ 5 คน คนที่ 6 ที่พิมพ์รหัส **ถูก** จะถูกปฏิเสธ (วัดจริงแล้ว)
        // → คืนเฉพาะครั้งที่สำเร็จ ความพยายามที่ล้มเหลวยังนับอยู่ครบเหมือนเดิม
        $this->releaseAttempt(self::LOGIN_IP_ACTION, $clientIp);
        $this->clearRateLimit('login', $clientIp, $normalizedEmail);
        // รหัสถูก = ไม่ใช่การไล่เดา → ล้างประวัติของบัญชีนี้ทิ้ง เจ้าของบัญชีที่เพิ่งพิมพ์
        // ผิดไปหลายครั้งจึงกลับมาเร็วเหมือนเดิมทันที ไม่ต้องรอให้หน้าต่างหมดอายุ
        $this->clearRateLimit(self::LOGIN_ACCOUNT_ACTION, '', $normalizedEmail);
        // ไม่ล้าง bucket ของ IP — ไม่งั้นล็อกอินสำเร็จ 1 บัญชีจะล้างประวัติการเดาบัญชีอื่นทิ้ง

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    /**
     * ล้าง session ทั้งก้อน ไม่ใช่เฉพาะ key ที่ระบุไว้
     *
     * เดิม unset ทีละ key ทำให้ของที่ไม่ได้อยู่ในลิสต์ติดข้ามการ logout ไป เช่น
     * auth_rate_limits (ตัวนับ fallback) — คนถัดไปที่ใช้เครื่องเดียวกันรับตัวนับนั้นต่อ
     * flash ที่ตอบหลัง logout ถูกเขียนทีหลัง จึงไม่ได้รับผลกระทบ
     */
    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function requestPasswordReset(string $email, string $clientIp): array
    {
        if ($this->passwordResetRepository === null) {
            return [
                'success' => false,
                'error' => 'ระบบรีเซ็ตรหัสผ่านไม่พร้อมใช้งาน',
            ];
        }

        $normalizedEmail = normalize_email($email);

        // เพดานแค่ 1 ครั้งต่อหน้าต่าง — ถ้าถามก่อนนับ คำขอที่มาพร้อมกันจะส่งอีเมล
        // ซ้ำหลายฉบับให้เจ้าของบัญชีเดียวกัน
        if ($this->reserveAttempt('password_reset', $clientIp, $normalizedEmail)
            > $this->getMaxAttemptsForAction('password_reset')) {
            return [
                'success' => false,
                'error' => 'ลองขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่',
            ];
        }

        // Count every password-reset request in the window to prevent abuse/spam.
        $this->markFailedAttempt('password_reset', $clientIp, $normalizedEmail);

        if ($normalizedEmail === '' || !is_valid_email($normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
            ];
        }

        $hasAbsoluteAppUrl = preg_match('#^https?://#i', APP_URL) === 1;
        if (APP_ENV === 'production' && !$hasAbsoluteAppUrl) {
            error_log('[auth][password_reset] APP_URL must be an absolute URL in production for password reset links');
            return [
                'success' => false,
                'error' => 'ระบบยังไม่พร้อมใช้งานในขณะนี้',
            ];
        }

        $user = $this->userRepository->findByEmail($normalizedEmail);
        if ($user === null) {
            return [
                'success' => true,
                'message' => 'หากอีเมลนี้มีอยู่ในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน',
            ];
        }

        $userId = (int)$user['id'];
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        try {
            // ส่งเป็นชั่วโมง ให้ MySQL บวกเวลาเอง — นาฬิกาเดียวกับตอนตรวจ expires_at > NOW()
            $this->passwordResetRepository->createToken($userId, $tokenHash, (int)PASSWORD_RESET_TOKEN_TTL_HOURS);
        } catch (Throwable $exception) {
            error_log('[auth][password_reset] ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถสร้างลิงก์รีเซ็ตรหัสผ่านได้',
            ];
        }

        $resetLink = app_url('/reset-password.php?token=' . $token);
        $emailSent = false;

        // ไม่ได้ตั้ง SMTP = ระบบสร้าง token แล้วทิ้ง ผู้ใช้รอลิงก์ที่ไม่มีวันมา
        // ต้องมีร่องรอยใน log เสมอ ไม่งั้นสืบไม่ได้ว่าทำไม "ลืมรหัสผ่าน" ใช้ไม่ได้
        // (ข้อความที่ตอบผู้ใช้ยังเหมือนเดิมทุกกรณี เพื่อไม่ให้เดาได้ว่าอีเมลไหนมีในระบบ)
        if ($this->emailService === null || !$this->emailService->isEnabled()) {
            error_log('[auth][password_reset] mail is not configured (MAIL_ENABLED/credentials) '
                . '- reset link was generated but not delivered');
        } else {
            $emailSent = $this->emailService->sendPasswordResetEmail($normalizedEmail, $resetLink);

            if (!$emailSent) {
                error_log('[auth][password_reset] mail delivery failed after retries');
            }
        }

        $response = [
            'success' => true,
            'message' => 'หากอีเมลนี้มีอยู่ในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน',
            'email_sent' => $emailSent,
        ];

        if (APP_ENV === 'development' && EXPOSE_DEV_RESET_LINK) {
            $response['token'] = $token;
            $response['email'] = $normalizedEmail;
        }

        return $response;
    }

    public function resetPassword(string $token, string $newPassword, string $passwordConfirm, string $clientIp): array
    {
        if ($this->passwordResetRepository === null) {
            return [
                'success' => false,
                'error' => 'ระบบรีเซ็ตรหัสผ่านไม่พร้อมใช้งาน',
            ];
        }

        $rateLimitSubject = '';
        // มี `password_hash()` อยู่ในหน้าต่างเช่นกัน — จองก่อน
        if ($this->reserveAttempt('reset_password', $clientIp, $rateLimitSubject)
            > $this->getMaxAttemptsForAction('reset_password')) {
            return [
                'success' => false,
                'error' => 'ลองรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่',
            ];
        }

        if ($token === '') {
            // ไม่ต้องนับซ้ำ — จองคิวไปแล้วก่อนตรวจ (เดิมนับ 2 ครั้งต่อการกด 1 ครั้ง)
            return [
                'success' => false,
                'error' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง',
            ];
        }

        // ⚠️⚠️ กรอกฟอร์มผิด **ไม่ใช่** ความพยายามรีเซ็ต ต้องคืนโควตาที่จองไว้
        //
        // เดิมนับทุกการกดส่ง ผู้ใช้ที่พิมพ์ช่องยืนยันไม่ตรงกัน 5 ครั้ง (เกิดบ่อยมาก
        // บนมือถือ) จะถูกกันด้วยข้อความ "ลองรีเซ็ตรหัสผ่านบ่อยเกินไป" ทั้งที่ยังไม่ได้
        // รีเซ็ตอะไรสำเร็จสักครั้ง แล้วต้องรอเต็มนาทีทั้งที่ลิงก์ยังใช้ได้อยู่ (วัดจริงแล้ว)
        //
        // ⚠️ ถังนี้ผูกกับ IP ล้วน ออฟฟิศ/เน็ตมือถือที่ใช้ IP ร่วมกันจึงแชร์โควตากันทั้งตึก
        // ยิ่งต้องไม่เอาความผิดพลาดในการพิมพ์ไปกินโควตาของคนอื่น
        //
        // หลักนี้ระบบทำถูกอยู่แล้วที่อื่น — `register()` มี "ไม่ต้องนับซ้ำ" กำกับทุกทาง
        // ที่ล้มเพราะกรอกฟอร์มผิด และ `api/profile.php` นับเฉพาะตอนรหัสปัจจุบันผิดจริง
        $passwordError = validate_password_length($newPassword);
        if ($passwordError !== null) {
            $this->releaseAttempt('reset_password', $clientIp, $rateLimitSubject);

            return [
                'success' => false,
                'error' => $passwordError,
            ];
        }

        if ($newPassword !== $passwordConfirm) {
            $this->releaseAttempt('reset_password', $clientIp, $rateLimitSubject);

            return [
                'success' => false,
                'error' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        $tokenHash = hash('sha256', $token);
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!is_string($passwordHash) || $passwordHash === '') {
            error_log('[auth][reset_password] Unable to create password hash');
            return [
                'success' => false,
                'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้ในขณะนี้',
            ];
        }

        $startedTransaction = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $tokenRecord = $this->passwordResetRepository->findByTokenHashForUpdate($tokenHash);
            if ($tokenRecord === null) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->markFailedAttempt('reset_password', $clientIp, $rateLimitSubject);

                return [
                    'success' => false,
                    'error' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว',
                ];
            }

            $userId = (int)$tokenRecord['user_id'];
            $updatedPassword = $this->userRepository->updatePasswordHash($userId, $passwordHash);
            if (!$updatedPassword) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้',
                ];
            }

            $this->userRepository->incrementSessionVersion($userId);

            $deletedToken = $this->passwordResetRepository->deleteByUserId($userId);
            if (!$deletedToken) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้',
                ];
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[auth][reset_password] ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้',
            ];
        }

        $this->clearRateLimit('reset_password', $clientIp, $rateLimitSubject);

        return [
            'success' => true,
            'message' => 'รีเซ็ตรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่',
        ];
    }

    private function establishSession(int $userId, string $email, int $shopId, string $shopName, int $sessionVersion): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = $email;
        $_SESSION['session_version'] = max(1, $sessionVersion);
        $_SESSION['auth_started_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['current_shop_id'] = $shopId;
        $_SESSION['current_shop_name'] = $shopName;
    }

    /**
     * hash ทิ้งสำหรับเผาเวลาให้เท่ากับการ verify จริงเมื่อไม่พบอีเมล
     *
     * ⚠️ ต้องสร้างด้วย PASSWORD_DEFAULT ตอน runtime ไม่ใช่ hardcode — cost ของ
     * PASSWORD_DEFAULT ต่างกันตามเวอร์ชัน PHP (8.2/8.3 = 10 · 8.4+ = 12) การ hardcode
     * cost 12 ทำให้บนเซิร์ฟเวอร์ PHP 8.3 เส้นทาง "ไม่มีอีเมลนี้" ช้ากว่าเส้นทางปกติ
     * ~4 เท่า = ยังบอกใบ้ได้ว่าอีเมลไหนมีในระบบ แค่กลับทิศ
     *
     * plaintext ถูกทิ้งไปทันที จึงไม่มีรหัสผ่านใดตรงกับ hash นี้
     */
    private static function dummyPasswordHash(): string
    {
        if (self::$dummyPasswordHash === null) {
            self::$dummyPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }

        return self::$dummyPasswordHash;
    }

    /** นับความพยายามที่ล้มเหลวทั้ง bucket ต่อบัญชี และ bucket ต่อ IP */
    private function markFailedLoginAttempt(string $clientIp, string $normalizedEmail): void
    {
        $this->markFailedAttempt('login', $clientIp, $normalizedEmail);
        $this->markFailedAttempt(self::LOGIN_IP_ACTION, $clientIp);
    }

    /** เหมือนกันกับฝั่งล็อกอิน — สมัครล้มเหลวต้องนับทั้ง 2 bucket */
    private function markFailedRegisterAttempt(string $clientIp, string $normalizedEmail): void
    {
        $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
        $this->markFailedAttempt(self::REGISTER_IP_ACTION, $clientIp);
    }

    private function isRateLimited(string $action, string $clientIp, string $subject = ''): bool
    {
        if ($this->canUseDatabaseRateLimit()) {
            try {
                return $this->isRateLimitedInDatabase($action, $clientIp, $subject);
            } catch (Throwable $exception) {
                $this->demoteToSessionLimiter('DB read failed', $exception);
            }
        }

        $bucket = $this->getRateLimitBucket($action, $clientIp, $subject);

        return (int)$bucket['attempts'] >= $this->getMaxAttemptsForAction($action);
    }

    /**
     * นับความพยายาม 1 ครั้งแบบ atomic แล้วคืน "เลขที่นับได้หลังบวกแล้ว"
     *
     * ⚠️ ต่างจาก `isRateLimited()` ตรงที่ **ไม่ใช่การถาม แต่เป็นการจอง** — คำขอที่ยิง
     * พร้อมกันจึงได้เลขคนละตัวเสมอ ไม่ใช่ทุกคนอ่านค่าเดิมแล้วผ่านไปพร้อมกัน
     *
     * คืน `PHP_INT_MAX` เมื่อจองไม่สำเร็จเลย เพื่อให้ฝั่งเรียก "ปฏิเสธไว้ก่อน"
     * (ปลอดภัยกว่าปล่อยผ่านตอนตัวจำกัดใช้งานไม่ได้)
     */
    private function reserveAttempt(string $action, string $clientIp, string $subject = ''): int
    {
        if ($this->canUseDatabaseRateLimit()) {
            // ⚠️ คำขอที่ยิงพร้อมกันจำนวนมากทำให้ MySQL ตัดบางตัวทิ้งด้วย deadlock (1213)
            // ซึ่งเป็นสถานการณ์เดียวกับที่ตัวจำกัดนี้มีไว้กัน · ลองใหม่สั้น ๆ ก่อน
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $this->markFailedAttemptInDatabase($action, $clientIp, $subject);

                    return $this->currentAttemptsInDatabase($action, $clientIp, $subject);
                } catch (Throwable $exception) {
                    if ($attempt < 3 && $this->isRetryableLockError($exception)) {
                        usleep(random_int(2000, 12000));
                        continue;
                    }

                    // ⚠️⚠️ **ห้ามตกไปใช้ตัวนับใน session** — process ที่เพิ่งเกิดมี
                    // ตัวนับเป็น 0 เสมอ การยิงพร้อมกันจึงผ่านฉลุยทุกตัว = ตัวจำกัด
                    // เปิดช่องให้พอดีกับตอนที่ต้องการมันที่สุด (วัดจริงแล้ว 16 จาก 40)
                    // จองไม่ได้ = ปฏิเสธไว้ก่อน ปลอดภัยกว่าปล่อยผ่าน
                    error_log('[auth] reserveAttempt failed, denying by default: ' . $exception->getMessage());

                    return PHP_INT_MAX;
                }
            }
        }

        $bucket = $this->getRateLimitBucket($action, $clientIp, $subject);
        $attempts = (int)$bucket['attempts'] + 1;
        $bucket['attempts'] = $attempts;
        $_SESSION['auth_rate_limits'][$this->rateLimitKey($action, $clientIp, $subject)] = $bucket;

        return $attempts;
    }

    /**
     * คืนโควตา 1 ครั้งที่จองไว้ — ใช้เมื่อผลลัพธ์ออกมาว่า "ไม่ใช่ความพยายามที่ล้มเหลว"
     *
     * ⚠️ ต่างจาก `clearRateLimit()` ที่ล้างทั้ง bucket — ตัวนี้ลบแค่ครั้งที่เราจองไป
     * ประวัติความพยายามของคนอื่นที่ใช้ IP เดียวกันยังอยู่ครบ
     */
    private function releaseAttempt(string $action, string $clientIp, string $subject = ''): void
    {
        if (!$this->canUseDatabaseRateLimit()) {
            $bucket = $this->getRateLimitBucket($action, $clientIp, $subject);
            $bucket['attempts'] = max(0, (int)$bucket['attempts'] - 1);
            $_SESSION['auth_rate_limits'][$this->rateLimitKey($action, $clientIp, $subject)] = $bucket;

            return;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE auth_rate_limits
                 SET attempts = GREATEST(0, attempts - 1), updated_at = NOW()
                 WHERE bucket_key = :bucket_key'
            );
            $stmt->execute([':bucket_key' => $this->rateLimitKey($action, $clientIp, $subject)]);
        } catch (Throwable $exception) {
            // คืนไม่ได้ = นับเกินไป 1 ครั้ง ซึ่งเข้มกว่าที่ควรแต่ไม่เปิดช่อง — ไม่ต้องล้ม
            error_log('[auth] releaseAttempt failed: ' . $exception->getMessage());
        }
    }

    /**
     * เวลาที่ต้องรอ (มิลลิวินาที) เมื่อบัญชีนี้ถูกลองรหัสผิดเกินเพดาน
     *
     * ยังไม่เกินเพดาน = ไม่รอเลย (ผู้ใช้ทั่วไปที่พิมพ์ผิดไม่กี่ครั้งไม่รู้สึกอะไร)
     * เกินแล้วรอเพิ่มเป็นเท่าตัวไปเรื่อย ๆ จนถึงเพดานเวลารอ
     *
     * ⚠️ ต้องมีเพดานเวลารอ — ไม่งั้นการยิงจำนวนมากจะทำให้ PHP ค้างรอกันเต็มเครื่อง
     * (กลายเป็นคนร้ายล่มเว็บได้ด้วยการเดารหัส ซึ่งแย่กว่าเดิม)
     */
    protected function accountThrottleDelayMilliseconds(int $attempts, ?int $maxDelayMs = null): int
    {
        $over = $attempts - $this->getMaxAttemptsForAction(self::LOGIN_ACCOUNT_ACTION);
        if ($over <= 0) {
            return 0;
        }

        // ⚠️ รับเพดานเข้ามาได้ เพื่อให้เทสต์เห็น "กติกาคูณสอง" จริง ๆ
        // เพดานที่เทสต์ใช้ (400) ต่ำกว่าค่าตั้งต้น (500) การคูณสองจึงถูกเพดานกลบทุกครั้ง
        // เทสต์เลยผ่านแม้เปลี่ยน `min` เป็น `max` ซึ่งจะทำให้ผิดครั้งที่ 39 หน่วงนาน
        // 36 ชั่วโมง (พิสูจน์แล้วว่าเทสต์ชุดเดิมเขียวหมดกับสูตรที่พังแบบนั้น)
        $maxDelay = $maxDelayMs ?? (int)LOGIN_ACCOUNT_MAX_DELAY_MS;
        if ($over >= 20) {
            // 2 ** 20 ก็เกินเพดานไปไกลแล้ว ไม่ต้องคิดต่อให้เลขล้น
            return $maxDelay;
        }

        return (int)min($maxDelay, self::THROTTLE_BASE_DELAY_MS * (2 ** ($over - 1)));
    }

    /**
     * หน่วงจริง — แยกจากการคำนวณเพื่อให้เทสต์ตรวจตัวเลขได้โดยไม่ต้องรอ
     *
     * ⚠️ `protected` เพื่อให้เทสต์สืบทอดแล้วดักดูว่า "ทางไหนเรียกมันบ้าง" ได้
     * การพิสูจน์ด้วยการจับเวลาล็อกอินทั้งกระบวนการใช้ไม่ได้ - สัญญาณคือ 400ms
     * แต่เวลาตรวจรหัสผ่านแกว่งเป็นหลักวินาทีเมื่อเครื่องมีภาระ เทสต์จึงเดี๋ยวเขียว
     * เดี๋ยวแดง (เกิดขึ้นจริง: ผ่านตอนรันไฟล์เดียว แดงตอนรันทั้งชุด)
     */
    protected function applyAccountThrottleDelay(int $attempts): void
    {
        $delayMs = $this->accountThrottleDelayMilliseconds($attempts);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /** deadlock (1213) / รอล็อกนานเกินไป (1205) — ลองใหม่ได้ ไม่ใช่ระบบพัง */
    private function isRetryableLockError(Throwable $exception): bool
    {
        $code = $exception instanceof PDOException ? (string)($exception->errorInfo[1] ?? '') : '';

        return $code === '1213' || $code === '1205';
    }

    /** จำนวนครั้งในหน้าต่างปัจจุบัน (0 เมื่อหน้าต่างหมดอายุแล้ว) */
    private function currentAttemptsInDatabase(string $action, string $clientIp, string $subject = ''): int
    {
        $sql = 'SELECT attempts, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS window_age_seconds
                FROM auth_rate_limits
                WHERE bucket_key = :bucket_key
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':bucket_key' => $this->rateLimitKey($action, $clientIp, $subject)]);
        $row = $stmt->fetch();

        if ($row === false) {
            return 0;
        }

        return (int)$row['window_age_seconds'] >= $this->getWindowSecondsForAction($action)
            ? 0
            : (int)$row['attempts'];
    }

    private function markFailedAttempt(string $action, string $clientIp, string $subject = ''): void
    {
        if ($this->canUseDatabaseRateLimit()) {
            try {
                $this->markFailedAttemptInDatabase($action, $clientIp, $subject);
                return;
            } catch (Throwable $exception) {
                $this->demoteToSessionLimiter('DB write failed', $exception);
            }
        }

        $bucket = $this->getRateLimitBucket($action, $clientIp, $subject);
        $bucket['attempts'] = (int)$bucket['attempts'] + 1;

        $key = $this->rateLimitKey($action, $clientIp, $subject);
        $_SESSION['auth_rate_limits'][$key] = $bucket;
    }

    private function clearRateLimit(string $action, string $clientIp, string $subject = ''): void
    {
        if ($this->canUseDatabaseRateLimit()) {
            try {
                $this->clearRateLimitInDatabase($action, $clientIp, $subject);
                return;
            } catch (Throwable $exception) {
                $this->demoteToSessionLimiter('DB clear failed', $exception);
            }
        }

        if (!isset($_SESSION['auth_rate_limits']) || !is_array($_SESSION['auth_rate_limits'])) {
            return;
        }

        $key = $this->rateLimitKey($action, $clientIp, $subject);
        unset($_SESSION['auth_rate_limits'][$key]);
    }

    private function getRateLimitBucket(string $action, string $clientIp, string $subject = ''): array
    {
        if (!isset($_SESSION['auth_rate_limits']) || !is_array($_SESSION['auth_rate_limits'])) {
            $_SESSION['auth_rate_limits'] = [];
        }

        $key = $this->rateLimitKey($action, $clientIp, $subject);
        $now = time();

        $bucket = $_SESSION['auth_rate_limits'][$key] ?? [
            'attempts' => 0,
            'started_at' => $now,
        ];

        $startedAt = (int)($bucket['started_at'] ?? $now);
        $attempts = max(0, (int)($bucket['attempts'] ?? 0));

        if (($now - $startedAt) >= $this->getWindowSecondsForAction($action)) {
            $startedAt = $now;
            $attempts = 0;
        }

        $normalizedBucket = [
            'attempts' => $attempts,
            'started_at' => $startedAt,
        ];

        $_SESSION['auth_rate_limits'][$key] = $normalizedBucket;

        return $normalizedBucket;
    }

    /**
     * DB rate limiter ใช้ไม่ได้ → ย้ายไปใช้ session limiter ตลอด request นี้
     *
     * สำคัญที่ต้องปิดทั้งฝั่งอ่านและเขียนพร้อมกัน: ถ้าเขียนลง DB ไม่ได้แต่ฝั่งอ่านยังถาม DB
     * ต่อไป จะได้ "ไม่มีแถว = ยังไม่ถูกจำกัด" เสมอ ขณะที่ตัวนับใน session ถูกเขียนทิ้งไว้
     * โดยไม่มีใครอ่าน — เท่ากับไม่มี rate limit เลย
     */
    private function demoteToSessionLimiter(string $stage, Throwable $exception): void
    {
        $this->databaseRateLimitReady = false;
        error_log('[auth][rate_limit] ' . $stage . ', fallback to session limiter: ' . $exception->getMessage());
    }

    private function rateLimitKey(string $action, string $clientIp, string $subject = ''): string
    {
        $normalizedSubject = strtolower(trim($subject));

        return hash('sha256', $action . '|' . $clientIp . '|' . $normalizedSubject);
    }

    private function canUseDatabaseRateLimit(): bool
    {
        if ($this->databaseRateLimitReady !== null) {
            return $this->databaseRateLimitReady;
        }

        try {
            $stmt = $this->db->query('SELECT 1 FROM auth_rate_limits LIMIT 1');
            if ($stmt !== false) {
                $stmt->fetch();
            }

            $this->databaseRateLimitReady = true;
            return true;
        } catch (Throwable $exception) {
            $this->databaseRateLimitReady = false;

            if ($exception instanceof PDOException && $this->isMissingAuthRateLimitTableError($exception)) {
                error_log('[auth][rate_limit] auth_rate_limits table not found, fallback to session-based rate limiting');
                return false;
            }

            error_log('[auth][rate_limit] DB rate-limit probe failed: ' . $exception->getMessage());
            return false;
        }
    }

    private function isRateLimitedInDatabase(string $action, string $clientIp, string $subject = ''): bool
    {
        $key = $this->rateLimitKey($action, $clientIp, $subject);

        // อายุของหน้าต่างคิดด้วยนาฬิกา MySQL ตัวเดียวกับตอนเขียน (NOW()/TIMESTAMPDIFF)
        // ห้ามเอา started_at มาเทียบกับ time() ของ PHP — connection ไม่ได้ pin time_zone ไว้
        // ถ้า PHP กับ MySQL คนละโซน อายุจะเพี้ยนจนหน้าต่างไม่หมุน (ล็อกถาวร) หรือหมุนทุกครั้ง
        // (ไม่จำกัดอะไรเลย) ขึ้นกับทิศทางที่เอียง
        $sql = 'SELECT attempts, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS window_age_seconds
                FROM auth_rate_limits
                WHERE bucket_key = :bucket_key
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':bucket_key' => $key]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return false;
        }

        $windowAge = $row['window_age_seconds'] ?? null;
        if ($windowAge === null || (int)$windowAge >= $this->getWindowSecondsForAction($action)) {
            // started_at เสีย (NULL) หรือหน้าต่างหมดอายุ → เริ่มนับใหม่
            $this->resetRateLimitWindowInDatabase($action, $clientIp, $key);
            return false;
        }

        return max(0, (int)($row['attempts'] ?? 0)) >= $this->getMaxAttemptsForAction($action);
    }

    private function getMaxAttemptsForAction(string $action): int
    {
        if ($action === 'password_reset') {
            return 1;
        }

        if ($action === self::LOGIN_ACCOUNT_ACTION) {
            return (int)LOGIN_ACCOUNT_MAX_ATTEMPTS;
        }

        return (int)RATE_LIMIT_MAX_ATTEMPTS;
    }

    /**
     * หน้าต่างเวลาของแต่ละ bucket
     *
     * bucket ต่อบัญชีใช้หน้าต่างที่ยาวกว่ามาก เพราะมันมีไว้จับ "การไล่เดาที่กระจาย
     * มาจากหลายเครื่อง" ซึ่งช้าและยาวกว่าการนั่งเดารัว ๆ จากเครื่องเดียว
     */
    private function getWindowSecondsForAction(string $action): int
    {
        if ($action === self::LOGIN_ACCOUNT_ACTION) {
            return (int)LOGIN_ACCOUNT_WINDOW_SECONDS;
        }

        return (int)RATE_LIMIT_WINDOW_SECONDS;
    }

    private function markFailedAttemptInDatabase(string $action, string $clientIp, string $subject = ''): void
    {
        if (random_int(1, 100) === 1) {
            $this->cleanupStaleRateLimitBucketsInDatabase();
        }

        $key = $this->rateLimitKey($action, $clientIp, $subject);

        // ⚠️ ชื่อ placeholder ห้ามซ้ำในคำสั่งเดียว — EMULATE_PREPARES=false ทำให้ MySQL
        // native prepare ตอบ HY093 "Invalid parameter number" (เคยเป็นบั๊ก: INSERT ล้มทุกครั้ง
        // แถวใน auth_rate_limits จึงเป็น 0 ตลอด = rate limit ไม่ทำงานเลย)
        $sql = 'INSERT INTO auth_rate_limits (bucket_key, action_type, client_ip, attempts, started_at, updated_at)
                VALUES (:bucket_key, :action_type, :client_ip, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_attempts THEN 1
                        ELSE attempts + 1
                    END,
                    started_at = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_started THEN NOW()
                        ELSE started_at
                    END,
                    updated_at = NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':bucket_key' => $key,
            ':action_type' => $action,
            ':client_ip' => $clientIp,
            ':window_attempts' => $this->getWindowSecondsForAction($action),
            ':window_started' => $this->getWindowSecondsForAction($action),
        ]);
    }

    private function clearRateLimitInDatabase(string $action, string $clientIp, string $subject = ''): void
    {
        $key = $this->rateLimitKey($action, $clientIp, $subject);

        $sql = 'DELETE FROM auth_rate_limits
                WHERE bucket_key = :bucket_key';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':bucket_key' => $key]);
    }

    private function resetRateLimitWindowInDatabase(string $action, string $clientIp, string $key): void
    {
        $sql = 'INSERT INTO auth_rate_limits (bucket_key, action_type, client_ip, attempts, started_at, updated_at)
                VALUES (:bucket_key, :action_type, :client_ip, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = 0,
                    started_at = NOW(),
                    updated_at = NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':bucket_key' => $key,
            ':action_type' => $action,
            ':client_ip' => $clientIp,
        ]);
    }

    private function isMissingAuthRateLimitTableError(PDOException $exception): bool
    {
        if ((string)$exception->getCode() !== '42S02') {
            return false;
        }

        return str_contains(strtolower($exception->getMessage()), 'auth_rate_limits');
    }

    private function cleanupStaleRateLimitBucketsInDatabase(): void
    {
        // เทียบอายุด้วยนาฬิกา MySQL เหมือนกัน — เดิมสร้าง threshold ด้วย date() ของ PHP
        // แล้วเอาไปเทียบกับ updated_at ที่ MySQL เขียน ถ้าคนละโซนจะลบแถวสดทิ้งทั้งตาราง
        $sql = 'DELETE FROM auth_rate_limits
                WHERE TIMESTAMPDIFF(SECOND, updated_at, NOW()) >= :retention_seconds';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':retention_seconds' => max(LOGIN_ACCOUNT_WINDOW_SECONDS * 10, RATE_LIMIT_WINDOW_SECONDS * 10, 600)]);
    }
}
