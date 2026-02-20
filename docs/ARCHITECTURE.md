# ARCHITECTURE

เอกสารสรุปสถาปัตยกรรมล่าสุดของโปรเจกต์ Ad Profit (สถานะ Phase 9)

## Pattern หลัก (บังคับใช้)

`Controller (public/api) -> Service -> Repository -> Database`

### 1) Controller

ตำแหน่ง:

- หน้าเว็บ: `/*.php`
- API: `/api/*.php`

หน้าที่:

- รับ/normalize request
- ตรวจ method + CSRF (สำหรับ POST)
- เรียก Service
- ส่ง response (HTML, redirect+flash, หรือ JSON)

### 2) Service

ตำแหน่ง:

- `app/Services/*.php`

หน้าที่:

- business rules
- ตรวจ permission และ edge case
- คำนวณ summary/chart payload
- ประสานงานหลาย repository (และ transaction เมื่อจำเป็น)

### 3) Repository

ตำแหน่ง:

- `app/Repositories/*.php`

หน้าที่:

- มี SQL ทั้งหมดของระบบ
- ใช้ PDO prepared statements เท่านั้น
- ไม่ทำ business logic

## Bootstrap Layer

- `includes/bootstrap.php` — session, error/log config, autoload, db bootstrap
- `includes/config.php` — constants/app config
- `includes/database.php` — PDO singleton (`ATTR_EMULATE_PREPARES=false`)
- `includes/functions.php` — helper ทั่วไป (escape/csrf/flash/json ฯลฯ)
- `includes/auth.php` — auth guard (`requireAuth`, `requireGuest`)

## Security Controls (ตาม architecture.md 4.2)

- XSS: ใช้ `e()` ก่อน render ข้อมูลจากผู้ใช้/DB
- SQLi: Prepared statements เท่านั้น
- CSRF: ทุก POST form ใส่ token และ verify ฝั่ง controller
- Password: `password_hash()` / `password_verify()`
- Session hardening: `session_regenerate_id(true)` หลัง login
- Rate limit: auth flows (`register`, `login`) จำกัดต่อช่วงเวลา
- Error handling: API ส่ง JSON error, หน้าเว็บใช้ flash/toast และ log ลง `/logs/`

## UX/Responsive Conventions (Phase 9)

- รองรับ mobile 360px ด้วย `flex-wrap`, `overflow-x-auto`, และ bottom nav แบบ dynamic columns
- มี global loading overlay เมื่อ submit form / กดลิงก์ที่กำหนด
- destructive actions ใช้ confirm dialog
- empty state แสดงข้อความเชิญชวนแทน blank/error

## โมดูลหลักที่มีแล้ว

- Auth: `AuthService`, `api/auth.php`
- Shops: `ShopService`, `api/shops.php`, shop switcher/header modal
- Records: `RecordService`, `api/records.php`, `add-record.php`, `history.php`
- Dashboard + Goals: `DashboardService`, `GoalService`, `api/dashboard-data.php`, `api/goals.php`
- Overview (all shops): `OverviewService`, `overview.php`, `api/overview-data.php`
- Annual: `AnnualService`, `annual.php`, `api/annual-data.php`
- Export: `ExportService`, `api/export.php`

## Database

ไฟล์:

- `database/schema.sql`
- `database/sample_data.sql`

ตารางหลัก:

- `users`
- `shops`
- `daily_records`
- `monthly_goals`
- `idempotency_requests`
