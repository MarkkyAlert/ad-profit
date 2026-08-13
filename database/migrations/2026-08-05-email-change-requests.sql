-- ⚠️⚠️⚠️ ก่อนรัน — ต้องเลือก "ฐานข้อมูลของแอป" ไว้ก่อนเท่านั้น
--
-- ใน phpMyAdmin: คลิกชื่อฐานข้อมูลของคุณในแถบซ้าย (เช่น u272145840_ad_profit)
-- **ให้ชื่อฐานข้อมูลขึ้นบนหัวจอ** แล้วค่อยกดแท็บ Import
--
-- ⚠️ ถ้าเผลอเลือก information_schema (หรือไม่ได้เลือกอะไรเลย) จะเจอ error พวกนี้:
--     #1109 - Unknown table 'shops' in information_schema
--     #1044 - Access denied ... to database 'information_schema'
--   → **ข้อมูลของคุณไม่ถูกแตะเลย** (คำสั่งล้มก่อนถึงข้อมูล) เลือกใหม่แล้วรันซ้ำได้เลย
--
-- บรรทัดถัดไปจะแสดงชื่อฐานข้อมูลที่กำลังจะแก้ — **ดูให้ตรงกับของคุณก่อนเสมอ**
SELECT DATABASE() AS `กำลังจะแก้ฐานข้อมูลนี้`;

-- ยืนยันอีเมลใหม่ก่อนเปลี่ยนจริง
--
-- ⚠️ ไม่บังคับต่อการบูต (Schema Guard ไม่ตรวจตารางนี้) แต่ **ถ้าไม่รัน
-- การเปลี่ยนอีเมลจะใช้ไม่ได้** และผู้ใช้จะเห็นข้อความว่าระบบยังไม่พร้อม
-- รันซ้ำได้ปลอดภัย (IF NOT EXISTS)

-- ⚠️⚠️ อีเมลใหม่ **ยังไม่ถูกเขียนลง `users`** จนกว่าเจ้าของจะกดลิงก์ยืนยันในกล่องจดหมายนั้น
--
-- เดิมเปลี่ยนอีเมลได้ทันที พิมพ์ผิดตัวเดียว (`@gmial.com`) ระบบขึ้นว่าสำเร็จ
-- แต่วันไหนหลุดจากระบบจะเข้าไม่ได้อีกเลย และ "ลืมรหัสผ่าน" จะส่งไปกล่องจดหมาย
-- ที่ไม่มีอยู่จริง = **บัญชีหายถาวร กู้คืนไม่ได้**
CREATE TABLE IF NOT EXISTS email_change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    new_email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- 1 คำขอต่อผู้ใช้ — ขอใหม่ทับของเดิม (ลิงก์เก่าใช้ไม่ได้ทันที)
    UNIQUE KEY uq_email_change_user (user_id),
    UNIQUE KEY uq_email_change_token_hash (token_hash),
    KEY idx_email_change_expires (expires_at),
    CONSTRAINT fk_email_change_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
