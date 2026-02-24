# QA_CHECKLIST — Ad‑Profit (ก่อนปล่อยขาย/ขึ้น production)

> สำหรับระบบ **Ad‑Profit** (PHP page-based + `api/`) ขนาดเล็ก–กลาง: ใช้เรียน / demo / ร้านเล็ก / ขายเป็น template

**หมายเหตุ (ปรับให้ตรงกับระบบนี้):**
- ระบบนี้มีผู้ใช้ **role เดียว** (ผู้ใช้ทั่วไป) ✅
- **ไม่พบในโค้ดของ template นี้**: admin panel, staff role, borrow/return, reservation, payment/checkout ✅

---

## 0) เตรียมสภาพแวดล้อมก่อนทดสอบ (Pre‑flight)

- [x] Import DB: `database/schema.sql` และ (ถ้าจะใช้) `database/sample_data.sql`
- [x] ตั้งค่า `.env` (แนะนำสำหรับ local/demo: `APP_ENV=development`)
- [x] เปิดหน้าเว็บได้ที่ `http://localhost/ad-profit/` และไม่ขึ้น 500/503
- [x] ล้าง browser cache / เปิด Incognito อีก 1 หน้าต่าง (ใช้ทดสอบ concurrent + session_version)
- [x] เปิดไฟล์ log และเฝ้าดู error ระหว่างเทส: `logs/php-error.log`
- [x] เตรียมบัญชีทดสอบอย่างน้อย 2 บัญชี (User A, User B)

---

## 1) Flow: Register (สมัครสมาชิก)

### 1.1 Happy Path
- [x] เปิด `/login.php` → แท็บ “สมัครสมาชิก”
- [x] กรอกอีเมล + รหัสผ่าน + ยืนยันรหัสผ่านถูกต้อง → submit
- [x] ระบบต้องพาไป `/dashboard.php` (ถือว่า login สำเร็จทันที)
- [x] เปิด `/shops.php` ต้องเห็นร้านเริ่มต้น “ร้านค้าของฉัน” อย่างน้อย 1 ร้าน

### 1.2 Failure Case
- [x] กรอก email ไม่ถูก format (เช่น `abc`) → ต้องแจ้ง error และไม่สมัครได้
- [x] รหัสผ่านสั้นกว่า policy → ต้องแจ้ง error
- [x] password กับ confirm ไม่ตรงกัน → ต้องแจ้ง error
- [x] ใช้อีเมลเดิมสมัครซ้ำ → ต้องสมัครไม่สำเร็จ (ไม่สร้าง user ซ้ำ)

### 1.3 Edge / Concurrency / Double submit
- [x] กดปุ่มสมัคร 2 ครั้งเร็ว ๆ (double click) → ต้องไม่เกิด 500 และต้องไม่สร้าง user ซ้ำ
- [x] เปิด 2 tab แล้วสมัครอีเมลเดียวกัน “เกือบพร้อมกัน” → ต้องมีได้สูงสุด 1 บัญชีเท่านั้น

### 1.4 Security / Integrity
- [x] ลบ/แก้ค่า `csrf_token` ก่อน submit → ต้องโดนปฏิเสธ (ไม่สมัครได้)
- [x] ลองใส่ payload SQLi ใน email เช่น `' OR 1=1 --` → ต้องไม่ crash และต้องไม่สมัครได้

---

## 2) Flow: Login (เข้าสู่ระบบ)

### 2.1 Happy Path
- [x] เปิด `/login.php` → กรอกบัญชีที่สมัครไว้ → submit
- [x] ต้อง redirect ไป `/dashboard.php`
- [x] refresh `/dashboard.php` หลายครั้ง → ยังอยู่ในระบบ

### 2.2 Failure Case
- [x] รหัสผ่านผิด → ต้องแจ้ง “อีเมลหรือรหัสผ่านไม่ถูกต้อง” และไม่ login
- [x] ไม่กรอก email/password → ต้องแจ้ง error
- [x] ลองผิดซ้ำหลายครั้งติดกัน → ต้องเจอข้อความ rate limit (รอ 1 นาทีแล้วลองใหม่)

### 2.3 Edge / Concurrency / Double submit
- [x] double click ปุ่ม login → ต้องไม่ 500
- [x] เปิด 2 tab แล้วกด login เกือบพร้อมกัน → ต้อง login ได้ (ไม่ fail จากการสร้างร้านเริ่มต้น)

