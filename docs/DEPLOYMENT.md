# DEPLOYMENT.md — คู่มือขึ้น Production (ใช้งานจริง) สำหรับโปรเจกต์นี้

> เอกสารนี้สำหรับคนที่ **ติดตั้งระบบสำเร็จแล้ว** (เปิดเว็บได้/เชื่อม DB ได้) และต้องการนำไปใช้งานจริงแบบ production
>
> ❗ ถ้าคุณยัง “ติดตั้งไม่เสร็จ” หรือยังเปิดเว็บไม่ได้ ให้กลับไปทำตาม `docs/INSTALL.md` ก่อน (เอกสารนี้จะไม่อธิบายขั้นตอนติดตั้งซ้ำ)

**หมายเหตุจากการตรวจโครงสร้างโค้ดในโปรเจกต์นี้ (เพื่อกันเข้าใจผิด)**
- ไม่พบไฟล์ `install.php` ในโปรเจกต์นี้ → จึงไม่มีขั้นตอน “ลบ/ปิด install.php”
- ไม่พบตัวแปร `APP_DEBUG` → ระบบใช้ `APP_ENV=development|production` เพื่อเปิด/ปิดการแสดง error (`display_errors`)
- ไม่พบไฟล์ `.htaccess` ในโปรเจกต์นี้ → ถ้าต้องการ block ไฟล์สำคัญ ต้องทำผ่านการตั้งค่าเว็บเซิร์ฟเวอร์/โฮสต์
- ไม่พบระบบ role (admin/staff/member) ในฐานข้อมูล/โค้ดที่สแกนได้ → ผู้ใช้ทุกบัญชีสิทธิ์เท่ากัน

---

## 1) ภาพรวม Deployment

### Deployment คืออะไร
Deployment คือ “การนำระบบที่ติดตั้งแล้ว” ขึ้นไปไว้บนเครื่อง/โฮสต์ที่ให้คนใช้งานจริง (Production) โดยเน้น:
- ความปลอดภัย (Security)
- ความพร้อมใช้งาน (Availability)
- การดูแลระยะยาว (Backup / Logs / Cron)

### ต่างจาก Install ยังไง
- **Install**: ทำให้ระบบ “รันได้” ครั้งแรก (ตั้ง DB, สร้าง `.env`, import schema)
- **Deployment**: ทำให้ระบบ “ใช้งานจริงได้” อย่างปลอดภัย (ตั้งค่า production, ปิด dev-mode, ตั้ง log/backup/cron, ตรวจความเสี่ยง)

### ควรใช้เอกสารนี้ตอนไหน
- ✅ คุณเปิดระบบได้แล้วใน local หรือ staging
- ✅ คุณมีโดเมน/โฮสต์จริง และจะให้คนอื่นใช้งาน
- ✅ คุณต้องการเช็กลิสต์ก่อนเปิด production และวิธีดูแลหลังเปิด

---

## 2) Pre-Deployment Checklist (ก่อนเอาขึ้น production)

> เป้าหมาย: ลดโอกาส “ขึ้นแล้วล่ม” และทำให้ย้อนกลับได้ถ้าพัง

### 2.1 ตรวจเวอร์ชันและส่วนประกอบหลัก
- [ ] PHP **8.1+**
  - เหตุผล: โค้ดใช้ syntax/typing บางอย่างที่ต้องใช้ PHP 8.1+ (เช่น return type `never`)
- [ ] DB เป็น MySQL/MariaDB และรองรับ:
  - [ ] InnoDB + Foreign Key
  - [ ] `utf8mb4`
- [ ] PHP extensions ตามโค้ด:
  - [ ] `pdo_mysql` (จำเป็น — เชื่อม DB ผ่าน PDO ที่ `includes/database.php`)
  - [ ] `mbstring` (แนะนำ — ถ้าไม่มีระบบยังรันได้ แต่การตรวจความยาวข้อความภาษาไทยอาจไม่แม่น)

