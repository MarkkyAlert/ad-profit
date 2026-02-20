# WHERE_TO_EDIT

คู่มือแก้ไขโปรเจกต์แบบเร็ว (อัปเดต Phase 9)

## Auth (สมัคร/ล็อกอิน/ล็อกเอาท์)

- UI: `login.php`
- Controller: `api/auth.php`
- Service: `app/Services/AuthService.php`
- Repository: `app/Repositories/UserRepository.php`, `app/Repositories/ShopRepository.php`

## Shops (สร้าง/สลับ/ลบร้าน)

- Header UI + shop modal: `includes/header.php`
- Controller: `api/shops.php`
- Service: `app/Services/ShopService.php`
- Repository: `app/Repositories/ShopRepository.php`

## Daily Record + History + Export CSV

- หน้าเพิ่มข้อมูล: `add-record.php`
- หน้าประวัติ: `history.php`
- Controller records: `api/records.php`
- Controller export: `api/export.php`
- Services: `app/Services/RecordService.php`, `app/Services/ExportService.php`
- Repository: `app/Repositories/RecordRepository.php`

## Dashboard + Goals

- หน้าแดชบอร์ด: `dashboard.php`
- API dashboard data: `api/dashboard-data.php`
- API goals: `api/goals.php`
- Services: `app/Services/DashboardService.php`, `app/Services/GoalService.php`
- Repository goals: `app/Repositories/GoalRepository.php`

## Overview (รวมทุกร้าน)

- หน้า: `overview.php`
- API: `api/overview-data.php`
- Service: `app/Services/OverviewService.php`

## Annual (สรุปรายปี)

- หน้า: `annual.php`
- API: `api/annual-data.php`
- Service: `app/Services/AnnualService.php`

## Layout / Responsive / UX ทั่วระบบ

- Header + modal ร้าน: `includes/header.php`
- Footer + bottom nav + loading overlay + toast fade: `includes/footer.php`

## Security/Helpers/Bootstrap

- Helpers (escape/csrf/flash/json): `includes/functions.php`
- Auth middleware: `includes/auth.php`
- Bootstrap + session/log/autoload: `includes/bootstrap.php`
- Config + rate limit: `includes/config.php`

## Database Changes

1. แก้ `database/schema.sql`
2. แก้ `database/sample_data.sql`
3. แก้ Repository ที่เกี่ยวข้อง
4. แก้ Service ที่ใช้ field/table นั้น
5. แก้ Controller/View ที่รับ-แสดงข้อมูล

## กฎก่อนแก้โค้ด

1. SQL ต้องอยู่ใน Repository เท่านั้น
2. POST ทุกจุดต้องมี CSRF
3. หน้า/API ต้องเรียกผ่าน Service (ไม่ข้ามไป query ตรง)
4. แสดงข้อมูลจาก user/DB ผ่าน `e()` เสมอ
