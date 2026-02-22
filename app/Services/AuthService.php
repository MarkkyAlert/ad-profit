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

        if ($this->isRateLimited('register', $clientIp)) {
            return [
                'success' => false,
                'error' => 'ลองสมัครบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || !$this->isValidEmail($normalizedEmail)) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
            ];
        }

        if (strlen($normalizedEmail) > 255) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'อีเมลยาวเกินไป',
            ];
        }

        if (strlen($password) < 4) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร',
            ];
        }

        if ($password !== $passwordConfirm) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        if ($this->userRepository->findByEmail($normalizedEmail) !== null) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'อีเมลนี้ถูกใช้งานแล้ว',
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

        try {
            $this->db->beginTransaction();

            $userId = $this->userRepository->create($normalizedEmail, $passwordHash);
            $shopId = $this->shopRepository->create($userId, self::DEFAULT_SHOP_NAME);
            $this->userRepository->updateLastLoginAt($userId);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->markFailedAttempt('register', $clientIp);
            error_log('[auth][register] ' . $exception->getMessage());

            $isDuplicateUser = $exception instanceof PDOException && $exception->getCode() === '23000';
            if ($isDuplicateUser) {
                return [
                    'success' => false,
                    'error' => 'อีเมลนี้ถูกใช้งานแล้ว',
                ];
            }

            return [
                'success' => false,
                'error' => 'ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่อีกครั้ง',
            ];
        }

        $this->establishSession($userId, $normalizedEmail, $shopId, self::DEFAULT_SHOP_NAME);
        $this->clearRateLimit('register', $clientIp);

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    public function login(string $email, string $password, string $clientIp): array
    {
        $normalizedEmail = $this->normalizeEmail($email);

        if ($this->isRateLimited('login', $clientIp)) {
            return [
                'success' => false,
                'error' => 'ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedEmail === '' || $password === '') {
            $this->markFailedAttempt('login', $clientIp);
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลและรหัสผ่าน',
            ];
        }

        $user = $this->userRepository->findByEmail($normalizedEmail);
        if ($user === null) {
            $this->markFailedAttempt('login', $clientIp);
            return [
                'success' => false,
                'error' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $passwordHash = (string)($user['password_hash'] ?? '');
        if (!password_verify($password, $passwordHash)) {
            $this->markFailedAttempt('login', $clientIp);
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

        $this->userRepository->updateLastLoginAt($userId);
        $this->establishSession($userId, $userEmail, $shopId, $shopName);
        $this->clearRateLimit('login', $clientIp);

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
            $_SESSION['current_shop_id'],
            $_SESSION['current_shop_name']
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

        if ($this->isRateLimited('password_reset', $clientIp)) {
            return [
                'success' => false,
                'error' => 'ลองขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่',
            ];
        }

        if ($normalizedEmail === '' || !$this->isValidEmail($normalizedEmail)) {
            $this->markFailedAttempt('password_reset', $clientIp);
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
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

        $this->clearRateLimit('password_reset', $clientIp);

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

        if (APP_ENV === 'development' || !$emailSent) {
            $response['token'] = $token;
            $response['email'] = $normalizedEmail;
        }

        return $response;
    }

    public function resetPassword(string $token, string $newPassword, string $passwordConfirm): array
    {
        if ($this->passwordResetRepository === null) {
            return [
                'success' => false,
                'error' => 'ระบบรีเซ็ตรหัสผ่านไม่พร้อมใช้งาน',
            ];
        }

        if ($token === '') {
            return [
                'success' => false,
                'error' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง',
            ];
        }

        if (strlen($newPassword) < 4) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านต้องมีอย่างน้อย 4 ตัวอักษร',
            ];
        }

        if ($newPassword !== $passwordConfirm) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        $tokenHash = hash('sha256', $token);
        $tokenRecord = $this->passwordResetRepository->findByTokenHash($tokenHash);

        if ($tokenRecord === null) {
            return [
                'success' => false,
                'error' => 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว',
            ];
        }

        $userId = (int)$tokenRecord['user_id'];
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (!is_string($passwordHash) || $passwordHash === '') {
            error_log('[auth][reset_password] Unable to create password hash');
            return [
                'success' => false,
                'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้ในขณะนี้',
            ];
        }

        try {
            $this->userRepository->updatePasswordHash($userId, $passwordHash);
            $this->passwordResetRepository->deleteByUserId($userId);
        } catch (Throwable $exception) {
            error_log('[auth][reset_password] ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถรีเซ็ตรหัสผ่านได้',
            ];
        }

        return [
            'success' => true,
            'message' => 'รีเซ็ตรหัสผ่านสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่',
        ];
    }

    private function establishSession(int $userId, string $email, int $shopId, string $shopName): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = $email;
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

    private function isRateLimited(string $action, string $clientIp): bool
    {
        $bucket = $this->getRateLimitBucket($action, $clientIp);

        return (int)$bucket['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS;
    }

    private function markFailedAttempt(string $action, string $clientIp): void
    {
        $bucket = $this->getRateLimitBucket($action, $clientIp);
        $bucket['attempts'] = (int)$bucket['attempts'] + 1;

        $key = $this->rateLimitKey($action, $clientIp);
        $_SESSION['auth_rate_limits'][$key] = $bucket;
    }

    private function clearRateLimit(string $action, string $clientIp): void
    {
        if (!isset($_SESSION['auth_rate_limits']) || !is_array($_SESSION['auth_rate_limits'])) {
            return;
        }

        $key = $this->rateLimitKey($action, $clientIp);
        unset($_SESSION['auth_rate_limits'][$key]);
    }

    private function getRateLimitBucket(string $action, string $clientIp): array
    {
        if (!isset($_SESSION['auth_rate_limits']) || !is_array($_SESSION['auth_rate_limits'])) {
            $_SESSION['auth_rate_limits'] = [];
        }

        $key = $this->rateLimitKey($action, $clientIp);
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

    private function rateLimitKey(string $action, string $clientIp): string
    {
        return hash('sha256', $action . '|' . $clientIp . '|' . session_id());
    }
}
