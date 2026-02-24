# FLOW.md — ภาพรวมการทำงานของระบบ (Ad‑Profit)

เอกสารนี้ทำไว้เพื่อช่วยให้คนที่ **ไม่เคยเห็นโค้ดนี้มาก่อน** เข้าใจ "ลำดับการทำงานของระบบ" แบบเห็นภาพ โดยไม่ต้องไล่อ่านทุกไฟล์ทีละบรรทัด

> ✅ เหมาะสำหรับ: อธิบายลูกค้า / สอนนักเรียน / ตอบคำถามซัพพอร์ต
>
> ❗ หมายเหตุ: อธิบายจากโค้ดจริงในโปรเจกต์นี้เท่านั้น

---

## 1) 🧭 ภาพรวมระบบ (System Overview)

### ระบบนี้ทำอะไร
Ad‑Profit คือระบบบันทึกและสรุปผล **รายได้ / ค่าโฆษณา / กำไร** แบบรายวัน โดยแยกข้อมูลเป็น "ร้าน" (Shop) ได้หลายร้านในบัญชีเดียว

สิ่งที่ผู้ใช้ทำได้หลัก ๆ:
- สมัครสมาชิก / เข้าสู่ระบบ
- สร้างร้านหลายร้าน และสลับร้านที่กำลังใช้งาน
- บันทึกข้อมูลรายวัน (รายได้, ค่าแอด, โน้ต)
- ดู Dashboard สรุปผลตามช่วงเวลา (สัปดาห์/เดือน/กำหนดเอง)
- ตั้งเป้าหมายรายเดือน (ยอดขาย/กำไร)
- ดูประวัติรายเดือน + แก้ไข/ลบรายการ
- Export เป็นไฟล์ CSV
- ดูภาพรวมทุกร้านในเดือนเดียวกัน
- ดูสรุปประจำปีของร้านที่กำลังใช้งาน

### ใครเป็นผู้ใช้งานบ้าง (Roles)
- ✅ พบ "ผู้ใช้" 1 ประเภท (สมัคร/ล็อกอินได้)
- ❗ **ไม่พบ** ระบบแยก role แบบ `admin / staff / member` ในโค้ดและฐานข้อมูลเวอร์ชันนี้
  - ทุกบัญชีมีสิทธิ์ทำได้เท่ากัน

### ข้อมูลหลักที่ระบบจัดการ (Data หลัก)
อ้างอิงจาก `database/schema.sql`:
- `users` 👤: ผู้ใช้ (email, display_name, password_hash, session_version)
- `shops` 🏪: ร้านของผู้ใช้ (1 user มีได้หลาย shop)
- `daily_records` 📅: บันทึกรายวันของแต่ละร้าน (รายได้, ค่าแอด, โน้ต)
- `monthly_goals` 🎯: เป้าหมายรายเดือนของแต่ละร้าน
- `password_reset_tokens` 🔑: โทเคนสำหรับลืมรหัสผ่าน (เก็บแบบ hash + มีวันหมดอายุ)
- `auth_rate_limits` 🧯: เก็บข้อมูล rate limit (กันลอง login/register/รีเซ็ตรหัสผ่านถี่เกิน)
- `idempotency_requests` 🧾: มีตารางและมี cron สำหรับล้างข้อมูลหมดอายุ
  - ❗แต่ในโค้ดเวอร์ชันนี้ **ไม่พบ** จุดที่สร้าง/อ่านข้อมูลตารางนี้จาก flow หน้าเว็บหลัก

---

## 2) 🗂️ โครงสร้างการไหลของระบบ (High-level Flow)

โปรเจกต์นี้เป็น PHP แบบ "เปิดไฟล์ .php ตามหน้า" (ไม่ใช้ framework)

ภาพรวมชั้นการทำงาน (Layer) ที่ใช้ในโปรเจกต์นี้:

```
ผู้ใช้ (Browser)
  ↓
หน้าเว็บ .php (เช่น dashboard.php, shops.php)
  ↓ include
includes/bootstrap.php  (โหลด .env, ตั้งค่า session, ต่อ DB, ตั้ง error log)
  ↓
(ตรวจสิทธิ์) includes/auth.php  → requireAuth()
  ↓
Service (app/Services/*)  = กฎธุรกิจ + validation + สรุปผล
  ↓
Repository (app/Repositories/*) = คุยกับฐานข้อมูล (SQL)
  ↓
Database (MySQL/MariaDB)
```

