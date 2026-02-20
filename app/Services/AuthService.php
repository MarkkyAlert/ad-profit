<?php

declare(strict_types=1);

class AuthService
{
    private const DEFAULT_SHOP_NAME = 'ร้านค้าของฉัน';

    private PDO $db;
    private UserRepository $userRepository;
    private ShopRepository $shopRepository;

    public function __construct(PDO $db, UserRepository $userRepository, ShopRepository $shopRepository)
    {
        $this->db = $db;
        $this->userRepository = $userRepository;
        $this->shopRepository = $shopRepository;
    }

    public function register(string $username, string $password, string $passwordConfirm, string $clientIp): array
    {
        $normalizedUsername = $this->normalizeUsername($username);

        if ($this->isRateLimited('register', $clientIp)) {
            return [
                'success' => false,
                'error' => 'ลองสมัครบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedUsername === '' || $this->usernameLength($normalizedUsername) < 3 || $this->usernameLength($normalizedUsername) > 50) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'ชื่อผู้ใช้ต้องมีความยาว 3-50 ตัวอักษร',
            ];
        }

        if (!preg_match('/^[\p{L}\p{N}._-]+$/u', $normalizedUsername)) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'ชื่อผู้ใช้ใช้ได้เฉพาะตัวอักษร ตัวเลข และ . _ -',
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

        if ($this->userRepository->findByUsername($normalizedUsername) !== null) {
            $this->markFailedAttempt('register', $clientIp);
            return [
                'success' => false,
                'error' => 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว',
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

            $userId = $this->userRepository->create($normalizedUsername, $passwordHash);
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
                    'error' => 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว',
                ];
            }

            return [
                'success' => false,
                'error' => 'ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่อีกครั้ง',
            ];
        }

        $this->establishSession($userId, $normalizedUsername, $shopId, self::DEFAULT_SHOP_NAME);
        $this->clearRateLimit('register', $clientIp);

        return [
            'success' => true,
            'user_id' => $userId,
            'shop_id' => $shopId,
        ];
    }

    public function login(string $username, string $password, string $clientIp): array
    {
        $normalizedUsername = $this->normalizeUsername($username);

        if ($this->isRateLimited('login', $clientIp)) {
            return [
                'success' => false,
                'error' => 'ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
            ];
        }

        if ($normalizedUsername === '' || $password === '') {
            $this->markFailedAttempt('login', $clientIp);
            return [
                'success' => false,
                'error' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน',
            ];
        }

        $user = $this->userRepository->findByUsername($normalizedUsername);
        if ($user === null) {
            $this->markFailedAttempt('login', $clientIp);
            return [
                'success' => false,
                'error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $passwordHash = (string)($user['password_hash'] ?? '');
        if (!password_verify($password, $passwordHash)) {
            $this->markFailedAttempt('login', $clientIp);
            return [
                'success' => false,
                'error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            ];
        }

        $userId = (int)$user['id'];
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
        $this->establishSession($userId, $normalizedUsername, $shopId, $shopName);
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
            $_SESSION['username'],
            $_SESSION['current_shop_id'],
            $_SESSION['current_shop_name']
        );

        unset($_SESSION['csrf_token']);
        session_regenerate_id(true);
    }

    private function establishSession(int $userId, string $username, int $shopId, string $shopName): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['current_shop_id'] = $shopId;
        $_SESSION['current_shop_name'] = $shopName;
    }

    private function normalizeUsername(string $username): string
    {
        return trim($username);
    }

    private function usernameLength(string $username): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($username);
        }

        return strlen($username);
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
