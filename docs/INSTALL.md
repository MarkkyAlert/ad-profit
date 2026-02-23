# คู่มือติดตั้ง Ad‑Profit (Local + Shared Hosting)

เอกสารนี้อธิบายการติดตั้งโปรเจกต์ **Ad‑Profit** (ระบบบันทึกรายได้/ค่าโฆษณา/กำไร รองรับหลายร้าน) ให้ใช้งานได้จริงทั้งบนเครื่องตัวเอง (XAMPP) และบน Shared Hosting

> ✅ เป้าหมาย: ให้ทำตามได้ทีละขั้น “อ่านแล้วไม่ต้องเดา”
>
> ⚠️ เอกสารนี้อ้างอิงจากโค้ด/โครงสร้างโปรเจกต์เท่านั้น (เช่น `includes/config.php`, `includes/bootstrap.php`, `database/schema.sql`, `cron/*`)

---

## A) ภาพรวมสั้น ๆ

สิ่งที่คุณกำลังติดตั้งคือเว็บแอป **PHP + MySQL/MariaDB** ที่ทำงานแบบ “เปิดไฟล์ .php ตามหน้า” (ไม่มี framework)

ภาพรวมการติดตั้งจะมี 4 ส่วนหลัก:
- 1) วางไฟล์โปรเจกต์ไว้บนเว็บเซิร์ฟเวอร์
- 2) สร้างฐานข้อมูล แล้ว import `database/schema.sql`
- 3) สร้างไฟล์ `.env` จาก `.env.example`
- 4) เปิดเว็บ แล้วสมัครสมาชิก/ใช้งาน

---

## B) Requirements (สิ่งที่ต้องมี)

### B.1 PHP
- ✅ **PHP 8.1+**
  - เหตุผลจากโค้ด: มีการใช้ return type `never` (เช่น `includes/functions.php`)

### B.2 Web Server
- ✅ Web Server ที่รัน PHP ได้ เช่น Apache (XAMPP) หรือ Shared Hosting ที่รองรับ PHP
- ❗ ไม่พบไฟล์ `.htaccess` ในโปรเจกต์ → โดยปกติ **ไม่จำเป็นต้องเปิด mod_rewrite** (ระบบเรียกหน้าแบบ `*.php` ตรง ๆ)

### B.3 Database
- ✅ MySQL/MariaDB
- ✅ ต้องรองรับ **InnoDB**, Foreign Key, และ charset `utf8mb4`
- ✅ ต้องรองรับคอลัมน์ชนิด **JSON** (ดู `database/schema.sql` ตาราง `idempotency_requests.response_payload`)

### B.4 PHP Extensions (เท่าที่ตรวจพบจากโค้ด)
- ✅ `PDO` และไดรเวอร์ MySQL (`pdo_mysql`) — ระบบเชื่อมต่อ DB ผ่าน PDO (`includes/database.php`)
- ⚠️ ถ้าใช้งาน “ลืมรหัสผ่าน ส่งอีเมลจริง”:
  - ต้องมีไลบรารี PHPMailer (มาจาก `vendor/` หรือ `composer install`)
  - ระบบตั้งค่า `PHPMailer::ENCRYPTION_STARTTLS` ใน `app/Services/EmailService.php` → โฮสต์ต้องรองรับการเชื่อมต่อแบบ TLS ตามที่ผู้ให้บริการอีเมลกำหนด

### B.5 HTTPS (สำคัญมากใน Production)
- ✅ ถ้า `APP_ENV=production` ระบบจะบังคับ session cookie เป็น `Secure` เสมอ (`includes/bootstrap.php`) → เว็บต้องเข้าแบบ **HTTPS**

---

## C) โครงสร้างไฟล์ที่เกี่ยวข้องกับการติดตั้ง (ควรรู้จัก)