### หน้าที่ของแต่ละชั้น (อธิบายแบบภาษาคน)
- **หน้าเว็บ / API endpoint (เหมือน Controller)**
  - รับ input จาก `GET/POST`
  - เรียก Service
  - ส่งผลกลับเป็น HTML/Redirect หรือ JSON
  - ตัวอย่างไฟล์:
    - หน้าเว็บ: `dashboard.php`, `shops.php`, `add-record.php`, `history.php`, `profile.php`, `overview.php`, `annual.php`
    - API: `api/auth.php`, `api/shops.php`, `api/records.php`, `api/goals.php`, `api/export.php`

- **Service (`app/Services/*`)**
  - เป็น "สมองของระบบ" รวม logic ที่ไม่อยากให้กระจัดกระจายอยู่ตามหน้า
  - ทำ validation, คำนวณสรุป, จัดรูปข้อมูลเพื่อส่งให้หน้าเว็บ

- **Repository (`app/Repositories/*`)**
  - เป็น "มือที่คุยกับ DB" ทำ SQL แบบเป็นจุด ๆ
  - เปลี่ยนโครงสร้าง DB หรือ query มักแก้ที่ชั้นนี้

- **Database**
  - เก็บข้อมูลจริงทั้งหมด

### ตัวช่วยสำคัญที่เจอบ่อย
- `includes/config.php` ⚙️
  - โหลด `.env` แล้ว define ค่าคงที่ (APP_ENV, APP_URL, DB_*, ฯลฯ)
- `includes/bootstrap.php` 🚀
  - start session + ตั้งค่า cookie
  - ตั้ง error log (`LOG_FILE`) และ exception handler
  - ต่อ DB (`$pdo = db()`)
  - มี Schema Guard: ถ้า DB schema ไม่ตรง ระบบจะขึ้น 503
- `includes/functions.php` 🧰
  - `csrf_token()` / `verify_csrf()` (กัน CSRF)
  - `api_respond()` (ถ้าเป็น XHR ตอบ JSON / ถ้าเป็น form ตอบ redirect + flash message)
  - `client_ip()` (รองรับ proxy แบบตั้งค่าได้)
- `includes/auth.php` 🔐
  - `requireAuth()` / `requireGuest()`
  - ตรวจ session timeout + ตรวจ `session_version` ใน DB

---

## 3) 🔐 Flow: Authentication / Login

### 3.1 ผู้ใช้กรอกอะไร
- Email
- Password

(หน้า UI หลักคือ `login.php`)

### 3.2 ระบบตรวจอะไรบ้าง (ภาพรวม)
เส้นทางหลัก:
- `login.php` (แสดงฟอร์ม) → ส่ง `POST` ไป `api/auth.php` (`action=login`)

สิ่งที่ระบบทำใน `api/auth.php`:
- ตรวจว่าเป็น `POST`
- ตรวจ CSRF token
- เรียก `AuthService->login()`

สิ่งที่ระบบทำใน `AuthService->login()`:
- normalize email + ตรวจข้อมูลว่าง
- ตรวจ rate limit (กันลองรหัสผิดถี่ ๆ)
- โหลด user จาก DB (`UserRepository->findByEmail()`)
- ตรวจรหัสผ่านด้วย `password_verify()`
- หา "ร้านแรก" ของ user
  - ถ้ายังไม่มีร้าน: ระบบจะสร้างร้านเริ่มต้นให้
- เขียน `last_login_at` (แบบ best-effort)
- สร้าง session (ดูหัวข้อถัดไป)

### 3.3 password ถูกจัดการยังไง
- เก็บใน DB เป็น `password_hash` (hash) เท่านั้น (ดูตาราง `users.password_hash`)
- ตอนสมัครสมาชิกใช้ `password_hash()`
- ตอน login ตรวจด้วย `password_verify()`

### 3.4 session ถูกสร้างตอนไหน
หลัง login สำเร็จ `AuthService` จะเรียกการ "ตั้ง session" (เช่น `session_regenerate_id(true)` และเก็บค่าใน `$_SESSION`)

ค่าที่สำคัญใน session ที่ใช้ทั้งระบบ:
- `user_id`, `email`
- `current_shop_id`, `current_shop_name`
- `session_version`
- `auth_started_at`, `last_activity_at`
- (ทางเลือก) `display_name`
  - จะถูกอัปเดตใน session หลังผู้ใช้บันทึกจากหน้า `profile.php` (`api/profile.php` action `update_profile`)

