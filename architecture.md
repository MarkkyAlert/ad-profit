# ARCHITECTURE.md — โครงสร้างระบบ (Ad‑Profit)

เอกสารนี้อธิบาย “สถาปัตยกรรมและการแบ่งชั้นของโค้ด” ในโปรเจกต์นี้แบบอ่านง่าย เหมาะกับมือใหม่ที่อยากเข้าใจว่า:
- โค้ดถูกแบ่งชั้นยังไง
- แต่ละไฟล์/แต่ละโฟลเดอร์มีหน้าที่อะไร
- ทำไมถึงออกแบบแบบนี้

> 📌 ถ้าคุณต้องการ “ลำดับการทำงานของแต่ละฟีเจอร์” แนะนำอ่าน `FLOW.md` ควบคู่กัน

---

## 1) 🏗️ ภาพรวมสถาปัตยกรรมระบบ

### สถาปัตยกรรมที่ใช้คืออะไร
โปรเจกต์นี้ใช้แนวคิดแบบ **Layered Architecture (แบ่งชั้น)** และมีหน้าตาคล้าย **MVC‑style** แต่ไม่ได้ใช้ framework

ภาพรวมการไหลของโค้ดจะประมาณนี้:

```
Browser
  ↓
หน้าเว็บ (*.php) หรือ API (/api/*.php)
  ↓
Service (app/Services/*)
  ↓
Repository (app/Repositories/*)
  ↓
Database (MySQL/MariaDB)
```

> จุดสำคัญ: โปรเจกต์นี้เป็น PHP แบบ “เปิดไฟล์ .php ตามหน้า” (page-based) จึงไม่มี router/middleware แบบ framework

### ทำไมถึงเลือกแนวนี้
เหมาะกับการทำเทมเพลต/งานขนาดเล็ก เพราะ:
- ✅ อ่านตามได้ง่าย (เปิดไฟล์แล้วเห็นการทำงาน)
- ✅ แยกส่วนที่ “เปลี่ยนบ่อย” ออกจากกัน (หน้าเว็บ / กฎธุรกิจ / SQL)
- ✅ มือใหม่ค่อย ๆ เรียนรู้ได้โดยไม่ต้องรู้ framework ก่อน
- ✅ ลดโค้ดซ้ำด้วยการรวม logic ไว้ที่ Service/Repository

### เหมาะกับระบบประเภทไหน
- ระบบขนาดเล็ก–กลาง
- เดโม/สอน/ส่งงาน
- ระบบภายในทีมเล็ก
- เทมเพลตที่ต้องการโครงสร้าง “เป็นระเบียบ” แต่ยังไม่ซับซ้อนเกินไป

---

## 2) 🧱 แนวคิดหลักที่ใช้ในการออกแบบ

### ✅ Separation of Concerns (แยกหน้าที่)
แนวคิดคือ “ไฟล์หนึ่ง/ชั้นหนึ่ง ควรรับผิดชอบเรื่องเดียวหลัก ๆ”

ตัวอย่างในโปรเจกต์นี้:
- หน้าเว็บ/ไฟล์ API: รับ request → ส่ง response
- Service: ตรวจข้อมูล + กฎธุรกิจ + สรุปผล
- Repository: คุย DB ด้วย SQL

ผลลัพธ์:
- แก้ไขง่ายขึ้น
- โค้ดอ่านง่ายขึ้น
- ลดโอกาสแก้จุดหนึ่งแล้วกระทบอีกจุดแบบไม่ตั้งใจ

### ✅ Single Source of Truth (มีแหล่งจริงของกฎอยู่ที่เดียว)
ตัวอย่าง “แหล่งจริง” ในโปรเจกต์นี้:
- กฎ validation/การคำนวณ: อยู่ใน Service
- SQL/query: อยู่ใน Repository
- config/environment: อยู่ใน `.env` + `includes/config.php`

ข้อดี:
- ไม่ต้องไล่แก้หลายไฟล์เวลาเปลี่ยนกฎ
- ลด bug จากการที่หน้า A เช็คแบบหนึ่ง หน้า B เช็คอีกแบบ

