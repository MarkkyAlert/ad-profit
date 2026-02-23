# WHERE_TO_EDIT.md — คู่มือแก้ไขระบบ (สำหรับมือใหม่)

เอกสารนี้ทำขึ้นเพื่อช่วยคุณ **หาไฟล์ที่ต้องแก้** โดยไม่ต้องไล่โค้ดทั้งโปรเจกต์

> 💡 เหมือนเป็น "แผนที่" บอกว่า "อยากได้ผลแบบไหน ต้องไปแก้ไฟล์ไหน"

---

## 1) 🔰 วิธีใช้ไฟล์นี้ (อ่านก่อน)

### ไฟล์นี้คือแผนที่
- เราจะบอกว่า "ถ้าอยากแก้ X → ไปแก้ไฟล์ Y"
- ไม่ใช่การสอนเขียนโค้ด แต่เป็นการบอกตำแหน่งไฟล์

### แนะนำให้แก้จากบนลงล่าง
1. เริ่มจาก "แก้หน้าตา" ก่อน (ง่ายที่สุด)
2. จากนั้นค่อยลงไปแก้ logic/database (ยากขึ้น)

### ⚠️ สำคัญมาก: Backup ก่อนแก้
- ถ่ายสำรองไฟล์ก่อนแก้ทุกครั้ง
- ถ่ายสำรอง database ก่อนแก้โครงสร้างตาราง
- ถ้าแก้แล้วพัง: คัดลอกไฟล์เดิมกลับมาได้

---

## 2) 🎨 แก้หน้าตา / UI

### 2.1 เปลี่ยนชื่อเว็บ
**ไฟล์ที่ต้องแก้:** `.env`

```
APP_NAME="Ad-Profit"
```
เปลี่ยนเป็นชื่อที่คุณต้องการ เช่น `"ร้านของฉัน"` หรือ `"My Shop"`

### 2.2 เปลี่ยนข้อความในหน้าเว็บ
**ตำแหน่งไฟล์:** หน้าเว็บแต่ละหน้าอยู่ที่ root (/)

ตัวอย่าง:
- `dashboard.php` = หน้าแดชบอร์ด
- `shops.php` = หน้าจัดการร้าน
- `add-record.php` = หน้าบันทึกรายวัน
- `history.php` = หน้าประวัติรายเดือน
- `profile.php` = หน้าโปรไฟล์
- `overview.php` = หน้าภาพรวมทุกร้าน
- `annual.php` = หน้าสรุปประจำปี
- `login.php` = หน้า login/register

**วิธีแก้:**
- เปิดไฟล์ที่ต้องการแก้
- ค้นหาข้อความที่เห็นบนหน้าเว็บ
- แก้ข้อความใน HTML ส่วนนั้น

### 2.3 เปลี่ยนสี / ปุ่ม / Layout
**ไฟล์หลัก:** `includes/header.php`

ระบบนี้ใช้ Tailwind CSS (โหลดจาก CDN) + มี custom CSS อยู่ใน `<style>` tag ของ `includes/header.php`

**ตัวอย่างที่แก้ได้:**
- สีพื้นหลัง: ดูที่ `body { background-color: ... }`
- สีปุ่ม: แก้ class ของ `<button>` ในหน้าต่าง ๆ
- ตำแหน่ง navbar/footer: แก้ `includes/header.php` และ `includes/footer.php`

### 2.4 เปลี่ยนเมนู / เพิ่มลิงก์
**ไฟล์:** `includes/header.php`

เมนูหลักอยู่ใน `<nav>` ตัวอย่าง:
- เพิ่มปุ่มเมนูใหม่: เพิ่ม `<a>` tag ใน navbar
- ซ่อนเมนูบางตัว: comment หรือลบ `<a>` tag ออก

---

## 3) 👤 แก้เรื่องผู้ใช้ (User / Login / Role)

### 3.1 ถ้าอยากเปลี่ยนเงื่อนไขสมัคร

**ตัวอย่าง:** อยากให้รหัสผ่านยาวอย่างน้อย 10 ตัว (แทนที่จะเป็น 8)

**ไฟล์:** `includes/config.php`