### 2.2 ตรวจสิทธิ์ไฟล์/โฟลเดอร์ (Permission)
- [ ] PHP ต้อง “เขียนไฟล์ log ได้” ตาม path ที่กำหนดใน `LOG_FILE`
  - โค้ดจะสร้างโฟลเดอร์ของ `LOG_FILE` อัตโนมัติ (`includes/bootstrap.php`) แต่ถ้าสร้างไม่ได้ ระบบอาจขึ้น 500
- [ ] ถ้าคุณตั้ง `LOG_FILE` ให้อยู่ในโปรเจกต์ เช่น `logs/php-error.log`:
  - [ ] โฟลเดอร์ `logs/` ต้องเขียนได้

### 2.3 สำรองข้อมูลก่อน (สำคัญมาก)
- [ ] สำรองฐานข้อมูล (อย่างน้อย 1 ไฟล์) ก่อนย้ายขึ้น production/ก่อนอัปเดตโค้ด
- [ ] เก็บไฟล์ `.env` ของ production ไว้ในที่ปลอดภัย (อย่าเก็บในที่สาธารณะ)
- [ ] ถ้าคุณไม่มีทีมเทคนิค แนะนำตั้งกติกา “ก่อนแก้อะไรต้อง backup ก่อนเสมอ”

### 2.4 Migration ที่ต้องรันบน database เดิม

⚠️ `database/schema.sql` เป็น **DROP + CREATE** — ห้ามรันทับ database ที่มีข้อมูล
การเปลี่ยนโครงสร้างบน DB เดิมให้รันไฟล์ใน `database/migrations/` แทน

| ไฟล์ | ทำอะไร | จำเป็นไหม |
|---|---|---|
| `2026-08-04-drop-idempotency-requests.sql` | ลบตาราง `idempotency_requests` ที่ไม่ถูกใช้งาน | ไม่บังคับ — ระบบทำงานได้ปกติถ้ายังไม่ลบ ตารางจะค้างอยู่เฉย ๆ |
| `2026-08-05-shop-name-collation.sql` | เปลี่ยนกติกาเทียบชื่อร้านให้แยกอิโมจิได้ | **บังคับ** — ไม่รัน = แอปตอบ 503 ทั้งระบบ (Schema Guard ปฏิเสธการบูต) |

```bash
mysql -u USER -p DBNAME < database/migrations/2026-08-04-drop-idempotency-requests.sql
mysql -u USER -p DBNAME < database/migrations/2026-08-05-shop-name-collation.sql
```

⚠️ **ไฟล์ที่สองบังคับ** — ก่อนแก้ ระบบมองว่าอิโมจิทุกตัวเป็นชื่อเดียวกัน ผู้ใช้ที่มี
ร้าน 🚀 แล้วสร้างร้าน 🎉 จะถูกพาไปที่ร้าน 🚀 พร้อมข้อความว่าสำเร็จ แล้วข้อมูลลงผิดร้าน

ไฟล์นี้จะ **เปลี่ยนชื่อร้านบางร้านโดยเติม ` #<เลขประจำร้าน>` ต่อท้าย** เมื่อกติกาใหม่
มองว่าชื่อสองร้านเหมือนกัน (เช่น `Da Nang` กับ `Đa Nang` ในภาษาเวียดนาม) —
**ไม่มีร้านไหนหาย ไม่มีข้อมูลในร้านหาย** และผู้ใช้เปลี่ยนชื่อกลับเองได้ในหน้าจัดการร้าน
คำสั่งท้ายไฟล์จะแสดงรายการที่ถูกเปลี่ยนให้ดู — **ดูให้ครบก่อนปิดหน้าจอ** แล้วแจ้งผู้ใช้

---

## 3) Production Configuration (`.env` สำหรับ production)

> หลักการ: production ต้อง “ชัดเจน” และ “ปลอดภัย” มากกว่า dev

### 3.1 ค่าใน `.env` ที่ “ต้องตั้ง/ต้องเปลี่ยน” เมื่อขึ้น production
- [ ] `APP_ENV=production`
- [ ] `APP_URL` ต้องเป็น **URL แบบเต็ม** และเป็น **https**
  - ตัวอย่าง: `https://yourdomain.com` หรือ `https://yourdomain.com/ad-profit`
  - เหตุผล: ใน `includes/config.php` ถ้าเป็น production แล้ว `APP_URL` ว่าง ระบบจะตั้ง `APP_URL` เป็นค่าว่างเพื่อความปลอดภัย (กัน Host header poisoning)
