# CLAUDE.md — Ad-Profit

คู่มือสำหรับ Claude Code เมื่อทำงานกับ repo นี้ อ่านก่อนเขียน/แก้โค้ดทุกครั้ง

---

## โปรเจกต์คืออะไร

ระบบบันทึกรายได้/ค่าโฆษณา/กำไรรายวัน แยกตามร้าน (multi-shop) — **PHP แบบไฟล์ล้วน ไม่มี framework** DB เป็น **MySQL/InnoDB** ฝั่งหน้าเว็บ render แบบ server-side (Chart.js + Tailwind ผ่าน CDN, ไม่มี build step)

PHP ที่รองรับ: **≥ 8.2** — **enforce ใน composer.json แล้ว** (`"require": { "php": ">=8.2" }`) เหตุผลที่ยกจาก 8.1: `phpoffice/phpspreadsheet 5.x` (ใช้ทำ xlsx export) require `php ^8.2`. ทุกไฟล์มี `declare(strict_types=1)` · เซิร์ฟเวอร์ production = Hostinger PHP 8.3

⚠️ **extension ที่ host ต้องมี** (นอกจาก pdo_mysql/mbstring ตามปกติ): **`zip`, `gd`** — `phpoffice/phpspreadsheet` ประกาศเป็น hard requirement ถ้าขาด `composer install` ล้มตั้งแต่แรก (CI ทั้ง 2 jobs ที่ `composer install` ใส่ `zip, gd` ใน `setup-php` แล้ว)

⚠️ **ช่องว่างเรื่องเวอร์ชันที่ต้องรู้ (อย่าเข้าใจผิดว่าเทสต์ครอบ 8.2):**
- **test suite รันได้บน PHP 8.4+ เท่านั้น** — `phpunit/phpunit 13.x` require `php >= 8.4.1` → บน 8.2–8.3 `composer install` (dev) ไม่ผ่านตั้งแต่แรก
- ความเข้ากันได้กับ **8.2 พิสูจน์แค่ระดับ syntax** (`php -l`) ผ่าน CI job `lint-php82` — **ไม่ได้พิสูจน์ runtime behavior บน 8.2/8.3** (คือเวอร์ชันที่ production ใช้จริง)
- ถ้าต้องการ runtime coverage บน 8.2/8.3 จริง ต้อง downgrade PHPUnit (เช่น ^10) — **ยังไม่ทำตอนนี้**

---

## กฎสถาปัตยกรรม (ห้ามฝ่าฝืนโดยไม่จำเป็น)

- **Layer:** `Page/API (controller) → Service (business logic) → Repository (SQL/PDO) → MySQL`
  - Page/API แค่รับ input, ตรวจ method/CSRF, เรียก Service, จัดรูป response
  - Service = business rule + ownership check + transaction/lock
  - Repository = SQL ล้วน ไม่มี business logic
- **Manual DI:** สร้าง Repository + Service เองในแต่ละ endpoint แล้วส่ง `$pdo`/repo เข้า constructor — **ไม่มี container**
- **Result pattern:** Service คืน array เสมอ ไม่ throw สำหรับ business/validation error
  - สำเร็จ: `['success' => true, 'data'|'message' => ...]`
  - ล้มเหลว: `['success' => false, 'error' => '<ข้อความไทย>']`
- **ไม่มี router:** endpoint ใหม่ = ไฟล์ใหม่ (front-controller-per-file)
- **Autoloader:** ชื่อคลาส = ชื่อไฟล์ อยู่ใน `app/Services/` หรือ `app/Repositories/`

## Convention การเขียนโค้ด

- คลาส = PascalCase (1 คลาส/ไฟล์), เมธอด = camelCase, global helper = snake_case
- เพจ/endpoint = kebab-case (`add-record.php`), คลาส = PascalCase (`RecordService.php`)
- **ข้อความ error ที่ user เห็น = ภาษาไทย; log = อังกฤษ** ผ่าน `error_log('[tag] ...')`
- SQL = prepared statement 100% (`PDO`, `EMULATE_PREPARES=false`) ห้าม concat user input
- state-changing action ทุกตัวต้องผ่าน guard chain: `ensure_post_request_or_respond` → `ensure_form_content_type_or_respond` → `ensure_valid_csrf_or_respond`
- redirect ที่รับค่าจาก user ต้องผ่าน `resolve_safe_redirect_path`
- **ใช้ shared component เดิม ห้ามสร้างใหม่:** helper ใน `includes/functions.php` (csrf, validation, format, flash, response), guard ใน `includes/auth.php`, `db()` ใน `includes/database.php`

## Business rules ที่ต้องรักษา

- ผู้ใช้เห็นเฉพาะข้อมูลตัวเอง — ทุก Service ต้อง scope ด้วย user แต่วิธีต่างกัน:
  - single-shop (Record/Shop/Goal/Dashboard/Annual/Export): `userCanAccessShop()` / `findByIdAndUserId()`
  - overview (OverviewService/OverviewDailyService/OverviewAnnualService): `listByUserId($userId)`
- 1 ผู้ใช้สร้างได้สูงสุด 20 ร้าน; ลบร้านสุดท้ายไม่ได้
- `daily_records` unique ต่อ (shop, date) → บันทึกซ้ำวันเดิม = upsert
- revenue/ad_cost ≥ 0; note ≤ 255; ชื่อร้าน ≤ 100
- goal: เดือน `YYYY-MM`, ต้องมีอย่างน้อย 1 เป้า, ค่า ≥ 0
- `profit = revenue − ad_cost` (คำนวณ ไม่เก็บ), `ROAS = revenue / ad_cost` (null เมื่อ ad_cost = 0)
- **Overview (รวมร้าน) ดูได้เมื่อมี ≥ 2 ร้านเท่านั้น** (`can_view`)
- เปลี่ยน/รีเซ็ตรหัสผ่าน **และเปลี่ยนอีเมล** → increment `session_version` (เตะ session อื่น) — อีเมลคือช่องทางกู้บัญชี จึงถือเป็น credential เหมือนกัน