### ✅ Boundary / Responsibility (เส้นแบ่งความรับผิดชอบ)
โปรเจกต์นี้พยายามวาง “เส้นแบ่ง” ชัด ๆ ว่าใครทำอะไร เช่น:
- Controller ไม่ควรเขียน SQL เอง
- Repository ไม่ควรรู้เรื่อง session/redirect
- Service ไม่ควร `echo` HTML หรือ `header()`

### ✅ Thin Controller / Fat Service (Controller บาง, Service หนา)
- **Controller (หน้าเว็บ/ไฟล์ API)**: ทำงานเบา ๆ เช่น อ่าน `$_GET/$_POST`, เรียก service, แล้วตอบกลับ
- **Service**: เป็นจุดรวม logic ที่สำคัญ เช่น ตรวจสิทธิ์, validation, transaction, สรุปผล

ตัวอย่างที่เห็นชัด:
- `api/*.php` จะเรียก service แล้วใช้ helper ตอบกลับ
- `RecordService` มี logic เรื่องการล็อกแถว (lock) และกันข้อมูลชนกัน

### ✅ Repository Pattern
Repository คือ “คลาสที่รวม SQL” เพื่อให้:
- หน้าเว็บไม่ต้องเห็น SQL เต็ม ๆ
- ปรับ query ได้โดยไม่กระทบ flow ทั้งระบบ
- ทำให้ service ทำงานกับ “ข้อมูล” แทนที่จะทำงานกับ SQL

---

## 3) 🗂️ โครงสร้างโฟลเดอร์ (ภาพรวม)

> โฟลเดอร์หลัก ๆ ที่ควรรู้

- `/` (root)
  - หน้าเว็บหลัก เช่น `dashboard.php`, `shops.php`, `add-record.php`, `history.php`, `profile.php`, `overview.php`, `annual.php`
  - `index.php` เป็นจุดเริ่มต้น (redirect ไป login/dashboard)

- `/api/`
  - ไฟล์ที่ทำหน้าที่เป็น “API endpoint” สำหรับ action ต่าง ๆ
  - ตัวอย่าง: `api/auth.php`, `api/records.php`, `api/shops.php`, `api/goals.php`, `api/export.php`
  - หลาย endpoint รองรับทั้ง:
    - form submit (ตอบเป็น redirect + flash message)
    - XHR/Fetch (ตอบเป็น JSON)

- `/app/Services/`
  - กฎธุรกิจ/การสรุปผล/validation
  - ตัวอย่าง: `AuthService.php`, `RecordService.php`, `DashboardService.php`, `ShopService.php`

- `/app/Repositories/`
  - คุยฐานข้อมูลด้วย PDO + prepared statements
  - ตัวอย่าง: `UserRepository.php`, `RecordRepository.php`, `ShopRepository.php`

- `/includes/`
  - โค้ดแกนกลางที่ทุกหน้าใช้ร่วมกัน
  - ตัวอย่าง:
    - `bootstrap.php` (เริ่มระบบ, session, log, ต่อ DB, schema guard)
    - `config.php` (อ่าน `.env` แล้วกำหนดค่าคงที่)
    - `database.php` (สร้าง PDO)
    - `functions.php` (helper เช่น CSRF/redirect/JSON response)
    - `auth.php` (requireAuth/requireGuest + session timeout)
    - `header.php`, `footer.php` (layout)

- `/database/`
  - `schema.sql` โครงสร้าง DB
  - `sample_data.sql` ข้อมูลตัวอย่าง

- `/cron/`
  - งานอัตโนมัติแบบ CLI (เช่น cleanup)

- `/vendor/`
  - dependency จาก Composer (เช่น PHPMailer)

### ใครควรเรียกใคร (Dependency ที่แนะนำ)
- หน้าเว็บ/ไฟล์ API ✅ เรียก Service
- Service ✅ เรียก Repository
- Repository ✅ เรียก Database (PDO)
- ทุกชั้น ✅ ใช้ helper ใน `includes/functions.php` ได้ “เท่าที่จำเป็น”

