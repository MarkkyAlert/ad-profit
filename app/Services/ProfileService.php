<?php

declare(strict_types=1);

class ProfileService
{
    private UserRepository $userRepository;
    private ?PDO $db;
    private ?PasswordResetRepository $passwordResetRepository;

    public function __construct(
        UserRepository $userRepository,
        ?PDO $db = null,
        ?PasswordResetRepository $passwordResetRepository = null
    ) {
        $this->userRepository = $userRepository;
        $this->db = $db;
        $this->passwordResetRepository = $passwordResetRepository;
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
    private function revokePasswordResetTokens(int $userId): void
    {
        if ($this->passwordResetRepository === null) {
            return;
        }

        try {
            $this->passwordResetRepository->deleteByUserId($userId);
        } catch (Throwable $exception) {
            error_log('[profile] revokePasswordResetTokens failed: ' . $exception->getMessage());
        }
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
        if ($nameLength > 120) {
            return [
                'success' => false,
                'error' => 'ชื่อที่แสดงยาวเกิน 120 ตัวอักษร',
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

            $updated = $this->userRepository->updateEmail($userId, $normalizedEmail);
            if (!$updated) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่สามารถเปลี่ยนอีเมลได้',
                ];
            }

            // อีเมลคือช่องทางกู้บัญชี — เปลี่ยนแล้วต้องเตะ session อื่นเหมือนตอนเปลี่ยนรหัสผ่าน
            // ไม่งั้นคนที่ยึด session ไว้ได้ เปลี่ยนอีเมลแล้วยังค้างอยู่ในบัญชีต่อไป
            $this->userRepository->incrementSessionVersion($userId);

            // ลิงก์รีเซ็ตที่ส่งไปอีเมล "เดิม" ต้องใช้ไม่ได้อีก
            $this->revokePasswordResetTokens($userId);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'data' => [
                    'email' => $normalizedEmail,
                ],
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
