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
        $normalizedEmail = $this->normalizeEmail($email);

        if ($this->isRateLimited('register', $clientIp, $normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'ลองสมัครบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || !$this->isValidEmail($normalizedEmail)) {
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

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $this->markFailedAttempt('register', $clientIp, $normalizedEmail);
            return [
                'success' => false,
                'error' => 'รหัสผ่านต้องมีอย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร',
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
        $normalizedEmail = $this->normalizeEmail($email);

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
                error_log('[auth][login] Unable to create default shop: ' . $exception->getMessage());
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเข้าสู่ระบบได้ กรุณาลองใหม่อีกครั้ง',
                ];
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

        $this->userRepository->updateLastLoginAt($userId);
        $this->establishSession($userId, $userEmail, $shopId, $shopName, $sessionVersion);
        $this->clearRateLimit('login', $clientIp, $normalizedEmail);

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    public function logout(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['email'],
            $_SESSION['display_name'],
            $_SESSION['session_version'],
            $_SESSION['auth_started_at'],
            $_SESSION['last_activity_at'],
            $_SESSION['current_shop_id'],
            $_SESSION['current_shop_name'],
            $_SESSION['password_reset_token']
        );

        unset($_SESSION['csrf_token']);
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

        $normalizedEmail = $this->normalizeEmail($email);

        if ($this->isRateLimited('password_reset', $clientIp, $normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'ลองขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่',
            ];
        }

        // Count every password-reset request in the window to prevent abuse/spam.
        $this->markFailedAttempt('password_reset', $clientIp, $normalizedEmail);

        if ($normalizedEmail === '' || !$this->isValidEmail($normalizedEmail)) {
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
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . PASSWORD_RESET_TOKEN_TTL_HOURS . ' hours'));

        try {
            $this->passwordResetRepository->createToken($userId, $tokenHash, $expiresAt);
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

        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านต้องมีอย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร',
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

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isRateLimited(string $action, string $clientIp, string $subject = ''): bool
    {
        if ($this->canUseDatabaseRateLimit()) {
            try {
                return $this->isRateLimitedInDatabase($action, $clientIp, $subject);
            } catch (Throwable $exception) {
                error_log('[auth][rate_limit] DB read failed, fallback to session limiter: ' . $exception->getMessage());
            }
        }

        $bucket = $this->getRateLimitBucket($action, $clientIp, $subject);

        return (int)$bucket['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS;
    }

    private function markFailedAttempt(string $action, string $clientIp, string $subject = ''): void
    {
        if ($this->canUseDatabaseRateLimit()) {
            try {
                $this->markFailedAttemptInDatabase($action, $clientIp, $subject);
                return;
            } catch (Throwable $exception) {
                error_log('[auth][rate_limit] DB write failed, fallback to session limiter: ' . $exception->getMessage());
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
                error_log('[auth][rate_limit] DB clear failed, fallback to session limiter: ' . $exception->getMessage());
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

        $sql = 'SELECT attempts, started_at
                FROM auth_rate_limits
                WHERE bucket_key = :bucket_key
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':bucket_key' => $key]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return false;
        }

        $attempts = max(0, (int)($row['attempts'] ?? 0));
        $startedAtRaw = (string)($row['started_at'] ?? '');
        $startedAtTimestamp = strtotime($startedAtRaw);
        if ($startedAtTimestamp === false) {
            $this->resetRateLimitWindowInDatabase($action, $clientIp, $key);
            return false;
        }

        if ((time() - $startedAtTimestamp) >= RATE_LIMIT_WINDOW_SECONDS) {
            $this->resetRateLimitWindowInDatabase($action, $clientIp, $key);
            return false;
        }

        return $attempts >= RATE_LIMIT_MAX_ATTEMPTS;
    }

    private function markFailedAttemptInDatabase(string $action, string $clientIp, string $subject = ''): void
    {
        if (random_int(1, 100) === 1) {
            $this->cleanupStaleRateLimitBucketsInDatabase();
        }

        $key = $this->rateLimitKey($action, $clientIp, $subject);

        $sql = 'INSERT INTO auth_rate_limits (bucket_key, action_type, client_ip, attempts, started_at, updated_at)
                VALUES (:bucket_key, :action_type, :client_ip, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_seconds THEN 1
                        ELSE attempts + 1
                    END,
                    started_at = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_seconds THEN NOW()
                        ELSE started_at
                    END,
                    updated_at = NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':bucket_key' => $key,
            ':action_type' => $action,
            ':client_ip' => $clientIp,
            ':window_seconds' => RATE_LIMIT_WINDOW_SECONDS,
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
        $retentionSeconds = max(RATE_LIMIT_WINDOW_SECONDS * 10, 600);
        $thresholdTime = date('Y-m-d H:i:s', time() - $retentionSeconds);
        $sql = 'DELETE FROM auth_rate_limits
                WHERE updated_at < :threshold_time';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':threshold_time' => $thresholdTime]);
    }
}