- `/.env.example` — ไฟล์ตัวอย่าง config (คัดลอกเป็น `.env`)
- `/.env` — ไฟล์ config จริง (ระบบอ่านจากไฟล์นี้โดยตรง)
- `/includes/config.php` — โค้ดที่โหลด `.env` และกำหนดค่าคงที่ (APP_ENV, APP_URL, DB_*)
- `/includes/bootstrap.php` — ไฟล์เริ่มระบบ (autoload, session, timezone, log, เชื่อม DB, schema guard)
- `/includes/database.php` — ฟังก์ชันเชื่อมต่อฐานข้อมูลผ่าน PDO
- `/database/schema.sql` — สคริปต์สร้างตารางทั้งหมด (มี DROP ตารางเดิมของระบบก่อนสร้างใหม่)
- `/database/sample_data.sql` — ข้อมูลตัวอย่าง (มี DELETE ข้อมูลเดิมของระบบก่อนใส่ข้อมูลใหม่)
- `/vendor/` + `/composer.json` + `/composer.lock` — ไลบรารีภายนอก (PHPMailer)
- `/logs/` — โฟลเดอร์เก็บ log (โปรเจกต์มี cron ล้างไฟล์เก่าในโฟลเดอร์นี้)
- `/uploads/` — โฟลเดอร์ในโปรเจกต์ (โปรเจกต์มีโฟลเดอร์นี้ แต่ **ไม่พบโค้ดอัปโหลดไฟล์** ในเวอร์ชันนี้)
- `/cron/cleanup-logs.php` — cron ลบไฟล์ใน `logs/` ที่เก่ากว่า 30 วัน
- `/cron/cleanup-idempotency.php` — cron ลบแถวหมดอายุในตาราง `idempotency_requests`

> ไม่พบไฟล์ `install.php` ในโปรเจกต์นี้

---

## D) ติดตั้งแบบ Local (XAMPP) — Step-by-step

> ตัวอย่างนี้อิงโครงสร้างโปรเจกต์ที่วางไว้ใต้ `c:\xampp\htdocs\ad-profit`

### D.1 วางไฟล์โปรเจกต์
- [ ] วางโฟลเดอร์โปรเจกต์ไว้ที่: `c:\xampp\htdocs\ad-profit`
- [ ] ตรวจสอบว่ามีไฟล์สำคัญอยู่จริง:
  - [ ] `index.php`
  - [ ] `includes/bootstrap.php`
  - [ ] `database/schema.sql`
  - [ ] `.env.example`

### D.2 ตรวจสอบไลบรารี (vendor)
- [ ] ถ้ามีโฟลเดอร์ `vendor/` อยู่แล้ว → ไปขั้นถัดไปได้
- [ ] ถ้าไม่มี `vendor/`:
  - [ ] รัน `composer install` ที่โฟลเดอร์โปรเจกต์ (ต้องมี Composer ในเครื่อง)

### D.3 สร้างฐานข้อมูล + Import schema
- [ ] เปิด XAMPP Control Panel → Start **Apache** และ **MySQL**
- [ ] เปิด phpMyAdmin: `http://localhost/phpmyadmin`
- [ ] สร้างฐานข้อมูลชื่อ `ad_profit` (แนะนำใช้ชื่อนี้เพื่อไม่ต้องแก้ไฟล์ SQL)
- [ ] Import ไฟล์ `database/schema.sql`

> ⚠️ `database/schema.sql` มีคำสั่ง `DROP TABLE` ของตารางระบบชุดนี้ (ถ้ามีอยู่แล้วข้อมูลจะหาย)

### D.4 (ทางเลือก) ใส่ข้อมูลตัวอย่าง
- [ ] Import ไฟล์ `database/sample_data.sql`

> ⚠️ `database/sample_data.sql` มีคำสั่ง `DELETE FROM ...` เพื่อล้างข้อมูลเดิมของระบบชุดนี้ก่อนใส่ข้อมูลใหม่
>
> ✅ ไฟล์นี้เพิ่มผู้ใช้ตัวอย่าง เช่น `demo@example.com`, `team@example.com` (ดู `database/sample_data.sql`)
>
> ❗ รหัสผ่านในไฟล์เป็นแบบ hash (ไม่ระบุรหัสผ่านเป็นข้อความ)
> - ถ้าจะใช้งานง่ายสุด: สมัครสมาชิกใหม่ที่ `login.php`
> - หรือใช้ `forgot-password.php` เพื่อรีเซ็ตรหัสผ่าน
>   - ใน local/dev สามารถตั้ง `EXPOSE_DEV_RESET_LINK=true` เพื่อให้เห็นลิงก์รีเซ็ตบนหน้า (ดู `forgot-password.php`)