- [ ] `DB_*` ต้องเป็นค่าของฐานข้อมูลบนเครื่อง/โฮสต์จริง
- [ ] `EXPOSE_DEV_RESET_LINK=false`
  - เหตุผล: ถ้าเปิดใน dev จะโชว์ลิงก์รีเซ็ตรหัสผ่านบนหน้า `forgot-password.php`
- [ ] `LOG_FILE` แนะนำให้ชี้ไป path ที่ **ไม่อยู่ใน web root** (ถ้าโฮสต์ทำได้)

### 3.2 ค่าใน `.env` ที่ “ไม่ควรเปลี่ยนถ้าไม่เข้าใจ”
- `DB_CHARSET` (แนะนำคง `utf8mb4`)
- `SCHEMA_GUARD_ENABLED` (แนะนำให้เปิด `true`)
  - ถ้าปิด: DB schema ไม่ตรงอาจพังแบบเงียบ ๆ
- `TRUST_PROXY` / `TRUSTED_PROXIES`
  - อย่าเปิดถ้าคุณไม่รู้ว่า proxy คืออะไรและไม่รู้ IP ที่เชื่อถือได้

### 3.3 ตัวอย่าง `.env` ที่ปลอดภัย (Production)
> ใส่ค่าให้ครบ โดยเฉพาะ `APP_URL` และ `DB_*`

```env
APP_ENV=production
APP_NAME="Ad Profit"

# ต้องเป็น https:// แบบเต็ม และต้องรวม path ถ้าติดตั้งใน subfolder
APP_URL=https://yourdomain.com/ad-profit

APP_TIMEZONE=Asia/Bangkok

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=your_db_name
DB_CHARSET=utf8mb4
DB_USER=your_db_user
DB_PASS=your_db_password

# Session timeout (seconds)
SESSION_IDLE_TIMEOUT_SECONDS=14400
SESSION_ABSOLUTE_TIMEOUT_SECONDS=86400

PASSWORD_MIN_LENGTH=8
PASSWORD_RESET_TOKEN_TTL_HOURS=1

SCHEMA_GUARD_ENABLED=true

# ตั้ง true เฉพาะเมื่อคุณอยู่หลัง proxy ที่ “เชื่อถือได้” และรู้ IP แน่นอน
TRUST_PROXY=false
TRUSTED_PROXIES=

# ห้ามเปิดใน production
EXPOSE_DEV_RESET_LINK=false

# แนะนำให้อยู่ “นอก web root” ถ้าโฮสต์อนุญาต
LOG_FILE=

# Email (เปิดเฉพาะถ้าจะใช้ส่งอีเมลจริง)
MAIL_ENABLED=false
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_TIMEOUT_SECONDS=15
MAIL_RETRY_ATTEMPTS=1
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Ad Profit"
```

> หมายเหตุ: ในโค้ดปัจจุบัน `RATE_LIMIT_MAX_ATTEMPTS` และ `RATE_LIMIT_WINDOW_SECONDS` ถูกกำหนดเป็นค่าคงที่ใน `includes/config.php` (ไม่ได้อ่านจาก `.env`)

---

## 4) Security Checklist (สำคัญ)

### 4.1 Error/Debug ใน production
- [ ] ไม่เปิดโชว์ error บนหน้าเว็บ
  - โปรเจกต์นี้ไม่มี `APP_DEBUG`
  - การเปิด/ปิด `display_errors` อิงจาก `APP_ENV` ที่ `includes/bootstrap.php`
- [ ] ให้ debug ผ่าน log เป็นหลัก (`LOG_FILE`)

### 4.2 Session / Cookie
สิ่งที่ระบบตั้งมาแล้ว (จาก `includes/bootstrap.php`):
- `cookie_httponly=true`
- `cookie_samesite=Lax`
- `use_strict_mode=true`
- ถ้า `APP_ENV=production` จะบังคับ `cookie_secure=true` เสมอ

