# INSTALL

คู่มือติดตั้งโปรเจกต์ Ad Profit บนเครื่อง Windows (XAMPP)

## 1) Requirements

- PHP 8.1+
- MySQL/MariaDB (XAMPP)
- Apache (XAMPP)

## 2) วางโปรเจกต์

วางโปรเจกต์ไว้ที่:

`c:\xampp\htdocs\ad-profit`

## 3) สร้างฐานข้อมูล

เปิด PowerShell แล้วรัน (ในโฟลเดอร์โปรเจกต์):

```powershell
& "c:\xampp\mysql\bin\mysql.exe" -u root -e "source c:/xampp/htdocs/ad-profit/database/schema.sql"
& "c:\xampp\mysql\bin\mysql.exe" -u root -e "source c:/xampp/htdocs/ad-profit/database/sample_data.sql"
```

> ถ้า root มีรหัสผ่าน ให้เพิ่ม `-p` แล้วกรอกรหัสผ่าน

## 4) ตั้งค่า config

ไฟล์: `includes/config.php`

ค่า default:

- DB_HOST = `127.0.0.1`
- DB_PORT = `3306`
- DB_NAME = `ad_profit`
- DB_USER = `root`
- DB_PASS = ``

หาก deploy ใน subdirectory ให้ตั้ง `APP_URL` (หรือปล่อยให้ระบบ detect จาก `DOCUMENT_ROOT`)

## 5) รันระบบ

1. เปิด Apache + MySQL ใน XAMPP Control Panel
2. เข้า URL: `http://localhost/ad-profit/`

## 6) ทดสอบ Auth

- สมัครสมาชิกใหม่ 1 บัญชี (ใช้อีเมลแทน username)
- ตรวจว่าระบบพาไป `dashboard.php`
- ลอง logout แล้ว login ใหม่
- ทดสอบ"ลืมรหัสผ่าน" (Dev mode: แสดง reset link บนหน้าจอ)

**Demo accounts** (หลังรัน sample_data.sql):
- `demo@example.com` / `password`
- `team@example.com` / `password`

## 7) Smoke Checklist (Phase 9)

หลังจาก login แล้วให้ทดสอบอย่างน้อย:

1. **Shops**
   - สร้างร้านที่ 2 จากหน้าจัดการร้าน
   - สลับร้านจาก dropdown แล้วข้อมูลต้องแยกกัน
   - ถ้าเหลือร้านเดียว ปุ่มลบร้านต้อง disabled

2. **Daily Record + History**
   - เพิ่มข้อมูลรายวัน 1 รายการ
   - เข้า `history.php` แล้วเห็นข้อมูลในเดือนที่เลือก
   - แก้ไข/ลบได้ พร้อม confirm dialog

3. **Dashboard + Goals**
   - ลองเลือกช่วงเวลา (สัปดาห์/เดือน/กำหนดเอง)
   - ตรวจกรณี custom ช่วงวันไม่ถูกต้อง (เริ่ม > สิ้นสุด) ต้องมี error
   - ตั้งเป้ารายเดือน แล้วดู progress เปลี่ยนตามข้อมูล

4. **Overview / Annual**
   - ถ้ามี >= 2 ร้าน ต้องเห็น tab "🏪 รวมร้าน"
   - หน้า overview ต้องเห็นตารางเปรียบเทียบ + กราฟ
   - หน้า annual ต้องเห็นตาราง 12 เดือน + กราฟ และเดือนไม่มีข้อมูลเป็น 0

5. **CSV Export**
   - กด "📥 Export CSV" ในหน้าประวัติ
   - เปิดไฟล์ใน Excel แล้วภาษาไทยต้องไม่เพี้ยน (UTF-8 BOM)

## 8) Migration สำหรับ Production ที่มีอยู่แล้ว

หากมี database production อยู่แล้วและต้องการอัปเกรดเป็นเวอร์ชันล่าสุด:

```powershell
& "c:\xampp\mysql\bin\mysql.exe" -u root -p -e "source c:/xampp/htdocs/ad-profit/database/migrations/001_hardening_schema.sql"
```

**การตรวจสอบหลัง migration:**