### D.5 สร้างไฟล์ `.env`
- [ ] คัดลอก `.env.example` → `.env`
- [ ] ปรับค่า DB ให้ตรงกับเครื่องคุณ

**ตัวอย่าง `.env` (Local / XAMPP)**
```env
# โหมดระบบ: development | production
APP_ENV=development

APP_NAME="Ad Profit"

# Local สามารถเว้นว่างได้ (ระบบจะพยายามเดา path ให้เอง)
# แต่ถ้าตั้งเองก็ได้ เช่น http://localhost/ad-profit
APP_URL=

APP_TIMEZONE=Asia/Bangkok

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ad_profit
DB_CHARSET=utf8mb4
DB_USER=root
DB_PASS=

# Session timeout (วินาที)
SESSION_IDLE_TIMEOUT_SECONDS=14400
SESSION_ABSOLUTE_TIMEOUT_SECONDS=86400

# Security/behavior
PASSWORD_MIN_LENGTH=8
PASSWORD_RESET_TOKEN_TTL_HOURS=1
SCHEMA_GUARD_ENABLED=true

# Reverse proxy (ส่วนใหญ่ local ไม่ต้องใช้)
TRUST_PROXY=false
TRUSTED_PROXIES=

# Dev only
EXPOSE_DEV_RESET_LINK=true

# Log file (ปล่อยว่างได้ ระบบจะไปลง Temp อัตโนมัติ)
LOG_FILE=

# Email (ถ้ายังไม่ใช้ ให้ปิดไว้)
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

### D.6 เปิดใช้งาน
- [ ] เปิดเว็บ: `http://localhost/ad-profit/`
- [ ] ถ้าถูกพาไปหน้า `login.php` ถือว่า routing พื้นฐานทำงานแล้ว
- [ ] สมัครสมาชิก 1 บัญชี → ระบบจะสร้าง “ร้านแรก” ให้อัตโนมัติ

---

## E) ติดตั้งบน Shared Hosting — Step-by-step

> หมายเหตุ: Shared Hosting แต่ละเจ้าหน้าตาเมนูไม่เหมือนกัน แต่ “แนวคิด” จะตรงกัน

### E.1 อัปโหลดไฟล์โปรเจกต์
- [ ] อัปโหลดไฟล์ทั้งหมดขึ้นโฮสต์ (ผ่าน File Manager หรือ FTP)
- [ ] เลือกตำแหน่งติดตั้ง เช่น:
  - `public_html/ad-profit/` (เข้าเว็บเป็น `https://yourdomain.com/ad-profit/`)
  - หรือวางไว้ที่ `public_html/` (เข้าเว็บเป็น `https://yourdomain.com/`)

### E.2 ตรวจสอบ `vendor/`
- [ ] ถ้าคุณอัปโหลดมาจากเครื่องที่มี `vendor/` อยู่แล้ว → ข้ามได้
- [ ] ถ้าไม่มี `vendor/`:
  - [ ] ตรวจสอบว่าโฮสต์รองรับการรัน Composer หรือไม่
  - [ ] ถ้ารันได้ ให้รัน `composer install` ในโฟลเดอร์โปรเจกต์
  - [ ] ถ้ารันไม่ได้ ให้ติดตั้ง Composer ในเครื่อง local แล้วอัปโหลด `vendor/` ขึ้นไปแทน

### E.3 สร้างฐานข้อมูล
- [ ] สร้าง Database และ Database User จาก Control Panel ของโฮสต์
- [ ] จดค่าต่อไปนี้ไว้:
  - DB_HOST
  - DB_NAME
  - DB_USER
  - DB_PASS

### E.4 Import `database/schema.sql`
- [ ] เปิด phpMyAdmin บนโฮสต์
- [ ] เลือกฐานข้อมูลที่สร้างไว้
- [ ] Import ไฟล์ `database/schema.sql`

> ⚠️ ถ้า import แล้วขึ้น error แนว ๆ “permission denied / CREATE DATABASE denied”
> - สาเหตุ: บางโฮสต์ไม่ให้ `CREATE DATABASE`
> - วิธีแก้ (ต้องทำเอง): สร้าง DB จาก Control Panel แล้วค่อย import เฉพาะส่วน `CREATE TABLE ...` (ดูรายละเอียดในหัวข้อ Troubleshooting)

