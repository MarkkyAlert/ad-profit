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

- สมัครสมาชิกใหม่ 1 บัญชี
- ตรวจว่าระบบพาไป `dashboard.php`
- ลอง logout แล้ว login ใหม่

## 7) Smoke Checklist (Phase 9)

หลังจาก login แล้วให้ทดสอบอย่างน้อย:

1. **Shops**
   - สร้างร้านที่ 2 จาก header modal
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

## 8) Troubleshooting

- ถ้าเจอ DB connection failed:
  - ตรวจว่า MySQL start แล้ว
  - ตรวจค่าฐานข้อมูลใน `includes/config.php`
- ถ้า redirect path เพี้ยน:
  - ตั้ง `APP_URL` ใน environment หรือแก้ใน `includes/config.php`
- log error อยู่ที่: `logs/php-error.log`