## โครงสร้างที่ต้องรู้ (gotchas)

- **Response layer:** controller ตอบผ่าน `api_respond()` — เลือก JSON (`jsonResponse`, กรณี XHR/`wants_json`) หรือ redirect+flash (กรณี form) อัตโนมัติ + `infer_http_status_from_error()`. Service คืน result-array, controller เป็นคนแปลงเป็น response
- **Frontend เป็น server-render เป็นหลัก:** state-changing = native `<form method="post" action="/api/...">` + `csrf_field()` + hidden `action` → redirect+flash; ปุ่มยืนยัน/loading/กันกดซ้ำ อยู่ใน `includes/footer.php`; **CSRF ไม่ได้ expose เป็น meta/JS**
- ⚠️ **AJAX มีจุดเดียวที่ตั้งใจ (controlled exception):** `GET api/month-grid.php` — โหลดข้อมูลทั้งเดือนมาเติมตาราง bulk ในหน้า `add-record.php` **read-only ไม่เปลี่ยน state จึงไม่มี CSRF** (auth ผ่าน session เหมือน `*-data.php`) · **การเขียนทุกอย่างยังเป็น form POST + CSRF เหมือนเดิม** · **ห้ามเพิ่มจุด AJAX ใหม่เพราะ "add-record ก็ทำ"** — ถ้าจะเพิ่มต้องเป็นการตัดสินใจที่มีเหตุผลชัดเจนเฉพาะกรณีนั้น
- **`api/dashboard-data.php`, `overview-data.php`, `annual-data.php` ไม่ถูกเรียกจาก UI** (data page เรียก Service ตรงในเพจ) — อย่าเข้าใจผิดว่าหน้าเว็บ fetch endpoint พวกนี้
- **กันกดซ้ำพึ่ง unique key ระดับ DB + row lock เท่านั้น** — ตาราง `idempotency_requests` + repository + cron ถูกลบทิ้งแล้ว (ไม่เคยถูกเรียกจากที่ไหนเลย) ⚠️ ผลข้างเคียงที่ดี: schema ไม่ต้องใช้คอลัมน์ชนิด JSON อีกต่อไป → host ไม่ต้องรองรับ JSON · ⚠️ **การลบซ้ำ (กด back แล้ว submit ใหม่) ยังตอบ error "ไม่พบรายการที่ต้องการลบ" ทั้งที่ลบสำเร็จไปแล้ว** — ถ้าจะแก้ต้องออกแบบ idempotency ใหม่ทั้งชุด
- **Schema Guard ใน `includes/bootstrap.php`:** ถ้า schema ไม่ตรง (ตาราง/คอลัมน์/index ที่กำหนด) ระบบตอบ 503 / CLI exit(1) ควบคุมด้วย flag `SCHEMA_GUARD_ENABLED` — **เวลาแก้ schema ต้องอัปเดต guard ด้วย**
- **`database/schema.sql` เป็น DROP + CREATE** — ห้ามรันทับ database จริง; ถ้าจะแก้โครงบน DB ที่มีข้อมูล ใช้ `ALTER` แยกต่างหาก ⚠️ ไฟล์**ขึ้นต้นด้วย `CREATE DATABASE ad_profit; USE ad_profit;` (hardcode ชื่อ DB จริง)** → `mysql < schema.sql` บนเซิร์ฟเวอร์ = **DROP ตารางใน `ad_profit` จริงทันที**; integration test loader (`tests/Integration/IntegrationTestCase.php`) จึง**ตัด 2 บรรทัดนี้ทิ้ง** ให้ DDL ลงเฉพาะ DB ที่ต่ออยู่
- **Auth/Session:** idle timeout 14400s, absolute 86400s; `requireAuth`/`requireGuest` เป็น guard; `isSessionVersionValid()` เช็ก DB **ทุก request**
- **Rate limiting:** auth ใช้ตาราง `auth_rate_limits` (DB) + fallback session; profile (email/password) ใช้ session-based ตอบ 429 · **login และ register มี 2 bucket เหมือนกัน**: ต่อ (IP + อีเมล) และต่อ IP ล้วน (`login_ip` / `register_ip`) — bucket ต่อ IP ไม่ถูกล้างตอนสำเร็จโดยตั้งใจ (ไม่งั้นสำเร็จ 1 ครั้งจะล้างประวัติการไล่เดาบัญชีอื่นทิ้ง)
- ⚠️ **token รีเซ็ตรหัสผ่านเดินทางผ่าน hidden field ของฟอร์มเท่านั้น ห้ามเก็บลง `$_SESSION`** — เดิม `reset-password.php` รับ `?token=` แล้วยัดลง session + เตะผู้ใช้ออกจากระบบ ทำให้ใครก็ได้ส่งลิงก์ของตัวเองให้เหยื่อกด แล้วรหัสที่เหยื่อพิมพ์ไปตกที่บัญชีผู้ส่ง (ทำซ้ำได้จริง) · ตอนนี้หน้าจอ **แสดงอีเมลของบัญชีที่ลิงก์นั้นเป็นเจ้าของ** และเตือนเมื่อไม่ตรงกับบัญชีที่ล็อกอินอยู่ · `api/auth.php` อ่าน token จาก `$_POST` เท่านั้น
- **เปลี่ยนรหัสผ่าน/อีเมลในหน้าโปรไฟล์ต้องล้าง token รีเซ็ตที่ค้างอยู่ด้วย** (`ProfileService::revokePasswordResetTokens()`) — คู่กับ `AuthService::resetPassword` ที่ลบอยู่แล้ว ไม่งั้นลิงก์เก่ายังเขียนทับรหัสใหม่ได้
⚠️ **ห้ามใช้ชื่อ placeholder ซ้ำในคำสั่ง SQL เดียว** — `EMULATE_PREPARES=false` ทำให้ MySQL ตอบ `HY093` แล้ว query ล้มเงียบ (เคยทำให้ rate limit ตายทั้งระบบ)
- **Security extra:** CSV export กัน formula injection (เติม `'` หน้า cell ที่ขึ้นต้น `= + - @ \t \r`) — **guard นี้อยู่ใน controller `api/export.php` (closure `$sanitizeCsvCell`) ไม่ใช่ `ExportService` → unit-test ที่ระดับ service ไม่ได้ ต้องเทสต์ผ่าน integration ที่ยิง endpoint จริง**; ⚠️ **guard ทำเฉพาะคอลัมน์โน้ต** (ช่องเดียวที่ผู้ใช้พิมพ์ — ตำแหน่งมาจาก `note_column_index` ใน payload) เซลล์ที่ระบบสร้าง (วันที่ ISO/ตัวเลข/%) ออกดิบ เพื่อให้ Excel อ่านเป็นตัวเลข/วันที่ ไม่ใช่ข้อความ; reset token เก็บเป็น hash + TTL, security headers เซ็ตใน bootstrap