### E.5 สร้างไฟล์ `.env` (สำคัญมาก)
- [ ] คัดลอก `.env.example` → `.env`
- [ ] ตั้งค่า `APP_ENV=production`
- [ ] ตั้งค่า `APP_URL` ให้เป็น **URL แบบเต็ม** และเป็น **https**

**ตัวอย่าง `.env` (Production / Shared Hosting)**
```env
APP_ENV=production
APP_NAME="Ad Profit"

# ต้องเป็น URL แบบเต็มเท่านั้น เช่น https://yourdomain.com/ad-profit
APP_URL=https://yourdomain.com/ad-profit

APP_TIMEZONE=Asia/Bangkok

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=your_db_name
DB_CHARSET=utf8mb4
DB_USER=your_db_user
DB_PASS=your_db_password

SESSION_IDLE_TIMEOUT_SECONDS=14400
SESSION_ABSOLUTE_TIMEOUT_SECONDS=86400

PASSWORD_MIN_LENGTH=8
PASSWORD_RESET_TOKEN_TTL_HOURS=1
SCHEMA_GUARD_ENABLED=true

TRUST_PROXY=false
TRUSTED_PROXIES=

# ห้ามเปิดใน production
EXPOSE_DEV_RESET_LINK=false

# แนะนำให้เก็บ log นอก web root ถ้าโฮสต์อนุญาต (ดู .env.example)
LOG_FILE=

# Email (เปิดเฉพาะถ้าจะส่งอีเมลจริง)
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

### E.6 เปิดเว็บ
- [ ] เข้าเว็บตาม `APP_URL` ที่ตั้งไว้
- [ ] สมัครสมาชิก 1 บัญชี

---

## F) Permission / โฟลเดอร์ต้องเขียนได้

โปรเจกต์นี้ “ต้องเขียนไฟล์” ที่เกี่ยวกับ log ตามค่า `LOG_FILE`:
- `includes/bootstrap.php` จะสร้างโฟลเดอร์ของ `LOG_FILE` อัตโนมัติ (`mkdir(..., 0775, true)`)
- ถ้าโฟลเดอร์นั้นเขียนไม่ได้ จะเกิด error แล้วเว็บอาจขึ้น 500

### F.1 Local (Windows / XAMPP)
- [ ] ปกติ `LOG_FILE` ค่าเริ่มต้นจะไปอยู่โฟลเดอร์ Temp ของ Windows (ระบบสร้างโฟลเดอร์เอง)
- [ ] ถ้าตั้ง `LOG_FILE=logs/php-error.log`:
  - [ ] ให้แน่ใจว่าโฟลเดอร์ `logs/` เขียนได้

### F.2 Shared Hosting (Linux)
> โฮสต์แต่ละเจ้าตั้ง permission ต่างกัน ให้ดูจาก Control Panel ของโฮสต์เป็นหลัก

ตัวอย่างคำสั่ง (กรณีมี SSH):
```bash
# ให้เว็บเซิร์ฟเวอร์เขียน log ได้
chmod 775 logs uploads
```

---

## G) Post-install checklist (ทำทันทีหลังติดตั้ง)

- [ ] ตั้งค่า `.env` ให้ถูกต้อง (อย่างน้อย DB_* และ APP_ENV)
- [ ] ถ้าใช้งานจริง:
  - [ ] ตั้ง `APP_ENV=production`
  - [ ] ตั้ง `APP_URL` เป็น `https://...` แบบเต็ม
  - [ ] ตั้ง `EXPOSE_DEV_RESET_LINK=false`
- [ ] ทดสอบสมัครสมาชิก + login + เพิ่มข้อมูลรายวัน 1 รายการ
- [ ] ตรวจสอบไฟล์ log ตาม `LOG_FILE` ว่ามีการเขียนจริงเมื่อเกิด error

---

## H) ตั้งค่า Cron (ถ้ามี)

พบ cron scripts ในโปรเจกต์:
1) `cron/cleanup-logs.php`
   - ลบไฟล์ในโฟลเดอร์ `logs/` ที่เก่ากว่า **30 วัน** (ค่าถูกกำหนดในไฟล์นี้)
2) `cron/cleanup-idempotency.php`
   - ลบข้อมูลหมดอายุในตาราง `idempotency_requests` (ลบแถวที่ `expires_at < NOW()`)