```php
define('PASSWORD_MIN_LENGTH', 8);
```
เปลี่ยนเป็น `10` หรือความยาวที่ต้องการ

### 3.2 ถ้าอยากเพิ่ม field (เช่น เบอร์โทร)

**ต้องแก้ 4 จุด:**

1. **Database**
   - ไฟล์: `database/schema.sql`
   - เพิ่มคอลัมน์ในตาราง `users` เช่น `phone VARCHAR(20)`
   - ต้องรัน SQL ใหม่ หรือใช้ `ALTER TABLE` (ระวังข้อมูลพัง)

2. **หน้า Profile**
   - ไฟล์: `profile.php`
   - เพิ่มฟอร์มสำหรับกรอกเบอร์โทร

3. **API รับข้อมูล**
   - ไฟล์: `api/profile.php`
   - เพิ่มโค้ดรับค่า `$_POST['phone']`

4. **Service + Repository**
   - ไฟล์: `app/Services/ProfileService.php` (เพิ่ม validation)
   - ไฟล์: `app/Repositories/UserRepository.php` (เพิ่ม SQL UPDATE)

> ⚠️ **คำเตือน:** การเพิ่ม field ต้องแก้หลายไฟล์ ถ้าไม่มั่นใจแนะนำให้ถาม support

### 3.3 ถ้าอยากเปลี่ยน role (admin/staff/member)

**คำตอบ:** ระบบนี้ **ไม่มี role แยกสิทธิ์** ในเวอร์ชันปัจจุบัน

ถ้าอยากเพิ่ม role ต้องทำเอง:
- เพิ่มคอลัมน์ `role` ในตาราง `users`
- เพิ่มการเช็คสิทธิ์ใน Service แต่ละตัว
- เพิ่มเงื่อนไขในหน้าเว็บ (ซ่อน/แสดงเมนูตาม role)

---

## 4) 📚 แก้ logic หลักของระบบ

### 4.1 ถ้าอยากเปลี่ยนเงื่อนไขการบันทึกรายวัน

**ตัวอย่าง:** อยากให้บันทึกข้อมูลย้อนหลังได้ไม่เกิน 30 วัน

**ไฟล์ที่ต้องแก้:**
- `app/Services/RecordService.php` (method `upsertRecord` หรือ `createRecord`)
- เพิ่ม validation เช็ควันที่

### 4.2 ถ้าอยากเปลี่ยนการคำนวณกำไร

**ไฟล์หลัก:**
- `app/Services/RecordService.php`
- `app/Services/DashboardService.php`
- `app/Repositories/RecordRepository.php`

**ตัวอย่าง:**
- สูตรคำนวณกำไร: อยู่ใน Service หรือ Repository ที่ทำ `SELECT SUM(...)`
- เพิ่มค่า commission หรือค่าธรรมเนียม: แก้ใน Service

### 4.3 ถ้าอยากเปลี่ยม flow การสร้าง/ลบร้าน

**ไฟล์:**
- หน้าเว็บ: `shops.php`
- API: `api/shops.php`
- Service: `app/Services/ShopService.php`
- Repository: `app/Repositories/ShopRepository.php`

**ตัวอย่าง:**
- จำกัดจำนวนร้านไม่เกิน 5: เพิ่มเงื่อนไขใน `ShopService->createShop()`
- เปลี่ยนชื่อร้านเริ่มต้น: แก้ constant `DEFAULT_SHOP_NAME` ใน `AuthService.php`

### 4.4 ถ้าอยากเปลี่ยนการแสดงผลใน Dashboard

**ไฟล์:**
- หน้าเว็บ: `dashboard.php`
- Service: `app/Services/DashboardService.php`

**ตัวอย่าง:**
- เปลี่ยนช่วงวันที่เริ่มต้น: แก้ใน `dashboard.php` (ส่วนที่กำหนด `$startDate` / `$endDate`)
- เพิ่มสถิติใหม่: แก้ใน `DashboardService->buildDashboard()`

---

## 5) 🗄️ แก้ฐานข้อมูล (Database)

### 5.1 ตารางอยู่ไหน
**ไฟล์:** `database/schema.sql`