### 3.5 จุดที่เน้นความปลอดภัย (จากโค้ด)
- ✅ CSRF protection ในทุก action ที่เป็น POST
- ✅ session cookie ตั้งค่า `HttpOnly` และ `SameSite=Lax` (`includes/bootstrap.php`)
- ✅ ถ้า `APP_ENV=production` จะบังคับ cookie เป็น `Secure` (ต้องเข้าเว็บผ่าน HTTPS)
- ✅ มี rate limit (login/register/รีเซ็ต)
- ✅ มีการ `session_regenerate_id(true)` ตอน login เพื่อกัน session fixation

---

## 4) 👤 Flow: การจัดการผู้ใช้ (User Management)

> ส่วนนี้คือ "ผู้ใช้จัดการบัญชีตัวเอง" ไม่ใช่การจัดการผู้ใช้คนอื่น

### 4.1 การสร้าง user (Register)
- UI อยู่ที่ `login.php` (แท็บสมัครสมาชิก)
- ส่ง `POST` ไป `api/auth.php` (`action=register`)
- `AuthService->register()` จะ:
  - ตรวจ email format + ความยาว
  - ตรวจความยาวรหัสผ่านตาม `PASSWORD_MIN_LENGTH`
  - ตรวจ password confirm
  - hash รหัสผ่านด้วย `password_hash()`
  - ทำ DB Transaction เพื่อ:
    - สร้าง user
    - สร้าง "ร้านเริ่มต้น" ให้ทันที (`DEFAULT_SHOP_NAME`)
  - จากนั้นสร้าง session และพาไป `dashboard.php`

### 4.2 การแก้ไขข้อมูล
หน้าโปรไฟล์: `profile.php`

การทำงานโดยรวม:
- `profile.php` โหลดข้อมูลด้วย `ProfileService->getProfile()`
- ตอนกดบันทึก จะส่ง `POST` ไป `api/profile.php` พร้อม `action` เช่น:
  - `update_profile` (แก้ชื่อที่แสดง)
  - `change_email` (เปลี่ยนอีเมล + ต้องใส่รหัสผ่านปัจจุบัน)
  - `change_password` (เปลี่ยนรหัสผ่าน + ต้องใส่รหัสผ่านปัจจุบัน)

จุดที่ Service/Repository ทำงานร่วมกัน:
- API (`api/profile.php`) ทำหน้าที่ตรวจ POST/CSRF แล้วเรียก Service
- Service (`ProfileService`) ทำ validation และเรียก Repository (`UserRepository`) เพื่อ update

### 4.3 การแยก role (admin / staff / member)
- ❗ **ไม่พบในโปรเจกต์นี้**
  - ไม่มีคอลัมน์ role ในตาราง `users`
  - ไม่มี middleware/guard ที่เช็ค role

---

## 5) 🔄 Flow หลักของระบบ (Core Business Flow)

ด้านล่างคือ flow หลักที่คนใช้จริงจะเจอทุกวัน (อธิบายแบบ step-by-step)

### 5.1 สร้าง/สลับ/ลบร้าน 🏪
เริ่มจากหน้า `shops.php`

- `shops.php`
  - โหลดรายชื่อร้านของ user ผ่าน `ShopService->getShopContext()`
  - เก็บร้านที่กำลังใช้งานไว้ใน session (`current_shop_id`, `current_shop_name`)

- ตอนผู้ใช้กด "สร้างร้าน / เปลี่ยนชื่อ / สลับร้าน / ลบร้าน"
  - จะส่ง `POST` ไปที่ `api/shops.php` พร้อม `action` เช่น:
    - `create`, `rename`, `switch`, `delete`
  - API ตรวจ CSRF แล้วเรียก `ShopService` ทำงาน
  - หลังสำเร็จ ระบบจะอัปเดต session ให้ชี้ร้านใหม่/ร้านที่เลือก

แนวคิดสำคัญ:
- ข้อมูลทุกอย่าง "ผูกกับร้าน" ผ่าน `shop_id`
- ลบร้าน = ลบข้อมูลในร้านทั้งหมด (เพราะมี FK `ON DELETE CASCADE`)

### 5.2 บันทึกข้อมูลรายวัน 📅
เริ่มจากหน้า `add-record.php`