### ใคร “ไม่ควร” เรียกใคร
- Repository ❌ ไม่ควร `redirect()` / `jsonResponse()` / ใช้ `$_SESSION`
- Service ❌ ไม่ควร `echo` HTML หรือยุ่งกับ `header()` โดยตรง
- หน้าเว็บ/ไฟล์ API ❌ ไม่ควรเขียน SQL ตรง ๆ (ควรผ่าน Repository)

---

## 4) 🧠 หน้าที่ของแต่ละ Layer

### 4.1 Controller (หน้าเว็บ + API)
**อยู่ที่:** `/` และ `/api/`

**ทำหน้าที่อะไร**
- รับ input จากผู้ใช้ (`GET/POST`)
- เรียก service ที่เหมาะสม
- ตอบกลับผลลัพธ์เป็น:
  - HTML page (หน้าเว็บ)
  - หรือ JSON/redirect (ไฟล์ใน `/api/`)

**ควรมี logic ระดับไหน**
- logic เบา ๆ เช่น parse ค่า, เลือก action, ส่งพารามิเตอร์เข้า service

**ควรอยู่ใน Controller**
- การตรวจว่าเป็น POST/GET
- การตรวจ CSRF (เรียก helper)
- การเลือก redirect path

**ไม่ควรอยู่ใน Controller**
- SQL
- การคำนวณ/สรุปผลที่ซับซ้อน
- transaction/lock (ควรอยู่ใน service)

---

### 4.2 Service
**อยู่ที่:** `app/Services/*`

**ทำหน้าที่อะไร**
- เป็น “กฎธุรกิจ” ของระบบ
- ตรวจความถูกต้องของข้อมูล (validation)
- ตรวจสิทธิ์/ความเป็นเจ้าของข้อมูล (authorization เชิงธุรกิจ)
- รวมหลาย repository เพื่อทำงานให้จบ 1 use case
- จุดที่ต้อง transaction/lock มักอยู่ที่ชั้นนี้

**ควรมี logic ระดับไหน**
- logic หนา (fat) ได้ แต่ควรจัดเป็นฟังก์ชันย่อย/แยกความรับผิดชอบให้ชัด

**ควรอยู่ใน Service**
- เช่น:
  - upsert/update/delete ข้อมูลแบบมีเงื่อนไข
  - สรุปตัวเลข/จัดรูปข้อมูลให้หน้า dashboard
  - ตรวจว่า user เข้าถึง shop นี้ได้ไหม

**ไม่ควรอยู่ใน Service**
- การอ่าน `$_POST` โดยตรง (ให้ controller ส่งค่าเข้ามา)
- การ `echo` HTML
- การสร้าง SQL string เอง (ให้ repository ทำ)

---

### 4.3 Repository
**อยู่ที่:** `app/Repositories/*`

**ทำหน้าที่อะไร**
- รับผิดชอบการอ่าน/เขียน DB ผ่าน PDO
- เก็บ SQL ไว้ให้เป็นที่เป็นทาง
- คืนค่าเป็น array/row ให้ service ใช้ต่อ

**ควรมี logic ระดับไหน**
- logic เกี่ยวกับ query เท่านั้น (เงื่อนไข query, join, for update)

**ควรอยู่ใน Repository**
- prepared statements
- query แบบ `SELECT ... FOR UPDATE` (ใช้คู่กับ transaction ใน service)

**ไม่ควรอยู่ใน Repository**
- กฎธุรกิจ เช่น “คำนวณกำไร/ROAS”
- เช็ค session/เช็คสิทธิ์เชิง flow
- การตอบกลับผู้ใช้ (redirect/json)

---

### 4.4 Database
**อยู่ที่:** `database/schema.sql`

**ทำหน้าที่อะไร**
- เป็นโครงสร้างข้อมูลจริง (ตาราง/คอลัมน์/ดัชนี)
- ใช้ constraint บางส่วนช่วยกันข้อมูลผิด (เช่น unique key)

แนวคิดที่ใช้ใน schema ที่เห็นในโปรเจกต์นี้:
- ความสัมพันธ์ผ่าน Foreign Key
- Unique index สำหรับกันข้อมูลซ้ำ (เช่น รายการรายวัน 1 ร้าน/1 วัน)

