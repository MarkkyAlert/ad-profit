# CLAUDE.md — Ad-Profit

คู่มือสำหรับ Claude Code เมื่อทำงานกับ repo นี้ อ่านก่อนเขียน/แก้โค้ดทุกครั้ง

---

## โปรเจกต์คืออะไร

ระบบบันทึกรายได้/ค่าโฆษณา/กำไรรายวัน แยกตามร้าน (multi-shop) — **PHP แบบไฟล์ล้วน ไม่มี framework** DB เป็น **MySQL/InnoDB** ฝั่งหน้าเว็บ render แบบ server-side (Chart.js + Tailwind ผ่าน CDN, ไม่มี build step)

PHP ที่รองรับ: **≥ 8.1** (โค้ดใช้ `never` return type ซึ่งเป็นฟีเจอร์ 8.1) — แต่ **ยังไม่ได้ enforce ใน composer.json** (ไม่มี `"php"` constraint) ถ้าจะ pin ให้เพิ่ม `"require": { "php": ">=8.1" }`. ทุกไฟล์มี `declare(strict_types=1)`

⚠️ **ช่องว่างเรื่องเวอร์ชันที่ต้องรู้ (อย่าเข้าใจผิดว่าเทสต์ครอบ 8.1):**
- **test suite รันได้บน PHP 8.4+ เท่านั้น** — `phpunit/phpunit 13.x` require `php >= 8.4.1` → บน 8.1–8.3 `composer install` (dev) ไม่ผ่านตั้งแต่แรก
- ความเข้ากันได้กับ **8.1 พิสูจน์แค่ระดับ syntax** (`php -l`) ผ่าน CI job `lint-php81` — **ไม่ได้พิสูจน์ runtime behavior บน 8.1**
- ถ้าต้องการ runtime coverage บน 8.1 จริง ต้อง downgrade PHPUnit (เช่น ^10) — **ยังไม่ทำตอนนี้**

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
- เปลี่ยน/รีเซ็ตรหัสผ่าน → increment `session_version` (เตะ session อื่น)

## โครงสร้างที่ต้องรู้ (gotchas)