> หมายเหตุ: cron ทั้ง 2 ตัว “บังคับให้รันผ่าน CLI เท่านั้น” (ถ้าเรียกผ่านเว็บจะตอบ `403 Forbidden`)

### H.1 ตัวอย่างคำสั่ง Cron (Shared Hosting)
> ปรับ path ให้ตรงกับโฮสต์ของคุณ

```bash
php /home/USERNAME/public_html/ad-profit/cron/cleanup-logs.php
php /home/USERNAME/public_html/ad-profit/cron/cleanup-idempotency.php
```

### H.2 ความถี่ในการรัน
- โค้ดไม่ได้บังคับความถี่ไว้
- ตัวอย่างที่พบบ่อย: ตั้งวันละครั้ง (เช่นตอนกลางคืน)

---

## I) Troubleshooting (อาการที่พบบ่อย + สาเหตุ + วิธีแก้)

> หลักการก่อนแก้: ดูไฟล์ log ก่อน โดยดูจาก `LOG_FILE` ใน `.env` (โหลดโดย `includes/config.php`)

### เคส 1: เปิดเว็บแล้วขึ้น 503 “ต้องอัปเกรดโครงสร้างฐานข้อมูล”
- **สาเหตุ**: ตาราง/คอลัมน์/ดัชนีใน DB ไม่ตรงกับที่ระบบต้องการ (Schema Guard ทำงาน)
- **วิธีแก้**:
  - Import `database/schema.sql` ใหม่
  - ตรวจว่า import สำเร็จครบทุกตาราง
- **ไฟล์เกี่ยวข้อง**: `includes/bootstrap.php` (Schema Guard)

### เคส 2: เปิดเว็บแล้วขึ้น 500 “เกิดข้อผิดพลาด” (หน้าเปล่า/หน้า error)
- **สาเหตุ**: มี Exception/Fatal error ภายใน
- **วิธีแก้**:
  - ดู log ตาม `LOG_FILE`
  - ตรวจว่า `.env` อ่านได้ และค่าต่อ DB ถูกต้อง
- **ไฟล์เกี่ยวข้อง**: `includes/bootstrap.php` (exception handler), `LOG_FILE` ใน `includes/config.php`

### เคส 3: เจอข้อความ/อาการ “Database connection failed”
- **สาเหตุ**: ต่อฐานข้อมูลไม่ได้ (host/port/user/pass/dbname ผิด หรือ DB ไม่ได้รัน)
- **วิธีแก้**:
  - เช็ค `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` ใน `.env`
  - เช็คว่าฐานข้อมูลออนไลน์และ user มีสิทธิ์
- **ไฟล์เกี่ยวข้อง**: `includes/database.php`

### เคส 4: Production แล้ว login ไม่ติด / เหมือน session หาย
- **สาเหตุ**: `APP_ENV=production` บังคับ cookie เป็น `Secure` → ถ้าเข้าเว็บผ่าน HTTP cookie จะไม่ถูกส่ง
- **วิธีแก้**:
  - เปิดใช้ HTTPS
  - ตั้ง `APP_URL` เป็น `https://...`
- **ไฟล์เกี่ยวข้อง**: `.env.example` (มี NOTE), `includes/bootstrap.php`

### เคส 5: ลิงก์/ปุ่มในเว็บพาไปผิด path (โดยเฉพาะติดตั้งใน subfolder)
- **สาเหตุ**: `APP_URL` ว่าง หรือไม่มี path ที่ถูกต้อง
- **วิธีแก้**:
  - ตั้ง `APP_URL` ให้ถูกต้อง เช่น `https://yourdomain.com/ad-profit`
- **ไฟล์เกี่ยวข้อง**: `includes/config.php` (กำหนด APP_URL), `includes/functions.php` (app_url)

### เคส 6: ขอ “ลืมรหัสผ่าน” แล้วขึ้น “ระบบยังไม่พร้อมใช้งานในขณะนี้” (Production)
- **สาเหตุ**: ใน production ระบบต้องการ `APP_URL` เป็น URL แบบเต็ม (absolute) เพื่อสร้างลิงก์รีเซ็ต
- **วิธีแก้**:
  - ตั้ง `APP_URL=https://...` แบบเต็ม
- **ไฟล์เกี่ยวข้อง**: `app/Services/AuthService.php` (ตรวจ APP_URL)