---

### 4.5 Utility / Helper
**อยู่ที่:** `includes/*`

สิ่งสำคัญที่รวมอยู่ในชั้นนี้:
- การอ่าน `.env` และกำหนดค่า config (`includes/config.php`)
- การเริ่มระบบ/ตั้งค่า session/log/ต่อ DB (`includes/bootstrap.php`)
- helper สำหรับงานซ้ำ ๆ เช่น:
  - CSRF (`csrf_token`, `verify_csrf`)
  - response แบบ JSON/redirect (`api_respond`, `jsonResponse`)
  - redirect (`redirect`, `app_url`)
  - ดึง IP ลูกค้าแบบปลอดภัยเมื่ออยู่หลัง proxy (`client_ip`)

ข้อแนะนำ:
- helper ควรเป็น “กลาง ๆ” ใช้ซ้ำได้
- ถ้าเริ่มมี logic ธุรกิจ (เช่นกฎรายได้/กำไร) ให้ย้ายไป Service

---

## 5) 🔐 การออกแบบด้านความปลอดภัย (เชิงโครงสร้าง)

### 5.1 ป้องกัน SQL Injection อยู่ตรงไหน
- Repository ใช้ **PDO + prepared statements** เป็นหลัก
- หน้าเว็บ/Service ไม่ควรต่อ string SQL เอง

### 5.2 Password อยู่ชั้นไหน
- อยู่ที่ Service + Repository
  - Service จัดการ `password_hash()` / `password_verify()`
  - Repository เก็บ/อ่าน `password_hash` จากตาราง `users`
- ไม่เก็บรหัสผ่านแบบข้อความธรรมดาใน DB

### 5.3 Authorization ควรเช็คที่ layer ไหน
- ระดับ “ต้อง login ก่อน” → เช็คที่ `includes/auth.php` ด้วย `requireAuth()`
- ระดับ “ข้อมูลนี้เป็นของ user/ร้านนี้จริงไหม” → เช็คที่ Service
  - เช่น service ตรวจว่า user มีสิทธิ์เข้าถึง shop ก่อนทำรายการ

### 5.4 CSRF ป้องกันตรงไหน
- helper อยู่ที่ `includes/functions.php`
- API/controller เรียก `ensure_valid_csrf_or_respond(...)` ก่อนทำ action ที่แก้ข้อมูล

### 5.5 Session / Cookie ถูกวางไว้ยังไง
- `includes/bootstrap.php` เป็นที่ตั้งค่า session:
  - `HttpOnly`, `SameSite=Lax`, strict mode
  - production บังคับ cookie แบบ `Secure` → ต้องใช้ HTTPS
- ตอน login/logout มีการ `session_regenerate_id(true)` ลดความเสี่ยง session fixation

### 5.6 Transaction / Lock ควรอยู่ตรงไหน
- **อยู่ที่ Service** (เพราะเป็นจุดที่รู้ “งานหนึ่งงานต้องทำอะไรบ้างให้ครบ”)
- Repository มีหน้าที่ “เตรียม query” เช่น `... FOR UPDATE` แต่ไม่ควรเป็นคนเริ่ม/จบ transaction เอง

ตัวอย่างภาพรวมที่พบในโปรเจกต์นี้:
- สมัครสมาชิก: transaction เพื่อสร้าง user + สร้างร้านเริ่มต้น
- รีเซ็ตรหัสผ่าน: lock token แล้วค่อยอัปเดตรหัสผ่าน
- แก้ไขรายการรายวัน: lock แถวเพื่อกันข้อมูลชนกัน

### 5.7 Logging และ Error Handling
- `includes/bootstrap.php` ตั้งค่าให้ error ไปลงไฟล์ตาม `LOG_FILE`
- มี global exception handler:
  - ถ้าเป็น `/api/` จะตอบ JSON error
  - ถ้าเป็นหน้าเว็บ จะตอบหน้า error แบบ HTML

---

## 6) ⚠️ ขอบเขตของสถาปัตยกรรมนี้