- **Response layer:** controller ตอบผ่าน `api_respond()` — เลือก JSON (`jsonResponse`, กรณี XHR/`wants_json`) หรือ redirect+flash (กรณี form) อัตโนมัติ + `infer_http_status_from_error()`. Service คืน result-array, controller เป็นคนแปลงเป็น response
- **Frontend เป็น server-render เป็นหลัก:** state-changing = native `<form method="post" action="/api/...">` + `csrf_field()` + hidden `action` → redirect+flash; ปุ่มยืนยัน/loading/กันกดซ้ำ อยู่ใน `includes/footer.php`; **CSRF ไม่ได้ expose เป็น meta/JS**
- ⚠️ **AJAX มีจุดเดียวที่ตั้งใจ (controlled exception):** `GET api/month-grid.php` — โหลดข้อมูลทั้งเดือนมาเติมตาราง bulk ในหน้า `add-record.php` **read-only ไม่เปลี่ยน state จึงไม่มี CSRF** (auth ผ่าน session เหมือน `*-data.php`) · **การเขียนทุกอย่างยังเป็น form POST + CSRF เหมือนเดิม** · **ห้ามเพิ่มจุด AJAX ใหม่เพราะ "add-record ก็ทำ"** — ถ้าจะเพิ่มต้องเป็นการตัดสินใจที่มีเหตุผลชัดเจนเฉพาะกรณีนั้น
- **`api/dashboard-data.php`, `overview-data.php`, `annual-data.php` ไม่ถูกเรียกจาก UI** (data page เรียก Service ตรงในเพจ) — อย่าเข้าใจผิดว่าหน้าเว็บ fetch endpoint พวกนี้
- **`idempotency_requests` + `IdempotencyRequestRepository` ยังไม่ถูกใช้จริง** — มีแค่ cron cleanup กันซ้ำจริงพึ่ง unique key ระดับ DB + row lock
- **Schema Guard ใน `includes/bootstrap.php`:** ถ้า schema ไม่ตรง (ตาราง/คอลัมน์/index ที่กำหนด) ระบบตอบ 503 / CLI exit(1) ควบคุมด้วย flag `SCHEMA_GUARD_ENABLED` — **เวลาแก้ schema ต้องอัปเดต guard ด้วย**
- **`database/schema.sql` เป็น DROP + CREATE** — ห้ามรันทับ database จริง; ถ้าจะแก้โครงบน DB ที่มีข้อมูล ใช้ `ALTER` แยกต่างหาก ⚠️ ไฟล์**ขึ้นต้นด้วย `CREATE DATABASE ad_profit; USE ad_profit;` (hardcode ชื่อ DB จริง)** → `mysql < schema.sql` บนเซิร์ฟเวอร์ = **DROP ตารางใน `ad_profit` จริงทันที**; integration test loader (`tests/Integration/IntegrationTestCase.php`) จึง**ตัด 2 บรรทัดนี้ทิ้ง** ให้ DDL ลงเฉพาะ DB ที่ต่ออยู่
- **Auth/Session:** idle timeout 14400s, absolute 86400s; `requireAuth`/`requireGuest` เป็น guard; `isSessionVersionValid()` เช็ก DB **ทุก request**
- **Rate limiting:** auth ใช้ตาราง `auth_rate_limits` (DB) + fallback session; profile (email/password) ใช้ session-based ตอบ 429
- **Security extra:** CSV export กัน formula injection (เติม `'` หน้า cell ที่ขึ้นต้น `= + - @ \t \r`) — **guard นี้อยู่ใน controller `api/export.php` (closure `$sanitizeCsvCell`) ไม่ใช่ `ExportService` → unit-test ที่ระดับ service ไม่ได้ ต้องเทสต์ผ่าน integration ที่ยิง endpoint จริง**; ⚠️ **guard ทำเฉพาะคอลัมน์โน้ต** (ช่องเดียวที่ผู้ใช้พิมพ์ — ตำแหน่งมาจาก `note_column_index` ใน payload) เซลล์ที่ระบบสร้าง (วันที่ ISO/ตัวเลข/%) ออกดิบ เพื่อให้ Excel อ่านเป็นตัวเลข/วันที่ ไม่ใช่ข้อความ; reset token เก็บเป็น hash + TTL, security headers เซ็ตใน bootstrap

## Logic ที่อยู่ที่ controller/view (ไม่ใช่ service)

> จุดที่ business logic หลุดออกจาก Service มาอยู่ที่ controller/page — ต้องรู้ก่อนแก้ (verified จากโค้ดจริง)

- **ปี (พ.ศ. + clamp):** controller (`annual.php:19-26`, `overview.php:31-38`, `api/annual-data.php:26-33`) แปลง พ.ศ. −543 (ช่วง 2400–2700) แล้ว clamp 2000–2100 (นอกช่วง → ปีปัจจุบัน) — **ตรรกะซ้ำ 3 ที่**; `AnnualService`/`OverviewAnnualService` `isValidYear` แค่ reject (2000–2100) **ไม่แปลง/ไม่ clamp**
- **Rate-limit ของ profile:** อยู่ใน `api/profile.php:31-91` เป็น closure session-based (5 ครั้ง/60s → 429) **ไม่ได้อยู่ใน `ProfileService`** — ต่างจาก `AuthService` ที่ rate-limit อยู่ใน service (ระวังตอนแก้/เพิ่ม rate-limit อย่าถือว่าเป็น pattern เดียวกัน)
- **Shop delete:** `api/shops.php:152-160` เรียก `ShopRepository->findByIdAndUserId` **ตรง** + ตัดชื่อร้าน 20 ตัวแรก (`mb_substr`) เทียบ `confirm_shop_name` เอง — เป็นจุดเดียวใน `api/` ที่ controller แตะ repo ตรงเพื่อ logic; typed-confirm rule อยู่ที่ controller **ไม่ใช่ `ShopService`**
- **สูตร profit/ROAS ซ้ำใน view:** `RecordService->getRecentRecords()` คืน row ดิบ → `add-record.php:138-141` คำนวณ profit/ROAS เองในเพจ ⚠️ **แก้สูตร profit/ROAS ต้องตามไปอัปเดต view นี้ด้วย ไม่ใช่แค่ service** (`AnnualService` summary มี `profit_margin` ให้แล้วเหมือน `OverviewAnnualService` — `annual.php` อ่านจาก service ไม่คำนวณเองแล้ว)

