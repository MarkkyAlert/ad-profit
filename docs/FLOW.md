# FLOW

Flow หลักของระบบ (สถานะล่าสุด: Phase 9)

## 1) Auth

### Register

1. ผู้ใช้เปิด `login.php` และ submit ไป `api/auth.php?action=register`
2. Controller ตรวจ method + CSRF
3. `AuthService::register()` ตรวจ rate limit + validation
4. Service สร้าง user + ร้านแรก (`ร้านค้าของฉัน`) แบบ transaction
5. สร้าง session context แล้ว redirect ไป `dashboard.php`

### Login

1. ผู้ใช้ submit ไป `api/auth.php?action=login`
2. ตรวจ method + CSRF + rate limit
3. verify รหัสผ่าน (`password_verify`)
4. set session (`user_id`, `current_shop_id`, `current_shop_name`) และ regenerate session id
5. redirect ไป `dashboard.php`

### Logout

1. ผู้ใช้กด Logout (POST)
2. ตรวจ CSRF
3. ล้าง session auth context + regenerate session id
4. redirect ไป `login.php`

## 2) Shop Management (Phase 4)

1. Header โหลด shop context จาก `ShopService::getShopContext()`
2. ผู้ใช้สลับร้านจาก dropdown -> `api/shops.php?action=switch`
3. ผู้ใช้สร้างร้านจาก modal -> `api/shops.php?action=create`
4. ผู้ใช้ลบร้าน -> `api/shops.php?action=delete` (กันลบร้านสุดท้าย)
5. Session `current_shop_id` อัปเดต แล้วทุกหน้ากรองข้อมูลตามร้านปัจจุบัน

## 3) Daily Records + History (Phase 2 + Phase 8)

1. หน้า `add-record.php` submit ไป `api/records.php?action=upsert`
2. `RecordService::upsertRecord()` ใช้ upsert (วันซ้ำอัปเดตทับ)
3. `history.php` เรียก `RecordService::getMonthlyRecords()` เพื่อแสดงตาราง/ยอดรวม
4. แก้ไขรายการ -> `api/records.php?action=update`
5. ลบรายการ -> `api/records.php?action=delete` (มี confirm)
6. Export CSV -> `api/export.php?month=YYYY-MM` (UTF-8 BOM, Excel Thai-safe)

## 4) Dashboard + Goals (Phase 3 + Phase 5)

1. `dashboard.php` เรียก `DashboardService::buildDashboard(...)`
2. Service สรุป KPI + สถิติ + chart รายวัน + trend 6 เดือน + เปรียบเทียบเดือนก่อน
3. ตั้งเป้ารายเดือน (modal) -> `api/goals.php?action=upsert`
4. ลบเป้า -> `api/goals.php?action=delete`
5. เป้าแสดง progress และสถานะ "ถึงเป้าแล้ว" เมื่อ >= 100%

## 5) Overview (All Shops) (Phase 6)

1. `overview.php` เรียก `OverviewService::buildOverview(userId, month)`
2. ถ้ามี < 2 ร้าน -> แสดงข้อความ fallback (ไม่แสดงข้อมูลเปรียบเทียบ)
3. ถ้ามี >= 2 ร้าน -> แสดงตารางเปรียบเทียบ, totals, bar chart, trend chart 6 เดือน

## 6) Annual Summary (Phase 7)

1. `annual.php` รับปี (รองรับ พ.ศ./ค.ศ.)
2. `AnnualService::buildYearlySummary()` คืนข้อมูล 12 เดือนเสมอ (เดือนไม่มีข้อมูล = 0)
3. แสดงการ์ดสรุปปี, ตารางรายเดือน, และกราฟแท่ง 12 เดือน

## 7) Unauthorized Access

- หน้าที่ต้อง login ใช้ `requireAuth()`
- ถ้าไม่ login:
  - หน้าเว็บ: redirect ไป `login.php`
  - API: JSON `401 Unauthorized`
