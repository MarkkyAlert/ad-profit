-- sample_data.sql
-- ข้อมูลตัวอย่างสำหรับทดสอบระบบ

USE ad_profit;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM password_reset_tokens;
DELETE FROM idempotency_requests;
DELETE FROM monthly_goals;
DELETE FROM daily_records;
DELETE FROM shops;
DELETE FROM users;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO users (id, email, password_hash, last_login_at, created_at, updated_at)
VALUES
    (1, 'demo@example.com', '$2y$10$WmdX4spwmdeVN4QE/BvMoe4QhxkFwzkjS7XSS9SYIITS557Ved1je', NOW(), NOW(), NOW()),
    (2, 'team@example.com', '$2y$10$0iCU/csDnPzAfxGgUbYHeeZXP7ncvn53Q0btNmlg9uF87P6jAAyL.', NOW(), NOW(), NOW());

INSERT INTO shops (id, user_id, name, created_at, updated_at)
VALUES
    (1, 1, 'ร้านค้าของฉัน', NOW(), NOW()),
    (2, 1, 'ร้านสำรอง', NOW(), NOW()),
    (3, 2, 'ร้านทีมงาน', NOW(), NOW());

INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note)
VALUES
    (1, '2025-11-03', 12500.00, 3800.00, 'เริ่มแคมเปญชุดใหม่'),
    (1, '2025-11-12', 16200.00, 5100.00, 'ยิงแอดสินค้าขายดี'),
    (1, '2025-12-06', 14300.00, 4200.00, 'โปร 12.12 รอบแรก'),
    (1, '2025-12-22', 17800.00, 6400.00, 'Live ปิดยอดสิ้นปี'),
    (1, '2026-01-04', 15100.00, 4300.00, 'เปิดปีใหม่'),
    (1, '2026-01-15', 17350.00, 5900.00, 'ทดสอบครีเอทีฟใหม่'),
    (1, '2026-01-27', 16220.00, 4800.00, 'โปรส่งฟรี'),
    (1, '2026-02-03', 9800.00, 3000.00, 'ยอดตกช่วงต้นเดือน'),
    (1, '2026-02-10', 14700.00, 4600.00, 'เพิ่มงบ retargeting'),
    (1, '2026-02-14', 5200.00, 1800.00, 'โปรวันวาเลนไทน์'),
    (1, '2026-02-18', 3200.00, 1000.00, 'ทดสอบชุดโฆษณาใหม่'),
    (1, '2026-02-26', 18900.00, 6200.00, 'Flash Sale ปลายเดือน'),
    (2, '2026-01-08', 9200.00, 2600.00, 'เปิดร้านสำรอง'),
    (2, '2026-01-22', 10100.00, 3100.00, 'ลงสินค้าใหม่'),
    (2, '2026-02-09', 11300.00, 3500.00, 'ทำคอนเทนต์รีวิว'),
    (2, '2026-02-21', 10800.00, 3400.00, 'รีมาร์เก็ตติ้งลูกค้าเก่า'),
    (3, '2026-01-10', 6500.00, 2100.00, 'แคมเปญทีมงาน'),
    (3, '2026-02-11', 7200.00, 2500.00, 'ยิงโฆษณาเพิ่มยอด');

INSERT INTO monthly_goals (shop_id, goal_month, target_revenue, target_profit)
VALUES
    (1, '2026-01', 50000.00, 32000.00),
    (1, '2026-02', 55000.00, 35000.00),
    (2, '2026-02', 25000.00, 15000.00),
    (3, '2026-02', 18000.00, 10000.00);

INSERT INTO idempotency_requests (
    user_id,
    action_type,
    idempotency_key,
    request_fingerprint,
    response_payload,
    status,
    created_at,
    expires_at
) VALUES (
    1,
    'record_upsert',
    SHA2('sample-idempotency-key-1', 256),
    SHA2('shop=1|date=2026-02-18|revenue=3200|ad=1000', 256),
    JSON_OBJECT('success', true, 'message', 'upserted'),
    'completed',
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 DAY)
);

ALTER TABLE users AUTO_INCREMENT = 3;
ALTER TABLE shops AUTO_INCREMENT = 4;