### 2.4 Security
- [x] แก้/ลบ `csrf_token` ก่อน submit → ต้องโดนปฏิเสธ และไม่ login

---

## 3) Flow: Logout (ออกจากระบบ)

- [x] กด logout จากเมนู (ต้องเป็น POST) → ต้องกลับไปหน้า `/login.php`
- [x] หลัง logout เปิด `/dashboard.php` ตรง ๆ → ต้องถูก redirect ไป `/login.php`
- [x] กด logout ซ้ำ (back แล้ว submit ซ้ำ) → ต้องไม่ 500

**Security**
- [x] ลองส่ง logout แบบไม่มี/ผิด CSRF → ต้องไม่ logout

---

## 4) Flow: Forgot Password (ขอลิงก์รีเซ็ตรหัสผ่าน)

### 4.1 Happy Path
- [x] เปิด `/forgot-password.php` → กรอกอีเมลที่มีจริง → submit
- [x] หน้าต้องขึ้นข้อความแนว “หากอีเมลนี้มีอยู่ในระบบ…” (กัน user enumeration)

### 4.2 Failure Case
- [x] กรอกอีเมล format ผิด → ต้องแจ้ง error

### 4.3 Edge / Double submit
- [x] กด submit ซ้ำภายใน 1 นาที → ต้องโดน rate limit (ไม่สร้าง/ส่งซ้ำแบบไม่จำกัด)

### 4.4 Security
- [x] แก้/ลบ CSRF → ต้องโดนปฏิเสธ

---

## 5) Flow: Reset Password (ตั้งรหัสผ่านใหม่)

### 5.1 Happy Path
- [x] ใช้ลิงก์ reset ที่ได้รับ (หรือ Dev mode แสดงบนหน้า) → เปิด `/reset-password.php?token=...`
- [x] ตั้งรหัสผ่านใหม่ถูกต้อง → submit
- [x] ระบบต้องพาไป `/login.php`
- [x] login ด้วย “รหัสใหม่” ต้องสำเร็จ
- [x] login ด้วย “รหัสเก่า” ต้องล้มเหลว

### 5.2 Failure Case
- [x] รหัสผ่านใหม่สั้นเกิน policy → ต้องแจ้ง error
- [x] password กับ confirm ไม่ตรงกัน → ต้องแจ้ง error
- [x] token ไม่ถูกต้อง/หมดอายุ → ต้องแจ้ง error และรีเซ็ตไม่ได้

### 5.3 Edge / Atomicity
- [x] ใช้ token เดิม reset ซ้ำอีกครั้ง → ต้องล้มเหลว (token one-time / ถูกลบแล้ว)

### 5.4 Security
- [x] แก้/ลบ CSRF → ต้องโดนปฏิเสธ

---

## 6) Flow: Shop Management (สร้าง/สลับ/เปลี่ยนชื่อ/ลบร้าน)

### 6.1 Happy Path
- [x] เปิด `/shops.php` → สร้างร้านใหม่ 1 ร้าน
- [x] สร้างสำเร็จแล้วระบบต้อง “สลับร้านให้”
- [x] สลับร้านจาก dropdown (ที่ header) แล้วข้อมูล dashboard/history ต้องเปลี่ยนตามร้าน
- [x] เปลี่ยนชื่อร้านแล้วชื่อใน header ต้องอัปเดต

### 6.2 Failure / Validation
- [x] สร้างร้านโดยเว้นชื่อว่าง → ต้องแจ้ง error
- [x] ตั้งชื่อร้านยาวเกิน limit → ต้องแจ้ง error

### 6.3 Edge / Idempotency / Concurrency
- [x] สร้างร้านชื่อเดิมซ้ำ → ต้องไม่สร้างซ้ำ (ควรสลับไปใช้ร้านเดิม)
- [x] double click สร้างร้านชื่อเดิม → ต้องจบด้วยร้านเดียว ไม่ duplicate
- [x] เปิด 2 tab แล้วสลับร้านไปมา → session ต้องสะท้อนร้านล่าสุด