### เหมาะกับงานแบบไหน
- ระบบเล็กที่อยากได้โครงสร้างชัด
- งานที่อยากให้มือใหม่อ่านตามได้
- ระบบที่ไม่ต้องมีหลายบริการ/หลายเซิร์ฟเวอร์

### ไม่เหมาะกับงานแบบไหน
- งานที่ต้อง scale สูงมาก (ผู้ใช้พร้อมกันจำนวนมาก)
- งานที่ต้องมี role/permission ซับซ้อนหลายระดับ
- งานที่ต้องมี audit log หรือ workflow ยาว ๆ หลายขั้น

### ถ้าจะเอาไปใช้ production ควรคิดเพิ่มเรื่องอะไร
(ไม่ใช่การเปลี่ยนโค้ด แต่เป็น “การดูแลระบบ”) เช่น:
- HTTPS / การป้องกันไฟล์ `.env`
- การ backup DB และแผนกู้คืน
- การตั้ง cron ที่จำเป็น
- การจัดการ log (ไม่ให้โตไม่จำกัด)
- แผนการอัปเกรด schema (ระวัง `schema.sql` ที่เป็น drop+create)

---

## 7) 🧭 แนวทางการต่อยอด

### 7.1 ถ้าจะเพิ่ม feature ควรเพิ่มที่ layer ไหน
แนวทางที่แนะนำ:
1) เพิ่มหน้า/ปุ่ม (หน้าเว็บที่ `/`)
2) ถ้าเป็น action แบบ POST ให้สร้าง endpoint ใน `/api/`
3) เพิ่ม/ขยาย service ใน `app/Services/*`
4) เพิ่ม/ขยาย repository ใน `app/Repositories/*`
5) ถ้าต้องเพิ่มตาราง/คอลัมน์ ให้แก้ `database/schema.sql`

### 7.2 ถ้าจะเปลี่ยน DB หรือปรับ query หนัก ๆ ควรแก้ตรงไหน
- เริ่มที่ Repository ก่อน
- ถ้าเปลี่ยน schema → ต้องดู `includes/bootstrap.php` (Schema Guard) ด้วยว่าเช็คอะไรไว้

### 7.3 ถ้าจะทำ API / Frontend แยก ควรเริ่มตรงไหน
- ใช้ `/api/*` เป็น “ขอบเขต” (boundary) ของ backend
- endpoint หลายตัวถูกออกแบบให้ตอบได้ทั้ง JSON และ redirect อยู่แล้ว (ผ่าน helper)
- ค่อย ๆ เพิ่ม endpoint ใหม่โดยยึดรูปแบบเดิม (POST + CSRF + service + repository)

### 7.4 ถ้าจะเพิ่มงานเบื้องหลัง
- โปรเจกต์นี้มีโฟลเดอร์ `/cron/` อยู่แล้ว
- แนะนำให้วางงานที่ต้องรันตามเวลาไว้ที่นี่ และทำให้รันผ่าน CLI เท่านั้นเหมือนสคริปต์เดิม

---

## 8) 📌 สรุปสำหรับผู้ซื้อ

สิ่งที่เอกสาร/โค้ดชุดนี้ช่วยให้คุณ “ฝึกคิดแบบสถาปนิกระบบ” ได้:
- การแบ่งชั้น (Layer) และการแยกหน้าที่ (SoC)
- การออกแบบให้แก้ง่าย/ต่อยอดง่ายด้วย Service + Repository
- ความปลอดภัยพื้นฐานที่ควรมีในระบบจริง (CSRF, session hardening, prepared statements)
- การวางจุด transaction/lock ให้ถูกชั้น

เหมาะกับใครที่สุด:
- มือใหม่ที่อยากมีโปรเจกต์ตัวอย่างโครงสร้างดี ๆ
- นักเรียน/คนเริ่มทำโปรเจกต์ที่ต้องการระบบ “ใช้งานได้จริง” และอ่านตามได้
- คนที่อยากได้เทมเพลตไปต่อยอดเป็นระบบเล็ก ๆ ของตัวเอง