## Logic ที่อยู่ที่ controller/view (ไม่ใช่ service)

> จุดที่ business logic หลุดออกจาก Service มาอยู่ที่ controller/page — ต้องรู้ก่อนแก้ (verified จากโค้ดจริง)

- **Rate-limit ของ profile:** อยู่ใน `api/profile.php` เป็น closure session-based (5 ครั้ง/60s → 429) **ไม่ได้อยู่ใน `ProfileService`** — ต่างจาก `AuthService` ที่ rate-limit อยู่ใน service (ระวังตอนแก้/เพิ่ม rate-limit อย่าถือว่าเป็น pattern เดียวกัน) · นับเฉพาะเคสที่รหัสผ่านปัจจุบันผิด (service ทำเครื่องหมาย `credential_failure`) ไม่ใช่ทุก validation error
- ⚠️ **`includes/header.php` ถูก include กลางหน้า — ตัวแปรอยู่ scope เดียวกับเพจ** ห้ามใช้ชื่อที่เพจใช้ (`$shopId`, `$userId`, `$selectedMonth` …) ในนั้น เคยใช้ `$shopId` ในลูปเลือกร้านแล้วทับค่าของเพจด้วย "ร้านสุดท้ายในรายการ" · โค้ดของเพจที่อยู่ **หลัง** `require header.php` จะได้ค่าผิดทั้งหมด (มีเทสต์ `ShopContextGuardTest` ล็อกไว้)
- **ฟอร์มที่เขียนข้อมูลทุกอันต้องมี `shop_context_field($shopId)` คู่กับ `csrf_field()`** — 7 ฟอร์มใน `add-record.php` (3), `history.php` (2), `dashboard.php` (2) · controller ตรวจด้วย `ensure_shop_context_or_respond()` ตอบ 409 เมื่อ session ชี้คนละร้านกับตอนที่หน้าถูกเรนเดอร์ ⚠️ guard ต้องวาง **หลัง** `$shopId` ถูกกำหนดค่า · ฟอร์มที่ไม่ส่งค่านี้มาจะผ่านโดยตั้งใจ (กันอุบัติเหตุ ไม่ได้กันการโจมตี — สิทธิ์ยังตรวจที่ Service)
- **ฟอร์มหลักในหน้าบันทึกเติมค่าเดิมของวันนั้นเสมอ** (`RecordService::getRecordForDate()` ฝั่งเซิร์ฟเวอร์ + JS ดึงผ่าน `api/month-grid.php` ตอนเปลี่ยนวัน) — การบันทึกเป็น upsert ที่เขียนทับทุกช่อง ถ้าไม่เติมโน้ตเดิมกลับมา การแก้แค่ยอดขายจะลบโน้ตทิ้ง
- **`RecordService::cleanImportNumber()` เดา "ตัวคั่นทศนิยม" เอง** — ตัวคั่นที่อยู่ขวาสุดคือจุดทศนิยม เว้นแต่ตามหลังด้วยตัวเลขไม่ใช่ 1–2 หลัก (`1,234` = คั่นหลักพัน · `1234,56` = ทศนิยม) ⚠️ **ห้ามกลับไปลบจุลภาคทิ้งเสมอ** — Excel ยุโรปใช้จุลภาคเป็นทศนิยม การลบทิ้งทำให้ยอดโตขึ้น 100 เท่าแล้วนำเข้าสำเร็จ · **มีคู่แฝดใน JS** `cleanAmountCell()` ที่ `add-record.php` ต้องใช้กติกาเดียวกัน
- **ตัวกัน "เว้นช่องแล้วของเดิมหาย" ใช้กับทุกทางที่เขียนข้อมูล** (`rejectBlankCellsOverwritingExistingDays()`) — ทั้ง CSV และตารางกรอกหลายวัน · คุมทั้ง รายได้/ค่าแอด/**โน้ต** ⚠️ เตือนเฉพาะเมื่อ "มีของจริงจะหาย" (ค่าเดิม `0` หรือโน้ตว่าง ไม่นับ) และรายงาน **แถวที่ผู้ใช้เห็นเป็นแถวแรก** ไม่ใช่วันที่น้อยสุด
- **หน้าตั้งรหัสผ่านใหม่ต้องพา token กลับมาเมื่อบันทึกไม่ผ่าน** (`api/auth.php` → `/reset-password.php?token=…`) — ไม่งั้นพิมพ์รหัสยืนยันผิดครั้งเดียวจะถูกเด้งไปหน้า "ลืมรหัสผ่าน" พร้อมข้อความที่ไม่จริง และ `reset-password.php` ต้องไม่เขียนทับ flash error ที่ API ตั้งไว้
- **CSV: "ช่องว่าง = 0" ใช้กับวันใหม่เท่านั้น** — ถ้าวันนั้นมีข้อมูลอยู่แล้ว ระบบปฏิเสธทั้งไฟล์พร้อมบอกแถว (`rejectBlankCellsOverwritingExistingDays()`) · parser ส่งธง `revenue_was_blank`/`ad_cost_was_blank` มาให้แยก "ช่องว่าง" ออกจาก "ผู้ใช้พิมพ์ 0"
- **สูตร profit/ROAS ซ้ำใน view:** `RecordService->getRecentRecords()` คืน row ดิบ → `add-record.php` (ตาราง "รายการล่าสุด") คำนวณ profit/ROAS เองในเพจ ⚠️ **แก้สูตร profit/ROAS ต้องตามไปอัปเดต view นี้ด้วย ไม่ใช่แค่ service**
- **`$today` seam:** service ที่ผลลัพธ์ขึ้นกับวันที่ (`RecordService`, `AnnualService`, `OverviewAnnualService`, `ExportService`, `DashboardService`) รับ `?string $today = null` ท้าย param ไว้ให้เทสต์ล็อกวันได้ — **ถ้าเพิ่ม logic ที่อ่านวันที่ ต้องรับ seam ต่อไปด้วย** ไม่งั้นบางส่วนอ้างวันนี้จริงขณะที่ส่วนอื่นใช้ค่าที่ส่งเข้ามา (เคยเป็นบั๊กใน `DashboardService::resolveRange`) · ใน `RecordService` ใช้ `resolveToday()` ตัวเดียว (เคยคัดลอกบล็อกเดิมไว้ 4 ที่)
- **"วันล่าสุด" = วันล่าสุดที่ ≤ วันนี้ ไม่ใช่ `record_date` มากที่สุด** — ระบบอนุญาตให้ลงวันล่วงหน้า แถวพวกนั้นจึงลอยขึ้นบนสุดของ `ORDER BY record_date DESC` เสมอ `getDaysSinceLastRecord` และ `getWeekdayContext` จึงใช้ `findLatestOnOrBeforeDate()` เหมือนกัน ⚠️ **ยกเว้น `getRecentRecords()` ที่ตั้งใจให้เห็นแถววันอนาคต** (ไม่งั้นผู้ใช้ที่เพิ่งบันทึกจะคิดว่าไม่ถูกบันทึก) — มีเทสต์ล็อกไว้ทั้งสองฝั่ง อย่า "กวาดให้เหมือนกัน"
- **ทุกเพจต้องเรียก `resolve_current_shop_id($pdo, $userId)` ก่อนดึงข้อมูล** แทนการอ่าน `$_SESSION['current_shop_id']` ตรง ๆ — helper ซ่อม session ที่ชี้ไปร้านที่ถูกลบไปแล้ว (จากอุปกรณ์อื่น) และตั้ง flash บอกผู้ใช้ว่าถูกสลับร้านให้ · เดิมการซ่อมอยู่ใน `header.php` ที่ include ท้ายไฟล์ หน้าจึงขึ้น "คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้" + ฿0 หนึ่งครั้งก่อนหายเอง · `shop_context_for_user()` cache ต่อ request จึงไม่ยิง query ซ้ำ
- **`ShopService::createShop` เช็ก "ชื่อซ้ำ" ก่อน "โควตา 20 ร้าน" เสมอ** — ชื่อที่มีอยู่แล้วคือการสลับไปร้านนั้น ไม่ได้สร้างแถวใหม่ โควตาจึงไม่ควรมาขวาง (เดิมผู้ใช้ที่ครบ 20 ร้านพิมพ์ชื่อร้านตัวเองไม่ได้เลย)
- **ทศนิยมของค่าเงินเกิน 2 ตำแหน่งถูกปฏิเสธ** (`RecordService::hasTooManyDecimals()` · `AMOUNT_DECIMALS`) ใช้ร่วมกับ `GoalService` — ไม่งั้น MySQL ปัดให้เงียบ ๆ แล้วระบบรายงานว่าสำเร็จ ⚠️ เทียบด้วย epsilon ไม่ใช่สตริง เพราะ `0.1 + 0.2` = `0.30000000000000004`
- **เพดานค่าเงิน `9,999,999,999.99`** = `RecordService::MAX_AMOUNT` และ `GoalService::MAX_AMOUNT` (alias ของตัวแรก) — คอลัมน์เป็น `DECIMAL(12,2)` ทั้งคู่ ขยับต้องขยับพร้อม schema
- **วันที่กำกวมถูกปฏิเสธทั้งสองฝั่ง:** `05/03/2026` (ทั้งสองเลข ≤ 12) อ่านได้ทั้ง D/M และ M/D → PHP `RecordService::isAmbiguousSlashDate()` (import CSV) และ JS `parseDateCell()` ใน `add-record.php` (วางจาก Excel) คืน null เหมือนกัน · JS ใช้ `looksLikeDateCell()` แยกต่างหากสำหรับเช็กแถวหัวตาราง — **ห้ามเอา `parseDateCell` ไปเช็ก header** ไม่งั้นแถวข้อมูลแรกที่วันกำกวมจะถูกทิ้ง
- **การเทียบ "เดือนนี้ vs เดือนก่อน" ตัดที่วันเดียวกันทุกหน้า** — `resolve_comparison_cutoff_day()` + `comparison_range_end()` ใน `includes/functions.php` ใช้ร่วมกันทั้ง `DashboardService` และ `OverviewService` · เดิมมีแค่แดชบอร์ดที่ตัดวัน หน้ารวมร้านจึงบอก −87.1% ขณะที่แดชบอร์ดบอก 0% จากข้อมูลชุดเดียวกัน ⚠️ ตารางหน้ารวมร้านยังโชว์กำไร **ทั้งเดือน** ตามเดิม ตัดวันเฉพาะตัวเลขที่เอาไปเทียบ
- **ช่วงของแดชบอร์ดไม่เลยวันนี้** (`clampRangeEndToToday()`) — `week_this`/`month_this`/`month_pick` ตัดที่วันนี้ เพราะระบบอนุญาตให้ลงวันล่วงหน้า · เดิมการ์ดรวมยอดวันอนาคตขณะที่ป้ายใต้การ์ดตัดที่วันนี้ (การ์ด ฿9,000 คู่กับป้าย 0% และ "วันดีสุด" เป็นวันที่ยังมาไม่ถึง) ⚠️ **ช่วง `custom` ไม่ถูกตัด** — ผู้ใช้เลือกเองย่อมตั้งใจ · ความคืบหน้าเป้ายังใช้ทั้งเดือน (เป้าเป็นของทั้งเดือน)
- **สูตร % เป้าและ "ถึงเป้าไหม" อยู่ที่ `GoalService::progressPercent()` / `isReached()`** — ใช้ร่วมกันทั้งแดชบอร์ด หน้ารายปี และ Excel · เดิมแดชบอร์ดปัดลงแต่หน้ารายปีปัดขึ้น หน้ารายปีจึงขึ้น 100.0% คู่กับ "ยังไม่ถึงเป้า" · **เป้า 0 ไม่นับว่าถึงเป้า** (เดิมสองหน้าตอบต่างกัน)
- **`%` เปลี่ยนแปลงของ ROAS คิดจากค่าดิบ ไม่ใช่ค่าที่ปัดแล้ว** — ปัดก่อนหาผลต่างทำให้ 0.05% กลายเป็น 0.5% และป้ายเปลี่ยนจากเทาเป็นเขียว
- **`formatMoney()` แสดงสตางค์เฉพาะตอนมีเศษ** — เดิมตัดทิ้งทุกช่อง ทำให้แถวรวมไม่เท่ากับผลบวกของแถวที่เห็น (฿100 ×3 แต่รวม ฿301)
- **สัดส่วนกำไรของแถวรวม = 100% ตามนิยาม** ห้ามบวกค่าที่ปัดแล้วทีละแถว (3 ร้านเท่ากัน → 99.9%) · **"วันที่กรอก" ของแถวรวมใช้ค่ามากสุด ไม่ใช่ผลบวกข้ามร้าน** (3 ร้าน × 31 วัน ≠ 93 วันในเดือนที่มี 31 วัน)
- **`%` ความคืบหน้าเป้าปัดลง** (`DashboardService::calculateGoalPercent` ใช้ `floor`) — `round` ทำให้ 99.996% ขึ้นเป็น 100.0% คู่กับป้าย "ยังไม่ถึงเป้า" ในการ์ดเดียวกัน (ป้ายเทียบค่าจริง)
- **ป้าย `↑/↓ %` ทุกที่ต้องผ่าน `format_change_badge()`** ใน `includes/functions.php` — 5 จุด (`history.php`, `dashboard.php`, `annual.php`, `overview.php` ×2) เคยเขียนเองแล้วไม่ตรงกัน บางที่ `>= 0` จึงขึ้น "↑ 0.0%" สีเขียวทั้งที่ยอดเท่าเดิม · helper ตัดสินจากค่า **หลังปัด 1 ตำแหน่ง** ให้ลูกศรตรงกับเลขที่เห็นเสมอ
- **อันดับร้านในหน้ารวมร้าน:** `OverviewService::buildShopComparison` ดัน **ร้านที่ยังไม่มีข้อมูล (`days_count = 0`) ไปท้ายตารางเสมอ** — กำไร `0.0` ของร้านที่ไม่ได้กรอก "มากกว่า" ร้านที่ขาดทุนจริง จะขึ้นอันดับ 1 ทั้งที่ควรแปลว่า "ยังไม่รู้" · กำไรเท่ากันตัดด้วย `days_count` แล้วชื่อ (query ไม่การันตีลำดับ)
- **ประมาณการสิ้นปีนับเศษของเดือนปัจจุบันด้วย** — `months_remaining` = เดือนเต็มที่เหลือ แต่ตัวคูณจริงคือ `months_remaining + current_month_remaining_ratio` (นับจากปฏิทิน ไม่ใช่จำนวนวันที่กรอก) เดิมวันที่เหลือของเดือนนี้หายไปทั้งจาก `$cumulativeProfit` และ `$monthsRemaining` ⚠️ ป้าย "เหลืออีก …" อยู่ทั้งใน `annual.php` และ `XlsxReportService` — **แก้ต้องแก้คู่**
- **กริด/ชีต "ฤดูกาล" โผล่เมื่อมีข้อมูล ≥ 2 ปีเท่านั้น** — `buildMonthlyHeatmap()` คืน `comparable` มาให้ ทั้ง `annual.php` และ `api/export-xlsx.php` ใช้ค่านี้ตัดสิน (ปีเดียว = กริดว่าง 2 ใน 3 แถว ไม่ใช่ฤดูกาล)
- **`DashboardService` memo `getByDateRange` ต่อการเรียก 1 ครั้ง** (`fetchRecords()`) — โหลดแดชบอร์ดเคยยิงช่วง `เดือนนี้ทั้งเดือน` ซ้ำ 2 รอบ (การ์ดสรุป + ความคืบหน้าเป้า) · cache key มี `shopId` ด้วย และอ็อบเจ็กต์สร้างใหม่ทุก request จึงไม่ค้างข้ามคำขอ — **เพิ่มจุดอ่านใหม่ใน service นี้ให้เรียก `fetchRecords()` ไม่ใช่ repo ตรง**
- **`cron/cleanup-logs.php` กวาด `dirname(LOG_FILE)` ไม่ใช่ `<project>/logs`** — default ของ `LOG_FILE` คือ `sys_get_temp_dir()/ad-profit/php-error.log` เดิม cron hardcode ไปที่โฟลเดอร์ `logs/` ในโปรเจกต์ซึ่งมีแต่ `.gitkeep` (log จริงจึงโตไปเรื่อย ๆ) · `LogCleanupRepository` ลบเฉพาะไฟล์ที่ match `*.log*` — เดิมลบทุกไฟล์ในโฟลเดอร์ ถ้ามีคนวาง `.htaccess` กันไม่ให้เข้าถึง log ผ่านเว็บ ไฟล์นั้นจะหายไปเงียบ ๆ
- **คอลัมน์ "เทียบครั้งก่อน" ใน CSV ต้องไม่มีตัวคั่นหลักพัน** — คอลัมน์ตัวเลขอื่นส่ง `''` เป็น separator อยู่แล้ว เฉพาะช่องนี้ที่เคยใช้ค่า default ทำให้ค่าโต ๆ ออกมาเป็น `+9,999,900.0%` ที่ Excel อ่านเป็นสูตรผิดไวยากรณ์
- **ข้อความ error ตอน import CSV ใช้ "แถวที่ N" ไม่ใช่ "บรรทัดที่ N"** — `fgetcsv` คืน 1 record ต่อครั้ง โน้ตที่มีขึ้นบรรทัดใหม่ในเครื่องหมายคำพูดกินหลายบรรทัดในไฟล์ เลขนี้จึงตรงกับเลขแถวใน Excel (สิ่งที่ผู้ใช้เปิดดูจริง) ไม่ตรงกับเลขบรรทัดของไฟล์ดิบ
- **เกณฑ์ตัวอย่างขั้นต่ำของวันในสัปดาห์ = `RecordService::WEEKDAY_MIN_SAMPLE`** — `comparable` (มีอะไรให้เทียบไหม) ยังเป็น `>= 1` แต่การฟันธง "สูงกว่า/ต่ำกว่าปกติ" ใช้ `trend_reliable` (`>= 3`) เกณฑ์เดียวกับที่ตารางแยกตามวันใช้เลือกวันดี/วันเงียบ · **อย่าฮาร์ดโค้ด 3 ซ้ำในเพจ**

> ตรรกะที่เคยอยู่ที่ controller และถูกย้ายลง service/helper แล้ว — อย่าย้ายกลับ:
> แปลงปี พ.ศ. → `resolve_calendar_year()` · cutoff เดือนอนาคต → `resolve_calendar_month()` ·
> typed-confirm ตอนลบร้าน → `ShopService::confirmationNameFor()` + `deleteShop()` ·
> ตรวจรูปแบบเดือนของเป้าหมาย → `GoalService` (controller ส่งค่าดิบ ห้ามใช้ `normalize_month_input` กับข้อมูล)

**`?month` ต้องผ่าน `resolve_calendar_month()` ทุกจุด ไม่มีข้อยกเว้น** — ปัจจุบันครบแล้วทั้ง 7 จุด
(`dashboard.php`, `overview.php`, `history.php`, `api/dashboard-data.php`, `api/overview-data.php`,
`api/month-grid.php`, `api/export.php`) และ picker ทุกตัวมี `max="<เดือนนี้>"`
⚠️ `dashboard.php`/`api/dashboard-data.php` ต้องแยก `month=` **ที่ว่าง** (= ไม่ได้เลือก → `null`)
ออกจากเดือนปัจจุบัน — สองไฟล์นี้ต้องเขียนเหมือนกันเป๊ะ ๆ ไม่งั้นหน้าเว็บกับ endpoint ตอบคนละอย่าง

---

## การเทสต์ (Testing)

> **สถานะปัจจุบัน: setup ครบแล้ว** — `phpunit/phpunit` อยู่ใน `require-dev`, มี `phpunit.xml` (testsuite `Unit`/`Integration`), มี `tests/bootstrap.php`
> `composer test` รันได้เลย ไม่ต้อง setup อะไรเพิ่ม

### ⚠️ ความครอบคลุมไม่เท่ากันทั้งระบบ — อย่าเชื่อตัวเลขรวม

จำนวนเทสต์รวมดูเยอะ แต่กระจุกอยู่ที่ **ฝั่งอ่าน/รายงาน** เกือบทั้งหมด ฝั่ง **เขียนข้อมูล** แทบไม่มี:

| พื้นที่ | สถานะ |
|---|---|
| Annual / Overview / Xlsx / Dashboard / Export (อ่าน) | ครอบแน่น |
| RecordService ฝั่งอ่าน (grid, weekday, days-since, unfilled) | ครอบแน่น |
| Auth (login/logout/reset password/session guard) | ครอบแล้ว (integration) |
| ShopService / GoalService / ProfileService / RecordService เขียน-ลบ | ครอบแล้ว |
| **ชั้น controller (`api/*.php`) ทั้งชั้น** | **0** — `grep "api/\|\$_POST\|csrf" tests/` ยังว่างเปล่า |
| **JavaScript ทั้งหมด** (paste TSV, month-grid, typed-confirm) | **0** — ไม่มี JS test runner |
| **EmailService การส่งจริง** | ตรวจได้แค่คอนฟิก — การส่งถึงจริง **ต้อง verify มือ** |

**ชั้น controller และ JS ยังไม่มีตาข่าย** — logic ที่อยู่ตรงนั้นให้ย้ายลง service/helper
ก่อนแก้ (มีตัวอย่างในหัวข้อด้านบน) หรือถ้าย้ายไม่ได้ ให้ verify ด้วย curl/เบราว์เซอร์จริง

### Framework
- **PHPUnit** เป็น dev dependency, รันด้วย `composer test`
- ⚠️ **ต้องใช้ PHP 8.4+ ในการรันเทสต์** (PHPUnit 13 require `php >= 8.4.1`) — เครื่องที่เป็น 8.2–8.3 จะ `composer install` dev dependency ไม่ผ่าน; ความเข้ากันได้กับ 8.2 คุมด้วย `php -l` ใน CI job `lint-php82` เท่านั้น (syntax ไม่ใช่ runtime)
- `phpunit.xml` เปิด `failOnWarning="true"` + `failOnNotice="true"` → warning/notice = เทสต์แดง (CI บังคับ)

### โครงสร้าง
```
tests/
  bootstrap.php        ← test bootstrap (ดูกฎด้านล่าง)
  Unit/                ← mock repository, ไม่แตะ DB
  Integration/         ← MySQL test DB จริง
phpunit.xml            ← แยก testsuite: Unit / Integration
```

### กฎเหล็กของ test bootstrap
- `tests/bootstrap.php` ต้อง **define constant ที่จำเป็น + include `includes/functions.php` และ `includes/auth.php`** เท่านั้น
- **ห้าม include `includes/bootstrap.php` เต็ม** ใน unit test (มันสั่ง `session_start`, security headers, `db()`, schema guard — มี side effect)
- constant กำหนดใน bootstrap ก่อน include config ได้ เพราะ `config.php` ใช้ `if (!defined(...))`

### Unit tests (เริ่มที่นี่ก่อนเสมอ — เร็ว ไม่ต้อง DB)
**ก่อนเขียน: ตรวจ constructor ของ Service ก่อน — รับ PDO ไม่เหมือนกัน**
- รับ `?PDO $db = null` (ส่ง `null` ข้าม transaction ได้): **RecordService, ShopService, GoalService, ProfileService** เท่านั้น
- **AuthService** รับ `PDO $db` แบบ **required** (ส่ง null ไม่ได้) → เทสต์เป็น integration หรือ mock PDO
- **DashboardService, AnnualService, OverviewService, OverviewDailyService, OverviewAnnualService** → **ไม่มี param PDO เลย** (รับแต่ repository) → mock repo ล้วน ไม่ต้องยุ่ง db
- **ExportService** → ไม่มี param PDO เช่นกัน แต่ `__construct(RecordService, ShopRepository)` — รับ **1 Service + 1 Repository** → เวลา unit test ต้อง **mock `RecordService` ด้วย** ไม่ใช่แค่ repository

**วิธี double repository (PHPUnit 13 — แยก 2 กรณีให้ชัด):**
- **pure stub** — แค่ต้องการค่า return (เช่น `userCanAccessShop` คืน `true`, `findByIdAndUserId` คืน row) ไม่ verify การเรียก → ใช้ `$this->createStub(XxxRepository::class)` **← default ของโปรเจกต์นี้** เพราะเคสส่วนใหญ่ fail ที่ validation ก่อนแตะ repo
- **verify interaction** — ต้องเช็กว่า method ถูกเรียก/เรียกกี่ครั้ง/ด้วยอาร์กิวเมนต์อะไร (`->expects($this->once())` ฯลฯ) → ใช้ `$this->createMock(XxxRepository::class)`
- เหตุผล: PHPUnit 13 ขึ้น notice ("No expectations were configured for the mock object... Consider using a test stub instead") ถ้าใช้ `createMock` แบบไม่ตั้ง expectation

assert แบบ **result-array** (`assertFalse($result['success'])`, `assertStringContainsString('<คำไทย>', $result['error'])`)

เป้าหมายหลัก (validation + business rule):
- `RecordService`: revenue/ad_cost ติดลบ → fail, note > 255 → fail
- `GoalService`: ไม่กรอกเป้าเลย → fail
- `ShopService`: สร้างเกิน 20 ร้าน → fail, ลบร้านสุดท้าย → fail
- `ProfileService`: display_name > 120 → fail, change email/password (verify รหัสเดิม, รหัสใหม่ต้องต่างเดิม)
- `ExportService`: รูปแบบ CSV — วันที่เป็น **ISO `YYYY-MM-DD`**, ค่าที่ไม่มี (ROAS/เทียบเมื่อวาน) เป็น **เซลล์ว่าง** ไม่ใช่ `'–'`, payload มี `note_column_index` + `blank_row_before_totals` ให้ controller ใช้ + `buildMonthlyCsvFilename` sanitize ชื่อไฟล์ (⚠️ **การกัน formula injection (เติม `'` หน้า cell ที่ขึ้นต้น `= + - @ \t \r`) อยู่ที่ controller `api/export.php` (closure `$sanitizeCsvCell`) และทำ *เฉพาะคอลัมน์โน้ต* ไม่ใช่ทุกเซลล์** — `buildMonthlyCsvPayload()` คืน payload ดิบ (`note` ส่งผ่านตรง ๆ) → อย่าเขียน unit test formula-injection ที่ Service)
- `AnnualService`: `isValidYear` รับ **2000–2100** เท่านั้น (⚠️ **การแปลงปี พ.ศ. −543 อยู่ที่ controller** เช่น `annual-data.php`/`annual.php`/`overview.php` **ไม่ใช่ใน Service** — อย่าเขียน unit test แปลงปีที่ Service)
- `OverviewService`/`OverviewDailyService`/`OverviewAnnualService`: `can_view` = จำนวนร้าน ≥ 2
- `DashboardService`: สูตร profit / ROAS (ad_cost=0 → null) / margin (DashboardService ไม่ยุ่งกับปี)

### Integration tests (เมื่อจำเป็นต้องแตะ DB)
- **ต้องใช้ MySQL test DB จริง** — SQL เป็น MySQL-specific (`ON DUPLICATE KEY`, `FOR UPDATE`, `DATE_FORMAT`, `information_schema`) SQLite in-memory ใช้ไม่ได้
- seed schema จาก `database/schema.sql` เข้า test DB ก่อนรัน — **loader ตัด `CREATE DATABASE`/`USE ad_profit` ทิ้ง** (schema hardcode ชื่อ DB จริง ดู gotchas) ให้ DDL ลงเฉพาะ DB ที่ต่ออยู่
- **ใช้ test DB แยกผ่าน env `TEST_DB_*`** — base class ต่อ PDO เอง (ไม่ผ่าน `db()`) แล้วฉีดเข้า repo/service; **SAFETY: ชื่อต้องลงท้าย `_test` ไม่งั้น throw**; แต่ละ test isolate ด้วย `TRUNCATE` (ไม่ใช่ tx rollback เพราะ service เปิด transaction เอง)
- **เตรียม test DB (ทำครั้งเดียว):** `CREATE DATABASE ad_profit_test;` แล้ว set env เท่าที่ต่างจาก default — `TEST_DB_HOST` (default `127.0.0.1`), `TEST_DB_PORT` (`3306`), `TEST_DB_NAME` (`ad_profit_test`), `TEST_DB_USER` (`root`), `TEST_DB_PASS` (`''`); ต่อไม่ได้ → integration **skip** (ไม่ error)
- เป้าหมาย: `RecordRepository` upsert unique(shop,date), FK cascade ตอนลบร้าน/ลบ user, `UserRepository` increment session_version, `AuthService` register (สร้าง user + default shop)

### ข้อควรระวัง
- `AuthService` เขียน `$_SESSION` + เรียก `session_regenerate_id(true)` (ต้องมี active session) → ส่วน `establishSession` เทสต์ยากใน CLI ให้เทสต์เฉพาะ branch validation/rate-limit หรือทำเป็น integration ที่ start session — **ทำแล้วใน `tests/Integration/AuthServiceTest.php`:** `setUp()` เปิด session เอง (`ini_set` save_path ถ้าว่าง + `session_start(['use_cookies'=>'0','cache_limiter'=>''])` กัน "headers already sent" ใน CLI) โดยไม่แตะ AuthService/base class
- ตั้ง `$_SESSION = []` ใน `setUp()` เมื่อเทสต์โค้ดที่อ่าน session
- error message เป็นภาษาไทย → assert ด้วย substring ที่เป็นคำไทย (เช่น `'ติดลบ'`, `'ไม่มีสิทธิ์'`)

### workflow
- เขียน/แก้โค้ดเสร็จ → รัน `composer test` ให้ผ่านทั้งหมดก่อนจบงาน
- เพิ่มฟีเจอร์ใหม่ใน Service → เพิ่ม unit test ครอบ business rule นั้นด้วยเสมอ
- แก้ bug → เขียน test ที่ reproduce bug ก่อน แล้วค่อยแก้ให้ผ่าน

---

## คำสั่งที่ใช้บ่อย

```bash
composer install            # ติดตั้ง dependency
composer test                          # รันเทสต์ทั้งหมด
vendor/bin/phpunit --testsuite Unit    # รันเฉพาะ unit (เร็ว ไม่ต้อง DB)
```

## อย่าทำ

- อย่าใส่ business logic ใน Repository หรือ Page/API
- อย่า throw exception สำหรับ validation error (ใช้ result-array)
- อย่าเขียน SQL ด้วยการ concat ค่าจาก user
- อย่าสร้าง helper/guard ใหม่ถ้ามีใน `includes/` อยู่แล้ว
- อย่าให้ integration test แตะ database จริง (ใช้ test DB เท่านั้น)