### 6.4 Delete Shop (Data Integrity)
- [x] เมื่อมี “2 ร้านขึ้นไป” ให้ลบร้านที่ไม่ใช่ร้านสุดท้าย → ต้องลบได้
- [x] พยายามลบร้านสุดท้ายที่เหลืออยู่ → ต้องถูกปฏิเสธ
- [x] หลังลบร้าน ระบบต้องเลือก “ร้านถัดไป” ให้ใช้งานต่อ (session current_shop ต้องถูก)

### 6.5 Authorization (IDOR)
- [x] Login เป็น User B แล้วลองส่ง `shop_id` ของ User A ไป `api/shops.php?action=switch` → ต้องถูกปฏิเสธ

---

## 7) Flow: Daily Records (บันทึกรายวัน) + History (แก้ไข/ลบ)

### 7.1 Upsert Record (Idempotency)
- [x] เปิด `/add-record.php` → บันทึกวันที่วันนี้ (revenue/ad_cost) → สำเร็จ
- [x] บันทึก “วันเดิม” อีกรอบด้วยตัวเลขใหม่ → ต้องเป็นการอัปเดตทับ (ไม่เกิด record ซ้ำ)

### 7.2 Failure / Validation
- [x] ใส่ revenue หรือ ad_cost ติดลบ → ต้องถูกปฏิเสธ
- [x] ส่ง date ไม่ถูกต้อง หรือไม่มีอยู่จริง (เช่น 31 ก.พ.) → ต้องถูกปฏิเสธ
- [x] note ยาวเกิน 255 → ต้องถูกปฏิเสธ
- [x] ลองกรอก "วันที่ในอนาคต" → ถ้าระบบอนุญาต กราฟและยอดสรุปต้องแสดงผลและคำนวณถูกต้อง (ไม่พัง)

### 7.3 History + Update
- [x] เปิด `/history.php?month=YYYY-MM` แล้วต้องเห็นรายการ + ยอดรวม
- [x] กด “แก้ไข” แล้วบันทึกค่าใหม่ → ต้องอัปเดตสำเร็จ
- [x] เปลี่ยนวันที่ของรายการ A ไปชนวันที่ที่มีรายการอยู่แล้ว → ต้อง error และ **ห้ามทับข้อมูลเดิม**

### 7.4 Delete
- [x] ลบรายการ 1 รายการ → ต้องลบได้
- [x] ลบรายการเดิมซ้ำอีกรอบ → ต้องไม่ 500 และต้องตอบว่าไม่พบรายการ

### 7.5 Concurrency / Double submit
- [x] double click ปุ่ม “บันทึกข้อมูล” ที่ add-record → ต้องไม่เกิดข้อมูลซ้ำผิดปกติ
- [x] เปิด 2 tab แล้วแก้ record เดียวกันคนละค่า → ผลลัพธ์ต้องเป็น “ค่าล่าสุดที่บันทึก” (ไม่ควรมี record หาย/แถวซ้อน)

### 7.6 Authorization (IDOR)
- [x] Login เป็น User B แล้วลอง update/delete ด้วย `record_id` ของ User A → ต้องไม่สำเร็จ

---

## 8) Flow: Goals (ตั้งเป้ารายเดือน)

### 8.1 Happy Path
- [x] ที่ `/dashboard.php` ตั้งเป้า (รายได้ หรือ กำไร อย่างน้อย 1 ค่า) → บันทึกได้
- [x] เปลี่ยนค่าเป้าเดือนเดิมอีกครั้ง → ต้องเป็นการ update ทับ (ไม่เกิดเป้าซ้ำ)
- [x] ลบเป้าเดือนนั้น → ต้องลบได้

### 8.2 Failure / Validation
- [x] ใส่เดือนผิด format → ต้องถูกปฏิเสธ
- [x] ใส่เป้าติดลบ → ต้องถูกปฏิเสธ
- [x] ไม่กรอกทั้งรายได้และกำไร → ต้องถูกปฏิเสธ

### 8.3 Double submit
- [x] double click บันทึกเป้า → ต้องไม่สร้างข้อมูลซ้ำ

---

## 9) Flow: Dashboard (สรุป/ช่วงเวลา/สถิติ)

### 9.1 Happy Path
- [x] เปิด `/dashboard.php` → เห็น summary + charts (อย่างน้อยไม่ error)
- [x] เลือก range: สัปดาห์นี้ / สัปดาห์ก่อน / เดือนนี้ / เดือนก่อน → ต้องโหลดได้