เช็กลิสต์ที่คุณต้องทำ:
- [ ] เปิดใช้ HTTPS จริง (ถ้าเข้า HTTP จะมีอาการเหมือน session หาย/login ไม่ติด)
- [ ] ตั้ง `APP_URL=https://...` ให้ถูกต้อง

### 4.2.1 Security Headers ที่ตั้งมาแล้ว
ระบบตั้ง HTTP response headers ไว้ใน `includes/bootstrap.php`:
- `X-Frame-Options: DENY` (กัน clickjacking)
- `Content-Security-Policy: frame-ancestors 'none'` (กัน embedding)
- `X-Content-Type-Options: nosniff` (กัน MIME sniffing)
- `Referrer-Policy: same-origin` (ลดการรั่วไหลของ referer)
- `Permissions-Policy: geolocation=(), microphone=(), camera=()` (ปิด API ที่ไม่ใช้)

### 4.3 `.htaccess` / กฎบล็อกไฟล์สำคัญ
- โปรเจกต์นี้ **ไม่พบ** `.htaccess` ใน repo
- ถ้าคุณใช้ Apache/Shared Hosting ที่รองรับ `.htaccess`:
  - [ ] **ควร** บล็อกการเข้าถึงไฟล์/โฟลเดอร์ที่ไม่ควรให้คนโหลดได้ เช่น:
    - `/.env`, `/.env.example`
    - `/database/*.sql`
    - `/cron/*`
    - `/vendor/*` (อย่างน้อยป้องกันการ directory listing)
    - `/logs/*`

> ถ้าคุณไม่แน่ใจ ให้ขอให้ผู้ดูแลโฮสต์ช่วยตั้ง “deny access” สำหรับรายการข้างบน

### 4.4 uploads / logs
- `uploads/`:
  - ในโค้ดที่สแกน **ไม่พบการอัปโหลดไฟล์** (`$_FILES`/`move_uploaded_file`) แต่โฟลเดอร์มีอยู่
  - [ ] แนะนำทำให้โฟลเดอร์นี้ “รันไฟล์ .php ไม่ได้” (ป้องกันกรณีมีไฟล์หลุดเข้าไป)
- `logs/`:
  - [ ] ถ้าใช้ `LOG_FILE` ชี้เข้า `logs/` ต้องทำให้โฟลเดอร์ไม่ถูกเปิดอ่านจากหน้าเว็บ (หรือย้าย log ไปนอก web root)

### 4.5 ไฟล์ที่ควร “ไม่เอาขึ้น production” หรืออย่างน้อยควรปิดการเข้าถึง
- [ ] `database/schema.sql`, `database/sample_data.sql` (ไม่ควรให้คนดาวน์โหลดได้)
- [ ] `qa_runner.php` (เป็นไฟล์สำหรับ QA/ทดสอบ; โค้ดตั้งให้ CLI-only แล้ว แต่ถ้าไม่ใช้ แนะนำไม่ต้องอัปโหลด)

---

## 5) Deploy ขึ้น Hosting / Server จริง

> ส่วนนี้จะไม่อธิบายการติดตั้งซ้ำ แต่จะเน้น “ย้ายขึ้น production ให้ปลอดภัย”

### 5.1 แนวทาง deploy บน Shared Hosting (ภาพรวม)
- [ ] อัปโหลดไฟล์โปรเจกต์ขึ้นโฮสต์
- [ ] ตั้ง `.env` สำหรับ production (ดูหัวข้อ 3)
- [ ] ตรวจว่าเข้าเว็บผ่าน HTTPS ได้
- [ ] ตั้ง `LOG_FILE` ให้เหมาะกับโฮสต์
- [ ] ตั้ง Cron (หัวข้อ 7)

