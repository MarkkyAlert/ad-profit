# รายงานการตรวจสอบความปลอดภัย (Security Audit Report)
## Backend Entrypoints & API - "Ad-Profit" Project

จากการตรวจสอบความปลอดภัย (Security Audit) ในส่วนของ Backend Entrypoints (`api/*.php`), Authentication, Authorization, และ API Layer ของระบบ 
พบว่าระบบมีการออกแบบ Baseline Security ที่ค่อนข้างแข็งแรง (เช่น ใช้ PDO ป้องกัน SQLi, มี CSRF Protection, เข้ารหัสรหัสผ่านด้วย `password_hash`, มี Rate Limiting ในจุดสำคัญ, และมีการตรวจสอบสิทธิ์/IDOR ที่รัดกุม) 

โดยรวม **ไม่พบช่องโหว่ระดับ Critical หรือ High** ที่สามารถ Exploit ได้ง่ายจากภายนอก ดังนั้นระบบนี้ **"ผ่านในระดับขายได้"** สำหรับโปรเจกต์ขนาดเล็กถึงกลาง

อย่างไรก็ตาม พบประเด็นความเสี่ยงระดับ **Medium** และ **Low** ที่ควรปรับปรุงเพื่อเพิ่มความปลอดภัยให้รัดกุมยิ่งขึ้น ดังนี้:

---

### 1. [Low] (Hardening) GET `action` fallback ใน state-changing APIs (แก้แล้ว)
- **Affected Endpoint:** `api/auth.php`, `api/records.php`, `api/shops.php`, `api/goals.php`, `api/profile.php`
- **Attack Scenario:** เป็นจุดเสี่ยงแบบ footgun (defense-in-depth) — ถ้ามีการเพิ่ม action ใหม่ในอนาคตแล้วลืมบังคับ POST+CSRF อาจกลายเป็น state-change ผ่าน GET ได้ง่าย
- **Exploit Steps (สั้น):** (กรณีมี action ใหม่ที่ลืม guard) ส่งลิงก์ `.../api/shops.php?action=<action>` ให้ผู้ใช้ที่ล็อกอินคลิก
- **Risk Level:** Low
- **Business Impact:** ลดโอกาส CSRF/accidental state change จากการหลุด guard ในอนาคต
- **Fix (applied):** ปรับให้รับ `action` จาก POST เท่านั้น
  ```php
  // ก่อน: $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
  // หลัง: $action = (string)($_POST['action'] ?? '');
  ```
- **Mitigation Checklist:**
  - ตรวจให้ชัดว่า endpoint ที่ “เปลี่ยนสถานะ” รับเฉพาะ POST
  - ทุก action ต้องมี `ensure_post_request_or_respond` และ `ensure_valid_csrf_or_respond`
  - แยก endpoint ที่เป็น GET-only (read-only) ให้ชัดเจน

---

### 2. [Low] (Hardening) Content-Type guard สำหรับ POST APIs (แก้แล้ว)
- **Affected Endpoint:** state-changing actions ใน `api/auth.php`, `api/records.php`, `api/shops.php`, `api/goals.php`, `api/profile.php`
- **Attack Scenario:** ผู้โจมตีส่ง POST ด้วย `Content-Type` แปลก/ไม่คาดคิด เพื่อพยายามทำ request smuggling/การ parse เพี้ยน หรือทำให้ behavior ไม่คงที่ระหว่าง environment
- **Exploit Steps (สั้น):** ส่ง POST ด้วย `Content-Type: text/plain` หรือค่าอื่นที่ไม่ใช่ form/multipart
- **Risk Level:** Low
- **Business Impact:** ลดความเสี่ยง request parsing inconsistency และลดพื้นที่โจมตี (attack surface)
- **Fix (applied):** เพิ่ม `ensure_form_content_type_or_respond()` ใน `includes/functions.php` และเรียกใช้ในทุก action ที่เป็น POST เพื่อบังคับ Content-Type ให้เป็น form/multipart (ตอบ 415 เมื่อไม่ผ่าน)
- **Mitigation Checklist:**
  - อนุญาตเฉพาะ `application/x-www-form-urlencoded` และ `multipart/form-data` (รองรับค่าที่มี `; charset=...`/boundary)
  - ถ้าอนาคตต้องรองรับ JSON จริง ให้เพิ่มการอ่าน `php://input` และ validate schema แยกเป็นเคส