ตารางหลักมี:
- `users` = ผู้ใช้
- `shops` = ร้านค้า
- `daily_records` = รายการรายวัน
- `monthly_goals` = เป้าหมายรายเดือน
- `password_reset_tokens` = โทเคนรีเซ็ตรหัสผ่าน
- `auth_rate_limits` = rate limit
- `idempotency_requests` = idempotency (ไม่ได้ใช้งานในเวอร์ชันปัจจุบัน)

### 5.2 เพิ่ม column ต้องระวังอะไร

**วิธีปลอดภัย:**
1. Backup database ก่อน
2. ใช้ `ALTER TABLE` แทนการรัน `schema.sql` ใหม่ (เพราะ schema.sql จะ DROP TABLE)
3. ถ้าเพิ่มคอลัมน์ใหม่ ให้ตั้งค่า default หรือ allow NULL

**ตัวอย่าง:**
```sql
ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL;
```

### 5.3 FK / UNIQUE / constraint สำคัญ

**Foreign Key สำคัญ:**
- `shops.user_id` → `users.id` (ON DELETE CASCADE)
- `daily_records.shop_id` → `shops.id` (ON DELETE CASCADE)
- `monthly_goals.shop_id` → `shops.id` (ON DELETE CASCADE)

**ความหมาย:**
- ลบ user → ลบร้านทั้งหมดของ user นั้นด้วย
- ลบร้าน → ลบข้อมูลรายวัน/เป้าหมายทั้งหมดของร้านนั้นด้วย

**UNIQUE สำคัญ:**
- `users.email` = ไม่ให้อีเมลซ้ำ
- `daily_records(shop_id, record_date)` = ห้ามบันทึกวันเดียวกันซ้ำในร้านเดียวกัน

### 5.4 เตือนเรื่องข้อมูลพัง

⚠️ **อย่ารัน `schema.sql` ซ้ำถ้ามีข้อมูลจริง**
- `schema.sql` มี `DROP TABLE IF EXISTS` = จะลบข้อมูลเก่าทั้งหมด
- ถ้าต้องการแก้โครงสร้าง ใช้ `ALTER TABLE` แทน

---

## 6) ⚠️ จุดที่ "ไม่แนะนำให้แก้" (Danger Zone)

### 6.1 ระบบ Authentication
**ไฟล์:**
- `app/Services/AuthService.php`
- `includes/auth.php`
- `includes/bootstrap.php` (session config)

**เสี่ยงอะไร:**
- แก้ผิดอาจทำให้ login ไม่ได้
- แก้การเก็บ password อาจทำให้ password เดิมใช้ไม่ได้
- แก้ session อาจทำให้ logout เอง

### 6.2 CSRF Protection
**ไฟล์:**
- `includes/functions.php` (functions: `csrf_token`, `verify_csrf`, `ensure_valid_csrf_or_respond`)

**เสี่ยงอะไร:**
- แก้ผิดอาจทำให้ทุก action ใช้ไม่ได้
- ปิด CSRF = เสี่ยงโดน attack

### 6.3 Transaction / Lock
**ไฟล์:**
- `app/Services/AuthService.php` (register, reset password)
- `app/Services/RecordService.php` (update record)

**เสี่ยงอะไร:**
- แก้ผิดอาจทำให้ข้อมูลชนกัน
- ลบ transaction = เสี่ยงข้อมูลไม่ครบ (ครึ่งสำเร็จ/ครึ่งพัง)

### 6.4 Config ที่เกี่ยวกับ Security
**ไฟล์:** `.env` และ `includes/config.php`

**ตัวแปรที่ห้ามเล่น:**
- `APP_ENV` (production/development)
- `SCHEMA_GUARD_ENABLED`
- `TRUST_PROXY` / `TRUSTED_PROXIES`
- `SESSION_IDLE_TIMEOUT_SECONDS`
- `SESSION_ABSOLUTE_TIMEOUT_SECONDS`

**เสี่ยงอะไร:**
- แก้ผิดอาจทำให้ระบบไม่ปลอดภัย
- แก้ timeout ผิด = logout บ่อยเกินไป หรือ session ไม่หมดอายุเลย