### 5.2 การย้ายจาก local → server (กรณีมีข้อมูลจริงแล้ว)
แนวทางที่ปลอดภัย:
- [ ] “หยุดการใช้งานชั่วคราว” (กันข้อมูลเพิ่มระหว่างย้าย)
- [ ] Backup ฐานข้อมูลจากเครื่องเดิม
- [ ] นำ backup ไป restore ที่ server
- [ ] อัปโหลดโค้ดเวอร์ชันเดียวกันขึ้น server
- [ ] ตั้ง `.env` ให้ชี้ DB ใหม่ + ตั้ง `APP_URL` ใหม่
- [ ] เปิดระบบและทดสอบตามหัวข้อ 6

> ⚠️ ข้อควรระวัง: `database/schema.sql` มี `DROP TABLE` หลายตาราง
> - ใช้ได้กับ “ติดตั้งใหม่”
> - ไม่ควรรันทับใน production ที่มีข้อมูลจริง (ข้อมูลจะหาย)

### 5.3 การแก้ `APP_URL` หลังย้ายโดเมน/ย้ายโฟลเดอร์
- ถ้าเปลี่ยนโดเมน หรือย้ายจาก `/` → `/subfolder` ต้องแก้ `APP_URL` ให้ตรง
- `APP_URL` ควรไม่มี `/` ปิดท้าย (โค้ดจะ `rtrim` ให้)

ตัวอย่าง:
- ติดตั้งที่ root: `APP_URL=https://yourdomain.com`
- ติดตั้งในโฟลเดอร์: `APP_URL=https://yourdomain.com/ad-profit`

### 5.4 การ import database (กรณีติดตั้งใหม่)
- ให้ทำตาม `docs/INSTALL.md` (เอกสารนี้ไม่อธิบายซ้ำ)

---

## 6) Post-Deployment Verification (ทดสอบหลัง deploy)

### 6.1 Checklist ขั้นต่ำที่ควรเทสทุกครั้ง
- [ ] เปิดเว็บผ่าน `APP_URL` แล้วไม่ redirect แปลก ๆ
- [ ] สมัครสมาชิกได้
- [ ] Login ได้ และ session ไม่หลุดทันที
- [ ] สร้าง/สลับร้านได้ (`shops.php`)
- [ ] เพิ่มข้อมูลรายวันได้ (`add-record.php`)
- [ ] ดูประวัติ/Export CSV ได้ (`history.php` → export)

### 6.2 Flow ที่ควรเทสเสมอ “ถ้าเปิดใช้อีเมลจริง”
- [ ] ขอรีเซ็ตรหัสผ่าน (`forgot-password.php`) แล้วได้รับอีเมลจริง
- [ ] ลิงก์รีเซ็ตพาไปหน้าได้ และตั้งรหัสใหม่ได้

### 6.3 สัญญาณเตือนว่าระบบผิดปกติ
- เห็นหน้า 503 “ต้องอัปเกรดโครงสร้างฐานข้อมูล” → schema ไม่ตรง
- เจอ 500 “เกิดข้อผิดพลาด” บ่อย → ต้องเปิด log ไล่สาเหตุ
- login ไม่ติด/หลุดบ่อยใน production → เช็ค HTTPS + `APP_ENV` + `APP_URL`
- ผู้ใช้บอกว่าไม่ได้รับอีเมลรีเซ็ต → เช็ค `MAIL_*` + log

---

## 7) Cron Jobs (ถ้ามีในโค้ด)

พบ cron ในโปรเจกต์นี้ 2 ตัว:
1) `cron/cleanup-logs.php`
   - ลบไฟล์ในโฟลเดอร์ `logs/` ที่เก่ากว่า 30 วัน
2) `cron/cleanup-password-reset-tokens.php`
   - ลบ token รีเซ็ตรหัสผ่านที่หมดอายุ

ผลกระทบถ้าไม่ตั้ง cron:
- `logs/` อาจสะสมไฟล์มากขึ้น (ถ้าคุณเก็บ log ไว้ในโฟลเดอร์นี้)
- ตาราง `password_reset_tokens` และ `auth_rate_limits` อาจโตขึ้นตามเวลา (ขึ้นกับการใช้งาน)

> cron ทั้ง 2 ตัวบังคับให้รันผ่าน CLI เท่านั้น (ถ้าเรียกผ่านเว็บจะได้ `403 Forbidden`)

