-- ลบตาราง idempotency_requests ออกจาก database ที่มีข้อมูลอยู่แล้ว
--
-- ทำไม: ตารางนี้ + IdempotencyRequestRepository + cron/cleanup-idempotency.php
-- ไม่เคยถูกเรียกจากโค้ดส่วนไหนเลย การกันกดซ้ำจริง ๆ พึ่ง unique key ระดับ DB
-- (uq_daily_records_shop_date, uq_monthly_goals_shop_month) + row lock ในทรานแซกชัน
--
-- ⚠️ อย่ารัน database/schema.sql ทับ database จริงเพื่อการนี้ — ไฟล์นั้นเป็น DROP + CREATE
-- จะลบข้อมูลทั้งหมดทิ้ง ให้รันไฟล์นี้แทน
--
-- ผลข้างเคียงที่ดี: หลังลบแล้ว schema ไม่มีคอลัมน์ชนิด JSON เหลืออยู่
-- → host ไม่จำเป็นต้องรองรับ JSON column อีกต่อไป
--
-- วิธีรัน:
--   mysql -u USER -p DBNAME < database/migrations/2026-08-04-drop-idempotency-requests.sql

DROP TABLE IF EXISTS idempotency_requests;