- `add-record.php` แสดงฟอร์มบันทึก และโชว์ "รายการล่าสุด 7 วัน"
- ตอนผู้ใช้กดบันทึก:
  - ฟอร์มส่งไป `api/records.php` (`action=upsert`)
  - API ตรวจ POST/CSRF + แปลงตัวเลข
  - เรียก `RecordService->upsertRecord()`
  - Repository จะ upsert ลงตาราง `daily_records`

สิ่งที่ทำให้มือใหม่ใช้ได้ง่าย:
- ถ้ากรอก "วันเดิม" ระบบจะ update ทับให้ (เพราะมี unique `(shop_id, record_date)`)

### 5.3 ดูประวัติรายเดือน + แก้ไข/ลบ 🧾
เริ่มจากหน้า `history.php`

- `history.php` โหลดรายการของเดือนที่เลือกด้วย `RecordService->getMonthlyRecords()`
- แก้ไข/ลบจะส่ง `POST` ไป `api/records.php`:
  - `action=update`
  - `action=delete`

จุดที่น่าสังเกต:
- ตอน "แก้ไข" มี transaction + lock (ดูหัวข้อ 6)
- มีหน้า export CSV ผ่าน `api/export.php?month=...`

### 5.4 Dashboard + เป้าหมายรายเดือน 📊🎯
เริ่มจากหน้า `dashboard.php`

- `dashboard.php` เรียก `DashboardService->buildDashboard()` เพื่อสรุปข้อมูลช่วงเวลาที่เลือก
- ในหน้าเดียวกันมีฟอร์มตั้งเป้าหมายรายเดือน:
  - ส่ง `POST` ไป `api/goals.php` (`action=upsert` หรือ `delete`)
  - `GoalService` จะบันทึกลง `monthly_goals`

### 5.5 ภาพรวมทุกร้าน (เลือกเดือน) 📦
เริ่มจากหน้า `overview.php`

- `overview.php` เรียก `OverviewService->buildOverview()`
- ได้ข้อมูลเปรียบเทียบ "ทุก shop ของ user" ตามเดือนที่เลือก

### 5.6 สรุปประจำปีของร้านที่กำลังใช้งาน 📆
เริ่มจากหน้า `annual.php`

- `annual.php` เรียก `AnnualService->buildYearlySummary()`
- แสดงยอดรวมรายเดือนทั้งปี (ตาม shop ปัจจุบัน)

---

## 6) ⚠️ จุดสำคัญที่ต้องเข้าใจเป็นพิเศษ

### 6.1 จุดที่มี transaction
- **สมัครสมาชิก (register)**
  - ใช้ transaction เพื่อสร้าง `users` + `shops` ให้สำเร็จพร้อมกัน
- **รีเซ็ตรหัสผ่าน (reset password)**
  - ใช้ transaction เพื่อ: lock โทเคน + เปลี่ยนรหัสผ่าน + ลบโทเคน
- **บันทึก/แก้ไข/ลบรายการรายวัน**
  - `RecordService` ใช้ TX + `FOR UPDATE` lock แถว
- **เปลี่ยนชื่อร้าน/ลบร้าน**
  - `ShopService` ใช้ TX + `FOR UPDATE` lock แถว
- **เปลี่ยนอีเมล/รหัสผ่าน**
  - `ProfileService` ใช้ TX + `FOR UPDATE` lock user row
- **บันทึก/ลบเป้าหมาย**
  - `GoalService` ใช้ TX เพื่อกันข้อมูลชนกัน

### 6.2 จุดที่มี lock / race condition
- **บันทึก/แก้ไข/ลบรายการรายวัน**
  - `RecordService` ใช้ `SELECT ... FOR UPDATE` เพื่อกันชนกัน
  - เคสที่กันไว้: upsert/update/delete พร้อมกัน, เปลี่ยน "วันที่" แล้วไปชนกับรายการที่มีอยู่แล้ว
- **เปลี่ยนชื่อร้าน/ลบร้าน**
  - `ShopService` ใช้ `FOR UPDATE` lock user+shop row
- **เปลี่ยนอีเมล/รหัสผ่าน**
  - `ProfileService` ใช้ `FOR UPDATE` lock user row
- **รีเซ็ตรหัสผ่าน**
  - `PasswordResetRepository->findByTokenHashForUpdate()` ใช้ `FOR UPDATE`
  - กันเคส: token เดียวกันถูกใช้ซ้ำ/ชนกันพร้อม ๆ กัน

