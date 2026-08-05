<?php

declare(strict_types=1);

/**
 * คำขอเปลี่ยนอีเมลที่ "ยังไม่ยืนยัน"
 *
 * ⚠️ อีเมลใหม่อยู่ในตารางนี้เท่านั้น ยังไม่ถูกเขียนลง `users` จนกว่าเจ้าของ
 * จะกดลิงก์ในกล่องจดหมายนั้น — พิมพ์ผิดจึงไม่ทำให้บัญชีหาย
 *
 * เก็บ token เป็น hash เหมือนลิงก์รีเซ็ตรหัสผ่าน (ฐานข้อมูลรั่วก็ใช้ลิงก์ไม่ได้)
 */
class EmailChangeRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** มีตารางนี้ในฐานข้อมูลหรือยัง (ยังไม่ได้รัน migration = ยังไม่มี) */
    public function isReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM email_change_requests LIMIT 1');

            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** ขอใหม่ทับของเดิมเสมอ — ลิงก์เก่าใช้ไม่ได้ทันที */
    public function createRequest(int $userId, string $newEmail, string $tokenHash, int $ttlHours): bool
    {
        $sql = 'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
                VALUES (:user_id, :new_email, :token_hash, DATE_ADD(NOW(), INTERVAL :ttl_hours HOUR))
                ON DUPLICATE KEY UPDATE
                    new_email = VALUES(new_email),
                    token_hash = VALUES(token_hash),
                    expires_at = VALUES(expires_at),
                    created_at = CURRENT_TIMESTAMP';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':user_id' => $userId,
            ':new_email' => $newEmail,
            ':token_hash' => $tokenHash,
            ':ttl_hours' => max(1, $ttlHours),
        ]);
    }

    /**
     * อ่านคำขอที่ยังไม่หมดอายุ พร้อมล็อกแถวไว้
     *
     * ⚠️ ล็อกด้วย `FOR UPDATE` — กดลิงก์ซ้ำเร็ว ๆ สองครั้งต้องไม่เปลี่ยนอีเมลสองรอบ
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHashForUpdate(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, new_email, expires_at
             FROM email_change_requests
             WHERE token_hash = :token_hash AND expires_at > NOW()
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findPendingByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT new_email, expires_at
             FROM email_change_requests
             WHERE user_id = :user_id AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function deleteByUserId(int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM email_change_requests WHERE user_id = :user_id');

        return $stmt->execute([':user_id' => $userId]);
    }
}