---

## การเทสต์ (Testing)

> ⚠️ **สถานะปัจจุบัน: ยังไม่มี test setup ในโปรเจกต์** — `composer.json` มีแค่ `phpmailer/phpmailer` ไม่มี `require-dev`, ไม่มี `phpunit.xml`, ไม่มีโฟลเดอร์ `tests/`, ไม่มี `vendor/bin/phpunit`
> **ต้อง setup ก่อนถึงจะรันเทสต์ได้** (ทำครั้งเดียว — ดูขั้นตอนด้านล่าง) หลังจากนั้นคำสั่ง/workflow ในหัวข้อนี้ถึงจะใช้ได้

### ขั้นตอน setup (ทำครั้งเดียว ก่อนเริ่มเขียนเทสต์)
1. `composer require --dev phpunit/phpunit` (ปล่อยให้ composer เลือกเวอร์ชันตาม PHP ของเครื่อง)
2. เพิ่มใน `composer.json`: `autoload-dev` (ให้เห็นคลาสใน `app/`) + `scripts.test` = `phpunit`
3. สร้าง `phpunit.xml` แยก testsuite เป็น `Unit` / `Integration`
4. สร้าง `tests/bootstrap.php` (ดูกฎด้านล่าง)

### Framework
- **PHPUnit** เป็น dev dependency, รันด้วย `composer test` **(หลัง setup แล้วเท่านั้น)**
- ⚠️ **ต้องใช้ PHP 8.4+ ในการรันเทสต์** (PHPUnit 13 require `php >= 8.4.1`) — เครื่องที่เป็น 8.1–8.3 จะ `composer install` dev dependency ไม่ผ่าน; ความเข้ากันได้กับ 8.1 คุมด้วย `php -l` ใน CI job `lint-php81` เท่านั้น (syntax ไม่ใช่ runtime)
- `phpunit.xml` เปิด `failOnWarning="true"` + `failOnNotice="true"` → warning/notice = เทสต์แดง (CI บังคับ)

### โครงสร้าง (หลัง setup)
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

### workflow (หลังมี setup แล้ว)
- เขียน/แก้โค้ดเสร็จ → รัน `composer test` ให้ผ่านทั้งหมดก่อนจบงาน
- เพิ่มฟีเจอร์ใหม่ใน Service → เพิ่ม unit test ครอบ business rule นั้นด้วยเสมอ
- แก้ bug → เขียน test ที่ reproduce bug ก่อน แล้วค่อยแก้ให้ผ่าน

---

## คำสั่งที่ใช้บ่อย

```bash
composer install            # ติดตั้ง dependency

# หลัง setup test แล้วเท่านั้น (ดูหัวข้อ "การเทสต์"):
composer test                          # รันเทสต์ทั้งหมด
vendor/bin/phpunit --testsuite Unit    # รันเฉพาะ unit (เร็ว ไม่ต้อง DB)
```

## อย่าทำ

- อย่าใส่ business logic ใน Repository หรือ Page/API
- อย่า throw exception สำหรับ validation error (ใช้ result-array)
- อย่าเขียน SQL ด้วยการ concat ค่าจาก user
- อย่าสร้าง helper/guard ใหม่ถ้ามีใน `includes/` อยู่แล้ว
- อย่าให้ integration test แตะ database จริง (ใช้ test DB เท่านั้น)