### 6.3 จุดที่ "ห้ามแก้ถ้าไม่เข้าใจ"
- `APP_ENV` / `APP_URL` (เกี่ยวกับ security + ลิงก์ในระบบ)
- CSRF functions (`verify_csrf`, `ensure_valid_csrf_or_respond`)
- Session timeout (`SESSION_IDLE_TIMEOUT_SECONDS`, `SESSION_ABSOLUTE_TIMEOUT_SECONDS`)
- `SCHEMA_GUARD_ENABLED` (ช่วยกัน DB schema ไม่ตรงแล้วพังแบบเงียบ)
- `TRUST_PROXY` / `TRUSTED_PROXIES` (เกี่ยวกับการอ่าน IP จริง ใช้กับ rate limit)

---

## 7) 🛡️ ขอบเขตการใช้งานที่ควรรู้

ออกแบบมาเพื่อ:
- ระบบขนาดเล็ก / เดโม / งานส่ง / เทมเพลตขาย
- คนที่อยากศึกษาโค้ด PHP แบบเป็นขั้นเป็นตอน

ไม่เหมาะกับ:
- ระบบที่ต้องรองรับผู้ใช้จำนวนมากพร้อมกัน (scale สูง)
- งานที่ต้องมี role/permission ละเอียดมาก
- งานที่ต้องมี audit log หรือ workflow ซับซ้อน

ถ้าจะเอาไปต่อยอด production ต้องระวัง:
- การตั้งค่า `APP_ENV=production` และต้องใช้ HTTPS
- การดูแล log (`LOG_FILE`) และการตั้ง cron
- การอัปเกรด schema: โปรเจกต์มี `schema.sql` แบบ drop+create → ต้องระวังมากถ้ามีข้อมูลจริง

---

## 8) 🧠 วิธีอ่านโค้ดจาก FLOW นี้ (แนะนำสำหรับมือใหม่)

ถ้าอยากเริ่มอ่านโค้ด "แบบไม่หลง" ให้ไล่ตามลำดับนี้:

1) 🧩 จุดเริ่มต้นของระบบ
- `index.php` (redirect ไป login หรือ dashboard)

2) ⚙️ การตั้งค่า/การบูตระบบ
- `includes/config.php` (โหลด `.env` และกำหนดค่าคงที่)
- `includes/bootstrap.php` (session, log, exception handler, ต่อ DB, schema guard)

3) 🔐 ระบบยืนยันตัวตน
- `includes/auth.php` (requireAuth/requireGuest + session timeout)
- `api/auth.php` (register/login/logout/forgot/reset)
- `app/Services/AuthService.php`
- `app/Repositories/UserRepository.php`

4) 🏪 ร้านค้า
- `shops.php` + `api/shops.php`
- `app/Services/ShopService.php`
- `app/Repositories/ShopRepository.php`

5) 📅 รายการรายวัน (flow หลักที่สุด)
- `add-record.php` + `history.php` + `api/records.php`
- `app/Services/RecordService.php`
- `app/Repositories/RecordRepository.php`

6) 📊 สรุปผล
- `dashboard.php` + `app/Services/DashboardService.php`
- `overview.php` + `app/Services/OverviewService.php`
- `annual.php` + `app/Services/AnnualService.php`

> ทริค: เวลาอ่านหน้าเว็บ ให้มองหา `form action="/api/..."` แล้วตามไปดูว่า API เรียก service ตัวไหน

---

## 9) 📌 สรุปสั้นสำหรับผู้ซื้อ

ระบบนี้เหมาะกับ:
- คนที่อยากได้ "โปรเจกต์ PHP ใช้งานได้จริง" เอาไว้เรียน/เดโม/ส่งงาน
- ร้าน/ทีมเล็กที่อยากบันทึกตัวเลขรายวันแบบง่าย ๆ

สิ่งที่ใช้เรียนรู้ได้จากโปรเจกต์นี้:
- โครงสร้างแบบ Page → Service → Repository → DB (แยกหน้าที่ชัด)
- Session-based authentication + CSRF
- การทำ validation และการทำสรุปข้อมูล
- การใช้ PDO + prepared statements
- แนวคิด transaction/lock ในจุดที่สำคัญ

ควรรู้ก่อนนำไปใช้/ขายต่อ:
- ไม่มีระบบ role แยกสิทธิ์ในเวอร์ชันนี้
- เป็นเทมเพลตสำหรับระบบเล็ก ๆ ไม่ได้ออกแบบเพื่อ scale หนัก
- การดูแล production (log/backup/cron/HTTPS) ต้องทำให้ถูกตามเอกสาร `DEPLOYMENT.md`
