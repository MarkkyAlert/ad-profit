<?php

declare(strict_types=1);

class ProfileService
{
    /**
     * ความยาวชื่อที่แสดงสูงสุด — ต้องเท่ากับ `users.display_name` ใน schema เป๊ะ ๆ
     * (`SchemaContractTest` ล็อกคู่นี้ไว้)
     */
    public const MAX_DISPLAY_NAME_LENGTH = 120;

    private UserRepository $userRepository;
    private ?PDO $db;
    private ?PasswordResetRepository $passwordResetRepository;

    private ?EmailChangeRepository $emailChangeRepository;
    private ?EmailService $emailService;
    private ?RateLimitRepository $emailChangeRateLimitRepository;

    private const EMAIL_CHANGE_COOLDOWN_ACTION = 'email_change_resend_cooldown';
    private const EMAIL_CHANGE_WINDOW_ACTION = 'email_change_resend_hour';

    public function __construct(
        UserRepository $userRepository,
        ?PDO $db = null,
        ?PasswordResetRepository $passwordResetRepository = null,
        ?EmailChangeRepository $emailChangeRepository = null,
        ?EmailService $emailService = null,
        ?RateLimitRepository $emailChangeRateLimitRepository = null
    ) {
        $this->userRepository = $userRepository;
        $this->db = $db;
        $this->passwordResetRepository = $passwordResetRepository;
        $this->emailChangeRepository = $emailChangeRepository;
        $this->emailService = $emailService;
        $this->emailChangeRateLimitRepository = $emailChangeRateLimitRepository;
    }

    /**
     * ล้างลิงก์รีเซ็ตรหัสผ่านที่ยังค้างอยู่ของผู้ใช้คนนี้
     *
     * เรียกทุกครั้งที่ credential เปลี่ยน (รหัสผ่าน/อีเมล) — สถานการณ์จริงคือผู้ใช้ได้อีเมล
     * "ลืมรหัสผ่าน" ที่ตัวเองไม่ได้ขอ แล้วรีบไปเปลี่ยนรหัสผ่านเองเพื่อป้องกันตัว
     * ถ้าลิงก์เก่ายังใช้ได้ คนที่ถือลิงก์นั้นยังตั้งรหัสทับของใหม่ได้ = การป้องกันตัวไร้ผล
     *
     * ⚠️ คู่กับ `AuthService::resetPassword` ที่ลบ token ทิ้งอยู่แล้ว — แก้ที่หนึ่งต้องดูอีกที่
     * ล้มเหลวไม่ควรทำให้การเปลี่ยนรหัสผ่านล้มตาม (บันทึก log แล้วไปต่อ)
     */
    /** ส่งลิงก์ยืนยัน — แยกออกมาเพื่อให้เทสต์แทนที่ได้โดยไม่ต้องมีระบบอีเมลจริง */
    protected function sendEmailChangeLink(string $newEmail, string $token): bool
    {
        if ($this->emailService === null) {
            error_log('[profile] no email service configured — cannot send the verification link');

            return false;
        }

        return $this->emailService->sendEmailChangeVerification(
            $newEmail,
            app_url('/verify-email.php?token=' . urlencode($token))
        );
    }