---

### 3. [Low] Session Fixation Prevention Check (ตรวจแล้ว: มีแล้ว)
- **Affected Endpoint:**
  - `app/Services/AuthService.php::establishSession()`
  - `app/Services/AuthService.php::logout()`
  - `includes/auth.php::clearAuthSession()`
- **Attack Scenario:** ถ้า Login สำเร็จแต่ไม่ regenerate session id อาจเสี่ยง session fixation ภายใต้เงื่อนไขเฉพาะ
- **Exploit Steps (สั้น):** ยึด session id ก่อน login แล้วให้เหยื่อ login ด้วย session เดิม
- **Risk Level:** Low
- **Business Impact:** อาจนำไปสู่การยึดบัญชี (account takeover) ถ้ามีช่องทางฝัง session id
- **Fix status:** ✅ ระบบมี `session_regenerate_id(true)` แล้วทั้งตอน login และตอน logout/clear session (ไม่ต้องแก้เพิ่ม)
- **Mitigation Checklist:**
  - คงไว้ `session_regenerate_id(true)` ตอน Login/Logout/Expired (ทำแล้ว)
  - ใช้ `use_strict_mode` สำหรับ session (ทำแล้วใน `includes/bootstrap.php`)
  - ตรวจสอบให้แน่ใจว่า production ใช้ HTTPS เพื่อให้ `cookie_secure` ทำงานเต็มที่

---

### 4. [Low] Information Disclosure via Error Messages
- **Affected Endpoint:** `includes/bootstrap.php` (Global Exception Handler)
- **Attack Scenario:** ถึงแม้โหมด Production จะซ่อน Exception ละเอียด แต่หาก `APP_ENV = 'development'` เผลอหลุดไปบน Production, ตัว Error Log ของ PHP อาจเปิดเผย Path ใน Server หรือโครงสร้างระบบ
- **Exploit Steps:**
  1. Attacker พยายามส่ง Input ผิดปกติให้เกิด Error (เช่น ใส่ String ลงใน Field ที่บังคับ Int แล้วไม่ดัก Try-Catch ไว้ก่อนถึง Global)
  2. หากเป็น Development Mode จะแสดงข้อมูลกลับ
- **Risk Level:** Low
- **Business Impact:** ผู้โจมตีรู้โครงสร้างโฟลเดอร์ นำไปสู่การวางแผนโจมตี Path Traversal หรือ LFI ได้แม่นยำขึ้น
- **Fix with Code:** เป็น Accepted Risk ตามปกติของการตั้งค่า Environment แต่ควรตรวจสอบให้มั่นใจว่าไฟล์ `.env` ถูกตั้งค่าอย่างถูกต้องตอน Deploy
- **Mitigation Checklist:**
  - บังคับการตั้งค่า `display_errors = 0` ในไฟล์ `php.ini` หรือ `bootstrap.php` ทันทีเมื่อขึ้น Production โดยไม่สนใจ `APP_ENV` ก็ได้หากต้องการความปลอดภัยสูงสุด

---

## สรุป (Conclusion)
ระบบได้รับการออกแบบมาอย่างปลอดภัยเพียงพอสำหรับ Use case ของแอปพลิเคชันขนาดเล็กและ Template 
ไม่มีช่องโหว่ร้ายแรงประเภท SQL Injection, IDOR (Cross-tenant Data Leak), หรือ Broken Access Control ที่พบได้ทั่วไปในแอปพลิเคชันระดับนี้ 
ดังนั้นจึง **"ผ่านในระดับขายได้"** โดยสามารถนำข้อเสนอแนะด้านบนไปปรับปรุงเพิ่มเติมเล็กน้อยเพื่อเสริมความแข็งแกร่ง (Security Hardening) ได้