ตัวอย่างคำสั่ง (ปรับ path และ php binary ให้ตรงโฮสต์):
```bash
php /path/to/project/cron/cleanup-logs.php
php /path/to/project/cron/cleanup-password-reset-tokens.php
```

แนะนำความถี่:
- [ ] วันละครั้งก็พอ (เช่น กลางคืน)

---

## 8) Logs & Debugging

### 8.1 ดู error จากตรงไหน
- ดูจากไฟล์ที่กำหนดใน `LOG_FILE` ใน `.env`
- ถ้าไม่ได้ตั้ง `LOG_FILE`:
  - ระบบจะใช้ค่าเริ่มต้นเป็น Temp directory ของเครื่อง (ดู `includes/config.php`)

### 8.2 เปิด debug ชั่วคราวอย่างปลอดภัย
แนวทางที่แนะนำ:
- ✅ ดูจาก log เป็นหลัก
- ⚠️ ถ้าจำเป็นต้องเห็น error บนหน้า (ไม่แนะนำใน production):
  - การตั้ง `APP_ENV=development` จะทำให้ `display_errors=1`
  - ควรทำเฉพาะช่วงเวลาสั้น ๆ และควรจำกัดการเข้าถึง (เช่น ทำใน staging หรือจำกัด IP ที่หน้าเว็บเซิร์ฟเวอร์)

### 8.3 สิ่งที่ไม่ควรเปิดใน production
- [ ] `APP_ENV=development`
- [ ] `EXPOSE_DEV_RESET_LINK=true`

---

## 9) Backup & Recovery

### 9.1 ควร backup อะไรบ้าง
- [ ] ฐานข้อมูล (สำคัญที่สุด)
- [ ] ไฟล์ `.env` (เพราะมี config และ secret)
- [ ] โค้ดโปรเจกต์ (อย่างน้อยก่อนอัปเดต)

### 9.2 ความถี่ที่แนะนำ (แบบระบบเล็ก)
- [ ] Backup DB รายวัน
- [ ] Backup DB ก่อนอัปเดตโค้ดทุกครั้ง
- [ ] เก็บย้อนหลังอย่างน้อย 7–30 วัน (ตามพื้นที่และความสำคัญ)

### 9.3 ถ้าระบบพัง ควรทำอะไรก่อน
- 1) ดู log ตาม `LOG_FILE`
- 2) เช็คว่า DB ยังออนไลน์ และ `.env` ชี้ DB ถูก
- 3) ถ้าขึ้น 503 schema guard → อย่าเพิ่งรัน `schema.sql` ทับถ้ามีข้อมูลจริง ให้สำรอง DB แล้วค่อยแก้ schema แบบระมัดระวัง
- 4) ถ้าแก้ไม่ทัน ให้ rollback:
  - คืนโค้ดเวอร์ชันเดิม
  - restore DB จาก backup ล่าสุด

---

## 10) ข้อจำกัดของระบบ (พูดตรง)

ระบบนี้เหมาะกับ:
- ร้าน/ทีมเล็ก (ผู้ใช้น้อย)
- เดโม/สอน/ขายเป็น template

ข้อจำกัดที่ควรรู้ (จากโค้ดที่สแกน):
- ไม่พบระบบ role/permission แบบ admin/staff/member → ถ้าต้องแยกสิทธิ์ละเอียด ต้องพัฒนาเพิ่ม
- ไม่มีระบบ queue/background worker (งานส่วนใหญ่ทำตอนผู้ใช้กด)
- ไม่มีระบบ audit log แบบละเอียด
- การอัปเดตโครงสร้าง DB ไม่มี migration อัตโนมัติใน repo; `database/schema.sql` เป็นแบบ drop+create จึงต้องระวังมากเมื่อมีข้อมูลจริง

สรุปแบบจริงใจ:
- ถ้าคุณต้องการระบบเล็ก ๆ ที่ดูแลง่าย → ใช้ได้
- ถ้าคุณจะเปิดให้คนใช้จำนวนมาก/มีข้อกำหนดความปลอดภัยสูง → ควรมีคนเทคนิคช่วยดูแลและวางแผนเพิ่ม