---

## 7) ✅ ตัวอย่างคำถามยอดฮิต + คำตอบ

### Q1: อยากเปลี่ยนชื่อเว็บ
**A:** แก้ `.env` บรรทัด `APP_NAME="..."`

### Q2: อยากปิดสมัครสมาชิก (ให้ admin เพิ่มคนเอง)
**A:**
1. ซ่อนฟอร์มสมัครในหน้า `login.php` (comment หรือลบแท็บ Register)
2. ปิด API: แก้ `api/auth.php` ให้ตรวจเงื่อนไข `action=register` แล้ว return error

### Q3: อยากซ่อนปุ่มบางปุ่ม (เช่น ปุ่มลบร้าน)
**A:** แก้หน้า `shops.php` → ค้นหาปุ่ม "ลบร้าน" → comment หรือลบ `<button>` ออก

### Q4: อยากเปลี่ยนสีธีม
**A:** แก้ `includes/header.php` ส่วน `<style>` หรือแก้ Tailwind class ในแต่ละหน้า

### Q5: อยากเพิ่ม field ให้กรอกชื่อเต็ม (Full Name)
**A:** ดูหัวข้อ 3.2 (ต้องแก้ DB + หน้า Profile + API + Service + Repository)

### Q6: อยากลดวันที่บันทึกย้อนหลังได้
**A:** แก้ `app/Services/RecordService.php` เพิ่มเงื่อนไขเช็ควันที่ก่อนบันทึก

### Q7: อยากให้มี SMS alert เวลาบันทึกข้อมูล
**A:** เพิ่มโค้ดส่ง SMS ใน `app/Services/RecordService.php` หลังบันทึกสำเร็จ (ต้องใช้ SMS API)

### Q8: อยากเปลี่ยนลิงก์ "ลืมรหัสผ่าน" ไปหน้าอื่น
**A:** แก้ `login.php` หาลิงก์ "ลืมรหัสผ่าน" แล้วเปลี่ยน `href="..."`

---

## 8) 🧭 สรุปสำหรับมือใหม่

### ✅ แก้ได้ปลอดภัย (มือใหม่ลองได้)
- เปลี่ยนชื่อเว็บ (`.env`)
- เปลี่ยนข้อความในหน้าเว็บ (`.php` แต่ละหน้า)
- เปลี่ยนสี/ปุ่ม (`includes/header.php`)
- ซ่อน/แสดงเมนู (`includes/header.php`)

### ⚠️ ต้องระวัง (ควรมีความรู้พื้นฐาน)
- เพิ่ม field ใหม่ (ต้องแก้หลายไฟล์)
- เปลี่ยน logic การคำนวณ (Service/Repository)
- เพิ่ม/ลด column ในฐานข้อมูล

### 🚫 ไม่แนะนำให้แก้ (มือใหม่ห้ามลอง)
- ระบบ Authentication
- CSRF protection
- Transaction/Lock
- Config ที่เกี่ยวกับ security
- Session timeout

### 💡 ควรถาม Support เมื่อไหร่
- ถ้าอยากแก้แต่ไม่แน่ใจว่าจะกระทบอะไร
- ถ้าแก้แล้วระบบพัง แต่คัดลอกไฟล์เดิมกลับมาแล้วยังพัง
- ถ้าต้องการเพิ่มฟีเจอร์ใหม่ที่ซับซ้อน (เช่น เพิ่ม role/permission)

---

## 📌 หมายเหตุท้ายเล่ม

เอกสารนี้เขียนตามโครงสร้างโปรเจกต์ **เวอร์ชันปัจจุบัน** หากโปรเจกต์มีการอัปเดตในอนาคต ไฟล์/path อาจเปลี่ยนแปลงได้

**แนะนำเพิ่มเติม:**
- อ่าน `README.md` เพื่อเข้าใจภาพรวมระบบ
- อ่าน `FLOW.md` เพื่อเข้าใจลำดับการทำงาน
- อ่าน `ARCHITECTURE.md` เพื่อเข้าใจโครงสร้างโค้ด
- อ่าน `DEPLOYMENT.md` ก่อนนำไปใช้งานจริง