```sql
-- ต้องได้ exists = 1 ทุกรายการ
SELECT 'auth_rate_limits' AS item, COUNT(*) AS exists FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ad_profit' AND TABLE_NAME = 'auth_rate_limits';
SELECT 'users.display_name' AS item, COUNT(*) AS exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'ad_profit' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'display_name';
SELECT 'shops.uq_shops_user_name' AS item, COUNT(*) AS exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'ad_profit' AND TABLE_NAME = 'shops' AND INDEX_NAME = 'uq_shops_user_name';
SELECT 'password_reset_tokens.uq_password_reset_token_hash' AS item, COUNT(*) AS exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'ad_profit' AND TABLE_NAME = 'password_reset_tokens' AND INDEX_NAME = 'uq_password_reset_token_hash';
```

> **หมายเหตุ**: หากไม่รัน migration ระบบจะแสดงหน้า "ต้องอัปเกรดโครงสร้างฐานข้อมูล" (503) และไม่ให้เข้าใช้งาน

## 9) Environment Variables (Production)

สร้างไฟล์ `.env` จาก `.env.example` แล้วตั้งค่าตามต้องการ:

| Variable | Default | คำอธิบาย |
|----------|---------|---------|
| `APP_ENV` | development | ตั้งเป็น `production` สำหรับ prod |
| `APP_URL` | (empty) | **ต้องตั้งค่าใน production** เช่น `https://example.com` |
| `SESSION_IDLE_TIMEOUT_SECONDS` | 14400 (4 ชม.) | หมดอายุหากไม่มีการใช้งาน |
| `SESSION_ABSOLUTE_TIMEOUT_SECONDS` | 86400 (24 ชม.) | หมดอายุหลัง login |
| `SCHEMA_GUARD_ENABLED` | true | ตรวจ schema ก่อนใช้งาน (ปิดได้ชั่วคราว) |
| `TRUST_PROXY` | false | เปิดเฉพาะกรณีรันหลัง trusted reverse proxy |
| `MAIL_TIMEOUT_SECONDS` | 15 | timeout การส่งอีเมล |
| `MAIL_RETRY_ATTEMPTS` | 1 | จำนวนครั้งที่ retry หากส่งไม่สำเร็จ |

## 10) Troubleshooting

- ถ้าเจอ DB connection failed:
  - ตรวจว่า MySQL start แล้ว
  - ตรวจค่าฐานข้อมูลใน `includes/config.php`
- ถ้า redirect path เพี้ยน:
  - ตั้ง `APP_URL` ใน environment หรือแก้ใน `includes/config.php`
- ถ้าเจอหน้า **"ต้องอัปเกรดโครงสร้างฐานข้อมูล"** (503):
  - รัน migration script ตามข้อ 8
  - หรือตั้ง `SCHEMA_GUARD_ENABLED=false` ชั่วคราว (ไม่แนะนำ)
- ถ้าเจอ "Session expired" บ่อยเกินไป:
  - ปรับ `SESSION_IDLE_TIMEOUT_SECONDS` ให้มากขึ้น
- log error อยู่ที่: `logs/php-error.log`

## 11) Cron Jobs (แนะนำสำหรับ production)

สคริปต์ที่ควรตั้งเวลา:

1. `cron/cleanup-idempotency.php`
   - หน้าที่: ลบ row ใน `idempotency_requests` ที่หมดอายุ (`expires_at < NOW()`)
   - แนะนำ: รันวันละครั้ง

2. `cron/cleanup-logs.php`
   - หน้าที่: ลบไฟล์ใน `logs/` ที่เก่ากว่า 30 วัน
   - แนะนำ: รันสัปดาห์ละครั้ง

ตัวอย่าง `crontab`:

```cron
# cleanup idempotency ทุกวันเวลา 02:00
0 2 * * * /usr/bin/php /path/to/ad-profit/cron/cleanup-idempotency.php >> /path/to/ad-profit/logs/cron-cleanup.log 2>&1

# cleanup logs ทุกวันอาทิตย์เวลา 03:00
0 3 * * 0 /usr/bin/php /path/to/ad-profit/cron/cleanup-logs.php >> /path/to/ad-profit/logs/cron-cleanup.log 2>&1
```