### เคส 7: ลืมรหัสผ่านแล้ว “ไม่ส่งอีเมล”
- **สาเหตุที่พบบ่อย**:
  - `MAIL_ENABLED=false`
  - ตั้ง `MAIL_USERNAME/MAIL_PASSWORD` ว่าง
  - โฮสต์บล็อก SMTP หรือข้อมูล SMTP ไม่ถูกต้อง
- **วิธีแก้**:
  - ตั้งค่า `MAIL_*` ให้ครบใน `.env` แล้วตั้ง `MAIL_ENABLED=true`
  - ดู log จะมีข้อความ `[email] ...`
- **ไฟล์เกี่ยวข้อง**: `includes/config.php` (MAIL_*), `app/Services/EmailService.php`

### เคส 8: Error ประมาณ “Class 'PHPMailer...' not found” หรือ log แจ้ง PHPMailer not installed
- **สาเหตุ**: ไม่มี `vendor/` หรือไม่ได้รัน Composer
- **วิธีแก้**:
  - รัน `composer install` หรืออัปโหลดโฟลเดอร์ `vendor/` ให้ครบ
- **ไฟล์เกี่ยวข้อง**: `composer.json`, `includes/bootstrap.php` (autoload), `app/Services/EmailService.php`

### เคส 9: รัน cron ผ่าน URL แล้วได้ `403 Forbidden`
- **สาเหตุ**: cron scripts บังคับให้รันผ่าน CLI เท่านั้น
- **วิธีแก้**:
  - ตั้ง cron ให้เรียก `php /path/to/script.php` จากระบบ cron ของโฮสต์
- **ไฟล์เกี่ยวข้อง**: `cron/cleanup-logs.php`, `cron/cleanup-idempotency.php`

### เคส 10: Import `database/schema.sql` บน Shared Hosting แล้ว error แนว ๆ “CREATE DATABASE denied”
- **สาเหตุ**: โฮสต์ไม่ให้สร้าง DB ผ่าน SQL
- **วิธีแก้**:
  - สร้าง DB จาก Control Panel ก่อน
  - แก้สคริปต์ตอน import โดย **ไม่รัน** ส่วน `CREATE DATABASE ...` และ `USE ...`
  - (ทางเลือก) เปิดไฟล์ `database/schema.sql` แล้วคัดลอกเฉพาะส่วน `CREATE TABLE ...` ไป import
- **ไฟล์เกี่ยวข้อง**: `database/schema.sql` (บรรทัดต้นไฟล์)

---

## J) Security notes ก่อนใช้งานจริง

- [ ] ตั้ง `APP_ENV=production`
- [ ] บังคับใช้ HTTPS (ดูเหตุผลที่ `includes/bootstrap.php` และ NOTE ใน `.env.example`)
- [ ] ตั้ง `APP_URL` เป็น `https://...` แบบเต็ม (รวม path ถ้าติดตั้งใน subfolder)
- [ ] ปิด `EXPOSE_DEV_RESET_LINK` เสมอใน production
- [ ] ตั้ง `LOG_FILE` ให้อยู่ “นอก web root” ถ้าโฮสต์อนุญาต (มี comment ใน `.env.example`)
- [ ] ไม่ควรอัปโหลด/เก็บไฟล์ `.env` ในที่สาธารณะหรือแชร์ให้คนอื่น (มี DB/Mail secrets)
- [ ] ทำ Backup ฐานข้อมูลสม่ำเสมอ

---

## K) Quick verification (เช็คว่าติดตั้งสำเร็จจริง)

- [ ] เปิด `APP_URL` แล้วเห็นหน้า Login
- [ ] สมัครสมาชิก 1 บัญชีได้ (ไม่ error)
- [ ] Login ได้ และเข้า `dashboard.php` ได้
- [ ] ไป `shops.php` แล้วสร้างร้านใหม่ได้
- [ ] ไป `add-record.php` แล้วเพิ่มข้อมูลรายวัน 1 รายการได้
- [ ] ไป `history.php` แล้วเห็นรายการที่เพิ่ม
- [ ] ถ้าทำให้เกิด error ตั้งใจ 1 ครั้ง (เช่น ตั้ง DB_PASS ผิดแล้วรีเฟรช) → ตรวจว่า log ถูกเขียนไปที่ `LOG_FILE`