    /**
     * ⭐ ยืนยันลิงก์แล้วค่อยเปลี่ยนอีเมลจริง
     *
     * ⚠️ เช็ก "อีเมลนี้ถูกใช้ไปแล้วหรือยัง" **ซ้ำอีกครั้งตรงนี้** — ระหว่างที่ลิงก์รออยู่
     * อาจมีคนอื่นสมัครด้วยอีเมลนั้นไปแล้ว
     *
     * @return array{success:bool,error?:string,data?:array<string,mixed>}
     */
    public function confirmEmailChange(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'ลิงก์ยืนยันอีเมลไม่ถูกต้อง'];
        }

        if ($this->emailChangeRepository === null || !$this->emailChangeRepository->isReady()) {
            error_log('[profile] confirmEmailChange called but the pending-request table is missing');

            return ['success' => false, 'error' => 'ระบบเปลี่ยนอีเมลยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแล'];
        }

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            // ⚠️⚠️ **ต้องจองแถว `users` ก่อนแถวคำขอเสมอ** — กฎเดียวกับ `AuthService::resetPassword`
            //
            // `changeEmail()` (ตอนขอ) จองแถวผู้ใช้ก่อน ถ้าตอนยืนยันจองสลับกัน
            // สองทางที่ทำพร้อมกันจะล็อกกันเอง แล้วฐานข้อมูลยกเลิกฝ่ายหนึ่งทิ้ง
            // (วัดจริงแล้ว: ได้ deadlock 1213) · ผู้ใช้จะเห็นข้อความชี้ผิดสาเหตุว่า
            // "ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว" ทั้งที่ลิงก์ยังดีอยู่
            //
            // อ่านแบบไม่ล็อกก่อนเพื่อรู้ว่าเป็นของใคร → จองแถวผู้ใช้ → อ่านซ้ำใต้ล็อก
            $tokenHash = hash('sha256', $token);
            $owner = $this->emailChangeRepository->findByTokenHash($tokenHash);
            if (is_array($owner)) {
                $this->userRepository->lockForUpdate((int)$owner['user_id']);
            }

            $request = $this->emailChangeRepository->findByTokenHashForUpdate($tokenHash);
            if ($request === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return ['success' => false, 'error' => 'ลิงก์ยืนยันอีเมลไม่ถูกต้องหรือหมดอายุแล้ว'];
            }

            $userId = (int)$request['user_id'];
            $newEmail = (string)$request['new_email'];

            $owner = $this->userRepository->findByEmail($newEmail);
            if ($owner !== null && (int)$owner['id'] !== $userId) {
                $this->emailChangeRepository->deleteByUserId($userId);

                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                return ['success' => false, 'error' => 'อีเมลนี้ถูกใช้งานแล้ว กรุณาใช้อีเมลอื่น'];
            }

            if (!$this->userRepository->updateEmail($userId, $newEmail)) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return ['success' => false, 'error' => 'ไม่สามารถเปลี่ยนอีเมลได้'];
            }

            // อีเมลคือช่องทางกู้บัญชี — เปลี่ยนแล้วต้องเตะ session อื่นเหมือนตอนเปลี่ยนรหัสผ่าน
            $this->userRepository->incrementSessionVersion($userId);

            // ลิงก์รีเซ็ตที่ส่งไปอีเมล "เดิม" ต้องใช้ไม่ได้อีก
            $this->revokePasswordResetTokens($userId);

            // ใช้ลิงก์แล้วต้องใช้ซ้ำไม่ได้
            $this->emailChangeRepository->deleteByUserId($userId);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return ['success' => true, 'data' => ['email' => $newEmail, 'user_id' => $userId]];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[profile] confirmEmailChange failed: ' . $exception->getMessage());

            return ['success' => false, 'error' => write_failure_message($exception, 'ไม่สามารถเปลี่ยนอีเมลได้')];
        }
    }

    private function revokePasswordResetTokens(int $userId): void
    {
        if ($this->passwordResetRepository === null) {
            return;
        }

        // ⚠️ ห้าม catch ที่นี่ — เมธอดนี้ถูกเรียกอยู่ "ใน" ทรานแซกชันเดียวกับการเปลี่ยน
        // credential ถ้ากลืน error ไว้ ระบบจะ commit การเปลี่ยนรหัสผ่านต่อไปโดยที่ลิงก์
        // รีเซ็ตเก่ายังใช้ได้ แล้วบอกผู้ใช้ว่า "สำเร็จ" ซึ่งคือสิ่งที่การแก้นี้ตั้งใจปิดพอดี
        // ปล่อยให้ throw ขึ้นไปให้ catch ชั้นนอก rollback ทั้งชุด
        $this->passwordResetRepository->deleteByUserId($userId);
    }

    public function getProfile(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        $user = $this->userRepository->findProfileById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'ไม่พบผู้ใช้งาน',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'id' => (int)$user['id'],
                'display_name' => trim((string)($user['display_name'] ?? '')),
                'email' => (string)($user['email'] ?? ''),
            ],
        ];
    }

    public function updateProfile(int $userId, string $displayName): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        $normalizedDisplayName = trim($displayName);
        if ($normalizedDisplayName === '') {
            return [
                'success' => false,
                'error' => 'กรุณากรอกชื่อที่แสดง',
            ];
        }

        $nameLength = function_exists('mb_strlen') ? mb_strlen($normalizedDisplayName) : strlen($normalizedDisplayName);
        if ($nameLength > self::MAX_DISPLAY_NAME_LENGTH) {
            return [
                'success' => false,
                'error' => 'ชื่อที่แสดงยาวเกิน ' . self::MAX_DISPLAY_NAME_LENGTH . ' ตัวอักษร',
            ];
        }

        $user = $this->userRepository->findProfileById($userId);
        if ($user === null) {
            return [
                'success' => false,
                'error' => 'ไม่พบผู้ใช้งาน',
            ];
        }

        $currentDisplayName = trim((string)($user['display_name'] ?? ''));
        if ($currentDisplayName === $normalizedDisplayName) {
            return [
                'success' => true,
                'data' => [
                    'display_name' => $currentDisplayName,
                ],
            ];
        }

        try {
            $updated = $this->userRepository->updateDisplayName($userId, $normalizedDisplayName);
        } catch (Throwable $exception) {
            error_log('[profile] updateProfile failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตข้อมูลส่วนตัวได้',
            ];
        }

        if (!$updated) {
            return [
                'success' => false,
                'error' => 'ไม่สามารถอัปเดตข้อมูลส่วนตัวได้',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'display_name' => $normalizedDisplayName,
            ],
        ];
    }

    public function changeEmail(int $userId, string $newEmail, string $currentPassword): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        $normalizedEmail = normalize_email($newEmail);
        if ($normalizedEmail === '' || !is_valid_email($normalizedEmail)) {
            return [
                'success' => false,
                'error' => 'กรุณากรอกอีเมลที่ถูกต้อง',
            ];
        }

        if (strlen($normalizedEmail) > 255) {
            return [
                'success' => false,
                'error' => 'อีเมลยาวเกินไป',
            ];
        }

        if ($currentPassword === '') {
            return [
                'success' => false,
                'error' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            ];
        }

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                if ($this->db->inTransaction()) {
                    // Ensure email/password verification & update are consistent for this user.
                    $this->userRepository->lockForUpdate($userId);
                }
            }

            $user = $this->userRepository->findById($userId);
            if ($user === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบผู้ใช้งาน',
                ];
            }

            $passwordHash = (string)($user['password_hash'] ?? '');
            if (!password_verify($currentPassword, $passwordHash)) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
                    // บอก controller ว่านี่คือการเดารหัสผ่านจริง ๆ ไม่ใช่กรอกฟอร์มผิด
                    // → ให้เป็นเคสเดียวที่นับเข้าตัวจำกัดจำนวนครั้ง
                    'credential_failure' => true,
                ];
            }

            $currentEmail = normalize_email((string)($user['email'] ?? ''));
            if ($currentEmail === $normalizedEmail) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->commit();
                }

                return [
                    'success' => true,
                    'data' => [
                        'email' => $currentEmail,
                    ],
                ];
            }

            $existingUser = $this->userRepository->findByEmail($normalizedEmail);
            if ($existingUser !== null && (int)$existingUser['id'] !== $userId) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
                ];
            }

            // ⚠️⚠️ **ยังไม่เขียนอีเมลใหม่ลง `users` ตรงนี้**
            //
            // เดิมเปลี่ยนทันที · พิมพ์ผิดตัวเดียว (`@gmial.com`) ระบบขึ้นว่าสำเร็จ แต่วันไหน
            // หลุดจากระบบจะเข้าไม่ได้อีกเลย และ "ลืมรหัสผ่าน" จะส่งไปกล่องจดหมายที่ไม่มีจริง
            // = **บัญชีหายถาวร กู้คืนไม่ได้** (ไม่มีชั้นไหนกันเลยสักชั้น)
            //
            // ตอนนี้เก็บเป็น "คำขอที่รอยืนยัน" แล้วส่งลิงก์ไปที่อีเมลใหม่
            // อีเมลจะเปลี่ยนจริงเมื่อกดลิงก์นั้นเท่านั้น (`confirmEmailChange()`)
            if ($this->emailChangeRepository === null || !$this->emailChangeRepository->isReady()) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                error_log('[profile] email change requested but the pending-request table is missing');

                return [
                    'success' => false,
                    'error' => 'ระบบเปลี่ยนอีเมลยังไม่พร้อมใช้งาน กรุณาติดต่อผู้ดูแล',
                ];
            }

            // ⚠️⚠️ ระบบอีเมลยังไม่ได้ตั้งค่า = ลิงก์จะไม่มีวันไปถึง ต้องหยุดตรงนี้
            // **ก่อน** จองโควตาและก่อนเขียนคำขอทับของเดิม
            //
            // วัดจริงตอนยังไม่มีด่านนี้ (ระบบอีเมลปิดอยู่): กดครั้งแรกได้ข้อความ
            // "ส่งอีเมลยืนยันไม่สำเร็จ **กรุณาลองใหม่อีกครั้ง**" พอทำตามทันทีกลับได้
            // "ส่งลิงก์บ่อยเกินไป กรุณารอ 1 นาที" — สั่งให้ลองใหม่แล้วลงโทษที่ทำตาม
            // และไม่มีข้อความไหนบอกสาเหตุจริงเลย · ครบ 5 ครั้งก็ถูกกันไปอีก 1 ชั่วโมง
            // ทั้งที่ไม่ว่ารออีกกี่ชั่วโมงก็ไม่มีทางสำเร็จ
            //
            // ผลข้างเคียงที่แย่ที่สุดคือคำขอเดิมที่ยังใช้ได้อยู่ถูกทับหายไปฟรี ๆ
            if ($this->emailService === null || !$this->emailService->isEnabled()) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                error_log('[profile] email change requested but the mail system is not configured');

                return [
                    'success' => false,
                    'error' => 'ระบบส่งอีเมลยังไม่พร้อมใช้งาน จึงยังเปลี่ยนอีเมลไม่ได้ กรุณาติดต่อผู้ดูแลระบบ',
                ];
            }

            $rateLimit = $this->reserveEmailChangeSendSlot($userId);
            if ($rateLimit !== null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return $rateLimit;
            }

            $token = bin2hex(random_bytes(32));
            $created = $this->emailChangeRepository->createRequest(
                $userId,
                $normalizedEmail,
                hash('sha256', $token),
                (int)PASSWORD_RESET_TOKEN_TTL_HOURS
            );

            if (!$created) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
                ];
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }

            // ⚠️ ส่งอีเมล **หลัง** commit — ถ้าส่งก่อนแล้วการบันทึกล้ม ผู้ใช้จะได้ลิงก์
            // ที่ใช้ไม่ได้ · ส่งไม่สำเร็จยังบอกผู้ใช้ตามจริงได้ เพราะคำขอถูกบันทึกแล้ว
            $sent = $this->sendEmailChangeLink($normalizedEmail, $token);

            return [
                'success' => true,
                'data' => [
                    'pending_email' => $normalizedEmail,
                    'email_sent' => $sent,
                ],
                'message' => $sent
                    ? 'ส่งลิงก์ยืนยันไปที่ ' . $normalizedEmail . ' แล้ว — อีเมลจะเปลี่ยนเมื่อคุณกดลิงก์นั้น'
                    : 'บันทึกคำขอแล้ว แต่ส่งอีเมลยืนยันไม่สำเร็จ กรุณารอสักครู่แล้วกดส่งใหม่อีกครั้ง',
            ];
        } catch (PDOException $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[profile] changeEmail failed: ' . $exception->getMessage());

            $isDuplicateUser = (string)$exception->getCode() === '23000';
            if ($isDuplicateUser) {
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
                ];
            }

            return [
                'success' => false,
                'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[profile] changeEmail failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
            ];
        }
    }

    private function reserveEmailChangeSendSlot(int $userId): ?array
    {
        if ($this->emailChangeRateLimitRepository === null) {
            return null;
        }

        $subject = (string)$userId;

        $cooldownAttempts = $this->emailChangeRateLimitRepository->reserveAttempt(
            self::EMAIL_CHANGE_COOLDOWN_ACTION,
            '',
            $subject,
            (int)EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS
        );
        if ($cooldownAttempts > 1) {
            return [
                'success' => false,
                'error' => 'ส่งลิงก์ยืนยันอีเมลบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่อีกครั้ง',
                'rate_limited' => true,
            ];
        }

        $windowAttempts = $this->emailChangeRateLimitRepository->reserveAttempt(
            self::EMAIL_CHANGE_WINDOW_ACTION,
            '',
            $subject,
            (int)EMAIL_CHANGE_RESEND_WINDOW_SECONDS
        );
        if ($windowAttempts > (int)EMAIL_CHANGE_RESEND_MAX_ATTEMPTS) {
            return [
                'success' => false,
                'error' => 'ส่งลิงก์ยืนยันอีเมลครบจำนวนที่อนุญาตแล้ว กรุณารอ 1 ชั่วโมงแล้วลองใหม่อีกครั้ง',
                'rate_limited' => true,
            ];
        }

        return null;
    }

    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $passwordConfirm
    ): array {
        if ($userId <= 0) {
            return [
                'success' => false,
                'error' => 'Unauthorized',
            ];
        }

        if ($currentPassword === '') {
            return [
                'success' => false,
                'error' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
            ];
        }

        $passwordError = validate_password_length($newPassword, 'รหัสผ่านใหม่');
        if ($passwordError !== null) {
            return [
                'success' => false,
                'error' => $passwordError,
            ];
        }

        if ($newPassword !== $passwordConfirm) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน',
            ];
        }

        if ($newPassword === $currentPassword) {
            return [
                'success' => false,
                'error' => 'รหัสผ่านใหม่ต้องไม่ซ้ำรหัสผ่านปัจจุบัน',
            ];
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newPasswordHash) || $newPasswordHash === '') {
            error_log('[profile] Unable to create password hash for changePassword');
            return [
                'success' => false,
                'error' => 'ไม่สามารถเปลี่ยนรหัสผ่านได้ในขณะนี้',
            ];
        }

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                if ($this->db->inTransaction()) {
                    // Ensure password_hash + session_version update are atomic.
                    $this->userRepository->lockForUpdate($userId);
                }
            }

            $user = $this->userRepository->findById($userId);
            if ($user === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบผู้ใช้งาน',
                ];
            }

            $passwordHash = (string)($user['password_hash'] ?? '');
            if (!password_verify($currentPassword, $passwordHash)) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
                    // บอก controller ว่านี่คือการเดารหัสผ่านจริง ๆ ไม่ใช่กรอกฟอร์มผิด
                    // → ให้เป็นเคสเดียวที่นับเข้าตัวจำกัดจำนวนครั้ง
                    'credential_failure' => true,
                ];
            }

            $updated = $this->userRepository->updatePasswordHash($userId, $newPasswordHash);
            if (!$updated) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเปลี่ยนรหัสผ่านได้',
                ];
            }

            $this->userRepository->incrementSessionVersion($userId);

            // ลิงก์รีเซ็ตที่ยังค้างอยู่ต้องใช้ไม่ได้อีก — ผู้ใช้เพิ่งตั้งรหัสใหม่เองแล้ว
            $this->revokePasswordResetTokens($userId);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'success' => true,
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[profile] changePassword failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถเปลี่ยนรหัสผ่านได้',
            ];
        }
    }
}