### 9.2 Failure / Edge
- [x] custom range: start_date > end_date → ต้องแจ้ง error
- [x] custom range: ช่วงยาวมาก (มากกว่า ~1 ปี) → ต้องถูกปฏิเสธ (กันระบบช้า/timeout)

### 9.3 Data Consistency
- [x] ตรวจว่ากำไร = รายได้ - ค่าแอด (อย่างน้อย 2–3 ชุดข้อมูล)
- [x] ถ้า ad_cost = 0 ต้องไม่เกิด divide-by-zero และ ROAS แสดงเป็น “–” หรือ null ตาม UI

### 9.4 Zero Data & UI Edge Cases
- [x] สร้าง/สลับไปร้านค้าใหม่ที่ยังไม่มีข้อมูล → หน้า Dashboard ต้องไม่ขึ้น Error แจ้งเตือนของ PHP (เช่น Undefined variable, Divide by zero) และดีไซน์แสดงพื้นที่ว่างๆ อย่างสวยงาม
- [x] ตั้งเป้าหมายไว้น้อยมาก แต่บันทึกรายได้จริงมหาศาล (ทะลุ 300%+) → แถบ Progress Bar ต้องแสดงผลเต็ม 100%+ ได้โดยไม่ทะลุเลยกรอบของ CSS

---

## 10) Flow: Overview (รวมทุกร้าน)

- [x] มี 1 ร้าน → เปิด `/overview.php` ต้องขึ้นข้อความว่าต้องมี >= 2 ร้าน (และไม่ error)
- [x] สร้างร้านที่ 2 แล้วเปิด `/overview.php` → ต้องเห็นตารางเปรียบเทียบและกราฟ
- [x] เปลี่ยนเดือน (YYYY-MM) → ต้องเปลี่ยนข้อมูลตาม

**Edge**
- [x] ใส่เดือนผิด format ใน query (เช่น `month=2025-99`) → ระบบต้อง fallback (ไม่ 500)

---

## 11) Flow: Annual Summary (สรุปทั้งปี)

- [x] เปิด `/annual.php` ปีปัจจุบัน → ต้องเห็น 12 เดือนเสมอ (เดือนไม่มีข้อมูลเป็น 0)
- [x] ใส่ปีแบบ พ.ศ. (เช่น 2569) → ระบบต้องแปลงและแสดงได้
- [x] ใส่ปีนอกช่วง (เช่น 1800 หรือ 9999) → ต้อง fallback ไม่ 500

---

## 12) Flow: Export CSV

### 12.1 Happy Path
- [x] ไป `/history.php` → กด “Export CSV” → ได้ไฟล์ `.csv`
- [x] เปิดไฟล์ใน Excel/Google Sheets → ภาษาไทยไม่เพี้ยน (มี UTF‑8 BOM)

### 12.2 Security / Data Integrity
- [x] ใส่ note ที่ขึ้นต้นด้วย `=`, `+`, `-`, `@` หรือ tab แล้ว export → cell ต้องถูกป้องกันสูตร (ไม่กลายเป็นสูตรใน Excel)

### 12.3 Edge
- [x] ใส่ `month` ผิด format → ต้อง fallback ไม่ 500

---

## 13) Flow: Profile (ชื่อที่แสดง / เปลี่ยนอีเมล / เปลี่ยนรหัสผ่าน)

### 13.1 Update display name
- [ ] เปลี่ยนชื่อที่แสดงเป็นค่าปกติ → สำเร็จ
- [ ] เว้นว่าง / ยาวเกิน limit → ต้องถูกปฏิเสธ

### 13.2 Change email
- [ ] เปลี่ยนอีเมลด้วยรหัสผ่านปัจจุบันถูกต้อง → สำเร็จ และ session แสดงอีเมลใหม่
- [ ] ใส่รหัสผ่านปัจจุบันผิด → ต้องถูกปฏิเสธ
- [ ] เปลี่ยนเป็นอีเมลที่มีคนอื่นใช้แล้ว → ต้องถูกปฏิเสธ

### 13.3 Change password (session_version)
- [ ] เปิดระบบด้วย 2 browser (User A ทั้งคู่)
- [ ] เปลี่ยนรหัสผ่านใน browser ที่ 1 → สำเร็จ
- [ ] กลับ browser ที่ 2 แล้ว refresh หน้าใด ๆ → ต้องโดนบังคับให้ login ใหม่ (session expired/revoked)

