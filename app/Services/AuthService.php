<?php

declare(strict_types=1);

class AuthService
{
    private const DEFAULT_SHOP_NAME = 'ร้านค้าของฉัน';

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

        if ($this->isRateLimited('register', $clientIp, $normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'ลองสมัครบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || !is_valid_email($normalizedEmail)) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
            ];
        }

        if (strlen($normalizedEmail) > 255) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'อีเมลยาวเกินไป',
            ];
        }

        $passwordError = validate_password_length($password);
        if ($passwordError !== null) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => $passwordError,
            ];
        }

        if ($password !== $passwordConfirm) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        if ($this->userRepository->findByEmail($normalizedEmail) !== null) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
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

            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
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
        $this->clearRateLimit('register', $clientIp, $normalizedEmail);

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    public function login(string $email, string $password, string $clientIp): array
    {
        $normalizedEmail = normalize_email($email);

        if ($this->isRateLimited('login', $clientIp, $normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || $password === '') {
            $this->markFailedAttempt('login', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลและรหัสผ่าน',
            ];
        }

        $user = $this->userRepository->findByEmail($normalizedEmail);
        if ($user === null) {
            $this->markFailedAttempt('login', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $passwordHash = (string)($user['password_hash'] ?? '');
        if (!password_verify($password, $passwordHash)) {
            $this->markFailedAttempt('login', $clientIp, $normalizedEmail);
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
        $this->clearRateLimit('login', $clientIp, $normalizedEmail);

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

        if ($this->isRateLimited('password_reset', $clientIp, $normalizedEmail)) {
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

        if ($this->emailService !== null && $this->emailService->isEnabled()) {
            $emailSent = $this->emailService->sendPasswordResetEmail($normalizedEmail, $resetLink);
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
        if ($this->isRateLimited('reset_password', $clientIp, $rateLimitSubject)) {
            return [
                'success' => false,
                'error' => 'ลองรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่',
            ];
        }

        if ($token === '') {
            $this->markFailedAttempt('reset_password', $clientIp, $rateLimitSubject);
            return [
                'success' => false,
                'error' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $passwordError = validate_password_length($newPassword);
        if ($passwordError !== null) {
            return [
                'success' => false,
                'error' => $passwordError,
            ];
        }

        if ($newPassword !== $passwordConfirm) {
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

        if (($now - $startedAt) >= RATE_LIMIT_WINDOW_SECONDS) {
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
        if ($windowAge === null || (int)$windowAge >= RATE_LIMIT_WINDOW_SECONDS) {
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

        return (int)RATE_LIMIT_MAX_ATTEMPTS;
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
            ':window_attempts' => RATE_LIMIT_WINDOW_SECONDS,
            ':window_started' => RATE_LIMIT_WINDOW_SECONDS,
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
        $stmt->execute([':retention_seconds' => max(RATE_LIMIT_WINDOW_SECONDS * 10, 600)]);
    }
}
