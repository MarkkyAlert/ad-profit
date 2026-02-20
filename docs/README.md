# Ad Profit

เว็บแอปวิเคราะห์ยอดขาย/ค่าโฆษณาแบบหลายร้าน (Pure PHP + MySQL)

## สถานะปัจจุบัน

โปรเจกต์อยู่ที่ **Phase 9 (Polish + Review)**

ฟีเจอร์หลักที่มีแล้ว:

- ระบบบัญชีผู้ใช้: สมัคร / ล็อกอิน / ล็อกเอาท์ + rate limit
- ระบบร้านค้า: สร้างร้าน, สลับร้าน, ลบร้าน (ป้องกันลบร้านสุดท้าย)
- บันทึกข้อมูลรายวัน: upsert ต่อวัน, รายการล่าสุด, ประวัติรายเดือน, แก้ไข/ลบ
- Dashboard: เลือกช่วงเวลา, การ์ดสรุป, เปรียบเทียบเดือนก่อน, สถิติ, กราฟ
- เป้าหมายรายเดือน: ตั้ง/แก้ไข/ลบเป้า + progress + สถานะถึงเป้า
- หน้ารวมทุกร้าน: ตารางเปรียบเทียบ + กราฟแท่ง + แนวโน้ม 6 เดือน
- หน้าสรุปรายปี: การ์ดสรุปปี, ตาราง 12 เดือน, กราฟแท่ง 12 เดือน
- Export CSV รายเดือน: รองรับชื่อไฟล์ไทย + UTF-8 BOM สำหรับ Excel

## Tech Stack

- Backend: Pure PHP 8+
- Database: MySQL / MariaDB
- Frontend: HTML + Tailwind CSS (CDN) + Vanilla JS + Chart.js

## โครงสร้างหลัก

- `app/Services` — business logic
- `app/Repositories` — SQL layer
- `includes` — bootstrap/config/helpers/middleware/view partials
- `api` — API endpoints
- `database` — schema + sample data
- `docs` — เอกสารประกอบ

## ความปลอดภัยที่บังคับใช้

- ทุก POST form ต้องมี CSRF token
- SQL อยู่ใน Repository เท่านั้น (Prepared Statements)
- แสดง output ผ่าน helper `e()`
- password ใช้ `password_hash()` / `password_verify()`
- regenerate session id หลัง login

## บัญชีทดสอบ (หลัง import sample_data.sql)

- username: `demo_owner` / password: `password123`
- username: `demo_team` / password: `secret123`

## ตรวจสอบเบื้องต้นหลังติดตั้ง

1. สมัครสมาชิกใหม่ -> ต้องเข้า dashboard ได้
2. สร้างร้านที่ 2 -> สลับร้านแล้วข้อมูลต้องแยกกัน
3. บันทึกข้อมูลรายวัน -> ประวัติและแดชบอร์ดอัปเดต
4. ตั้งเป้ารายเดือน -> progress เปลี่ยนตามข้อมูล
5. Export CSV -> เปิดใน Excel แล้วภาษาไทยไม่เพี้ยน