---

## 14) Security Checklist (Cross‑cutting)

### 14.1 CSRF
- [ ] ทุก action ที่เป็น POST (auth/shops/records/goals/profile) ต้องปฏิเสธเมื่อ CSRF หาย/ผิด

### 14.2 Method Guard
- [ ] ยิง `GET` ไป action ที่ควรเป็น `POST` (เช่น login/register/upsert/delete) → ต้องไม่ทำงาน/ไม่เปลี่ยนข้อมูล

### 14.2.1 Content-Type Guard
- [ ] ยิง `POST` ด้วย Content-Type อื่น (เช่น `application/json`) ไป state-changing API (auth/shops/records/goals/profile) → ต้องได้ 415 Unsupported Media Type

### 14.3 Authorization / IDOR
- [ ] User B ต้องไม่สามารถแก้/ลบ record ของ User A ได้
- [ ] User B ต้องไม่สามารถ switch ไป shop ของ User A ได้

### 14.4 XSS
- [ ] ใส่ `<script>alert(1)</script>` ในช่องที่เก็บได้ (เช่น note / shop name / display name) แล้วเปิดหน้าที่แสดงค่า → ต้องแสดงเป็น “ข้อความ” ไม่ execute

### 14.5 SQLi / Input Abuse
- [ ] ใส่ payload เช่น `' OR 1=1 --` ใน input หลัก (email, shop name, note) → ต้องไม่ 500 และไม่หลุดข้อมูล

### 14.6 Open Redirect
- [ ] แก้ hidden field `redirect_to` เป็น `https://evil.com` แล้ว submit (shops/goals/profile) → ต้องไม่ redirect ออกนอกโดเมน

### 14.7 Session / Cookie
- [ ] ตั้ง `APP_ENV=production` แล้วเข้าผ่าน HTTP (ไม่ใช่ HTTPS) → ต้องระวังอาการ login ไม่ติด (เป็น expected เพราะ cookie Secure)
- [ ] ปล่อยหน้าเว็บทิ้งไว้จนหมด `SESSION_IDLE_TIMEOUT_SECONDS` (หรือทดลองแก้เวลาฝั่งเซิร์ฟเวอร์) → พอกด Refresh หรือเปลี่ยนหน้า ต้องโดนบังคับ Logout กลับไปหน้า `login.php` และเมื่อพยายามเข้า `dashboard.php` โดยตรงก็ต้องเข้าไม่ได้

### 14.8 Security Headers
- [ ] ตรวจ response headers (ใช้ DevTools > Network) ต้องมี:
  - `X-Frame-Options: DENY`
  - `Content-Security-Policy: frame-ancestors 'none'`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy: geolocation=(), microphone=(), camera=()`

---

## 15) Data Integrity Checklist (Cross‑cutting)

- [ ] 1 ร้าน + 1 วัน ต้องมี record ได้ไม่เกิน 1 แถว (upsert ต้องทับ)
- [ ] ห้ามมีตัวเลขติดลบใน revenue/ad_cost
- [ ] ลบร้าน (ที่ไม่ใช่ร้านสุดท้าย) แล้วข้อมูลของร้านนั้นต้องไม่กลับมา (FK cascade ทำงาน)
- [ ] export/overview/annual ไม่ควรทำให้ข้อมูลใน DB เปลี่ยน (read-only)

---

## 16) UI / UX & Responsiveness (Cross‑cutting)

- [ ] ใช้เบราว์เซอร์โหมดมือถือ (Mobile View) เข้าหน้า `/history.php` และ `/overview.php` → ตารางข้อมูลต้องสามารถเลื่อนซ้ายขวาได้ (Overflow-X) โดยที่ไม่ทำให้เลย์เอาต์หลักของหน้าเว็บหรือ Header/Footer ตกขอบหรือผิดเพี้ยน

---

## N/A (ไม่อยู่ใน template นี้ แต่ควร “ยืนยันว่าไม่มีจริง” ก่อนขาย)

- [ ] ไม่มี `admin/` module และไม่มีเส้นทางจัดการ role ในระบบนี้
- [ ] ไม่มี flow borrow/return/reservation/payment/checkout ในระบบนี้
- [ ] ไม่มีการอัปโหลดไฟล์จริง (นอกจากโฟลเดอร์ `uploads/` ที่เตรียมไว้)
