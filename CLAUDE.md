# CLAUDE.md — Ad-Profit

คู่มือสำหรับ Claude Code เมื่อทำงานกับ repo นี้ อ่านก่อนเขียน/แก้โค้ดทุกครั้ง

---

## 🧭 เริ่มที่นี่ (สรุปสั้นสุดสำหรับเซสชันใหม่)

> ไฟล์นี้ยาว ~33,000 ตัวอักษร — อ่านทั้งไฟล์ได้ · **บทเรียนละเอียดแยกไปอยู่ `docs/LESSONS-*.md`**

| # | สิ่งที่ต้องรู้ทันที |
|---|---|
| 1 | **PHP: เว็บต้อง ≥ 8.3 · ชุดเทสต์ต้อง ≥ 8.4.1** · PHP ของ XAMPP (8.2.4) ใช้ไม่ได้ทั้งคู่ |
| 2 | **`composer test` ต้องเขียวก่อนถือว่างานจบ** (~5 นาที · 1,400+ เทสต์) · `composer stan` ต้อง clean |
| 3 | **เทสต์เขียวไม่ได้แปลว่าถูก** — ต้องมิวเทชัน (ทำให้โค้ดพัง) แล้วดูว่าเทสต์แดงจริงไหม **ทุกครั้ง** |
| 4 | **ห้ามรัน `database/schema.sql` ทับ DB ที่มีข้อมูล** — เป็น DROP + CREATE · ใช้ `database/migrations/` แทน |
| 5 | **รากโปรเจกต์ = `public_html`** ทุกไฟล์เสิร์ฟผ่านเว็บโดยปริยาย · `.htaccess` 9 ไฟล์คือตัวกันเดียวที่มี |
| 6 | **ข้อความที่ผู้ใช้เห็นเป็นภาษาไทย · log เป็นอังกฤษ** · Service คืน result-array ไม่ throw |

**เอกสารอื่น — เปิดเมื่อต้องใช้ ไม่ต้องอ่านล่วงหน้า:**

| ไฟล์ | เปิดเมื่อ |
|---|---|
| `docs/DEVELOP.md` | เตรียมเครื่องหลัง clone · รันเทสต์ · ฐานข้อมูลทดสอบ |
| `docs/HOSTINGER.md` | จะขึ้นเซิร์ฟเวอร์จริง |
| `docs/manual-checks.md` | สิ่งที่ระบบทดสอบแทนไม่ได้ (วางจาก Excel · อีเมลจริง) |
| `docs/WHERE_TO_EDIT.md` | จะแก้เรื่องนี้ต้องไปแก้ไฟล์ไหน |

**⚠️ บทเรียนจากบั๊กจริง — แยกเป็น 3 ไฟล์ เปิดก่อนแก้เรื่องนั้นเสมอ:**

| ไฟล์ | ขนาด | เปิดก่อนแก้ |
|---|---|---|
| `docs/LESSONS-LOGIC.md` | ~73,000 | **สูตร · การเทียบช่วงเวลา · การปัดเศษ · รายงาน/Excel/CSV · JS หน้าบันทึก** |
| `docs/LESSONS-STRUCTURE.md` | ~33,000 | ฐานข้อมูล · session · ตัวจำกัดจำนวนครั้ง · token · นำเข้า CSV · ล็อก |
| `docs/LESSONS-UI.md` | ~32,000 | ตาราง · ปุ่ม · สี · ฟอร์ม · หน้าต่างซ้อน · ข้อความบนจอ |

> เดิมทั้งหมดนี้อยู่ใน `CLAUDE.md` ไฟล์เดียว (~166,000 ตัวอักษร) ซึ่งกินพื้นที่ความจำของ AI
> ไปราว 83% ก่อนเริ่มทำงานเลย · แยกออกมาแล้ว **เนื้อหาครบเท่าเดิมทุกบรรทัด**
> หัวข้อที่ย้ายออกไปยังเหลือ "สรุปข้อที่ห้ามพลาด" ไว้ในไฟล์นี้

**เครื่องมือตรวจใน `tools/` (รันจากบรรทัดคำสั่งเท่านั้น · อ่านอย่างเดียว ไม่แก้อะไร):**

| คำสั่ง | ตอบคำถามอะไร |
|---|---|
| `php tools/check-schema.php` | ฐานข้อมูลที่มีข้อมูลอยู่ ต้องรัน migration ตัวไหนบ้าง |
| `php tools/check-deploy.php` | เซิร์ฟเวอร์พร้อมใช้งานหรือยัง (PHP · ส่วนเสริม · `.env` · ตาราง) |
| `php tools/check-live.php <url>` | ไฟล์ลับ (`.env` · `.git/`) หลุดผ่านเว็บไหม — **ต้องรันทุกครั้งหลัง deploy** |

**วิธีทำงานที่โปรเจกต์นี้ใช้:** บันทึกบั๊กทุกตัวที่เคยเจอไว้ในไฟล์นี้ **พร้อมเหตุผลและตัวเลขที่วัดได้จริง**
ไม่ใช่แค่ "แก้แล้ว" — เพราะรูปแบบความผิดพลาดซ้ำเดิมเกิดขึ้นบ่อยมาก (ดูหัวข้อถัด ๆ ไป
จะเห็นคำว่า "ตกสำรวจ" "พลาดซ้ำรอบที่สอง" อยู่เต็มไปหมด)

---

## โปรเจกต์คืออะไร

ระบบบันทึกรายได้/ค่าโฆษณา/กำไรรายวัน แยกตามร้าน (multi-shop) — **PHP แบบไฟล์ล้วน ไม่มี framework** DB เป็น **MySQL/InnoDB** ฝั่งหน้าเว็บ render แบบ server-side (Chart.js + Tailwind ผ่าน CDN, ไม่มี build step)

PHP ที่รองรับ: **≥ 8.3** — **enforce ใน composer.json แล้ว** (`"require": { "php": ">=8.3" }`) ทุกไฟล์มี `declare(strict_types=1)` · เซิร์ฟเวอร์ production = Hostinger PHP 8.3

⚠️⚠️ **เพดานล่างคือ 8.3 ไม่ใช่ 8.2 — และเคยประกาศผิดมาก่อน** ตัวที่บังคับคือ `maennchen/zipstream-php 3.2.2` ที่ `phpoffice/phpspreadsheet` ลากมา ซึ่ง require `php-64bit ^8.3`
· ⚠️ มันประกาศไว้ใต้คีย์ **`php-64bit`** ไม่ใช่ `php` — อ่าน `composer.lock` เผิน ๆ จะมองไม่เห็น ต้องลองติดตั้งจริงถึงเจอ
· ⚠️ **CI job เดิม (`lint-php82`) เขียวตลอดทั้งที่ติดตั้งบน 8.2 ไม่ได้เลย** เพราะมันรันแค่ `php -l` ไม่เคยติดตั้ง dependency · วัดจริงด้วย `composer install --no-dev` บน PHP 8.2.4 → ล้มทันที ไม่มี vendor = เว็บเปิดไม่ขึ้นทั้งเว็บ
· ถ้าวันหนึ่งต้องรองรับ 8.2 จริง ต้องถอย zipstream เป็น 2.x ก่อน

⚠️ **extension ที่ host ต้องมี** (นอกจาก pdo_mysql/mbstring ตามปกติ): **`zip`, `gd`** — `phpoffice/phpspreadsheet` ประกาศเป็น hard requirement ถ้าขาด `composer install` ล้มตั้งแต่แรก (CI ทั้ง 2 jobs ที่ `composer install` ใส่ `zip, gd` ใน `setup-php` แล้ว)

⚠️ **ช่องว่างเรื่องเวอร์ชันที่ต้องรู้ (อย่าเข้าใจผิดว่าเทสต์ครอบ 8.3):**
- **test suite รันได้บน PHP 8.4+ เท่านั้น** — `phpunit/phpunit 13.x` require `php >= 8.4.1` → บน 8.3 `composer install` (dev) ไม่ผ่านตั้งแต่แรก
- ความเข้ากันได้กับ **8.3 พิสูจน์แค่ระดับ syntax** (`php -l`) ผ่าน CI job `lint-php83` + job `prod-install` ที่ลง dependency ชุด production บน 8.3 จริง — **ไม่ได้พิสูจน์ runtime behavior ของโค้ดเราเองบน 8.3** (คือเวอร์ชันที่ production ใช้จริง)
- ✅ **ปิดช่องว่างนี้แล้วด้วย smoke test** — `tests/smoke/pages.php` เขียนแบบไม่พึ่ง PHPUnit เลย จึงรันบน 8.3 ได้ · CI job `smoke-php83` ยกเซิร์ฟเวอร์จริง สมัครสมาชิก บันทึกข้อมูล แล้วเปิดทุกหน้า/ทุก endpoint/ไฟล์ดาวน์โหลด (21 รายการ) ดูว่ามีคำเตือนของ PHP หลุดออกมาไหม
  · ⚠️ **ต้องรันเซิร์ฟเวอร์ด้วย `APP_ENV=development`** ไม่งั้น `display_errors` ปิดอยู่ ตัวตรวจจะเขียวโดยไม่ได้ตรวจอะไรเลย (บทเรียนเดียวกับ `PageRenderTest`)
  · ⚠️ **ต้อง `strip_tags()` ก่อนหาคำว่า Warning** — PHP ห่อไว้เป็น `<b>Warning</b>:`
  · ⚠️ **ตัวตรวจต้องไม่ปล่อยคำเตือนของตัวเอง** — เวอร์ชันแรกเรียก `curl_close()` ซึ่งเลิกใช้ใน 8.5 คำเตือนของตัวเองไปปนกับสิ่งที่กำลังตามหา
  · มิวเทชันแล้ว: ใส่ตัวแปรที่ไม่มีจริงลง `dashboard.php` → จับได้ 5 หน้า
- ถ้าต้องการ **รันชุดเทสต์เต็ม** บน 8.3 ด้วย ต้อง downgrade PHPUnit (เช่น ^10) — **ยังไม่ทำ** เพราะ smoke test ครอบเส้นทางที่ผู้ใช้เดินจริงแล้ว

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

## การเข้าถึงผ่านเว็บ (สำคัญมาก)

- ⚠️⚠️ **รากโปรเจกต์ = `public_html/` บน Hostinger** ทุกไฟล์จึงเสิร์ฟผ่านเว็บโดยปริยาย · เดิม **ไม่มี `.htaccess` เลยสักไฟล์** → `https://โดเมน/.env` ให้รหัสฐานข้อมูลกับรหัสอีเมลไปตรง ๆ (พิสูจน์แล้วว่า `database/schema.sql` · `composer.json` · `CLAUDE.md` โหลดได้จริง)
  · ตัวกันหลักอยู่ใน **`.htaccess` ที่ราก** — ปิดไฟล์ที่ขึ้นต้นด้วยจุด · ปิดตามนามสกุล (`env sql json lock md log …`) · ปิดโฟลเดอร์ภายในทั้งหมดด้วย `RewriteRule`
  · ⚠️ **ห้ามพึ่ง `.htaccess` ในแต่ละโฟลเดอร์อย่างเดียว** — `vendor/` `logs/` `uploads/` ถูก .gitignore ไฟล์ข้างในจึงอาจไม่ขึ้นเซิร์ฟเวอร์ (`.gitignore` มี `!logs/.htaccess` / `!uploads/.htaccess` กันไว้แล้ว แต่ `vendor/` ยังพึ่งกฎที่รากอย่างเดียว)
  · ⚠️ **โปรเจกต์นี้ไม่มีไฟล์ static ที่หน้าเว็บต้องโหลดเลย** (Tailwind/Chart.js มาจาก CDN) การปิด `.json`/`.xml` จึงปลอดภัย — ถ้าอนาคตเพิ่มไฟล์ที่เบราว์เซอร์ต้องโหลด ต้องยกเว้นในกฎ
  · ⚠️⚠️⚠️ **`.git/` ต้องอยู่ในกฎปิดโฟลเดอร์ด้วย — deploy ด้วย `git clone` แล้วมันขึ้นไปด้วย**
    · กฎ `<FilesMatch "^\.">` **กันไม่ได้** เพราะมันดูที่ *ชื่อไฟล์* แต่ไฟล์ข้างใน `.git/`
      ชื่อ `config` · `HEAD` · `index` ซึ่งไม่มีจุดนำหน้า
    · วัดจริงกับ Apache ก่อนเพิ่มกฎ: `/.git/config` และ `/.git/HEAD` ตอบ **200**
      = ใครก็ได้ดาวน์โหลดประวัติซอร์สโค้ดทั้งหมด รวมความลับที่เคยเผลอ commit ไว้
    · เป็นเป้าที่เครื่องมือสแกนอัตโนมัติไล่หาเป็นอย่างแรก ๆ
    · `ApacheErrorRoutingTest` + `tools/check-live.php` ตรวจทั้งคู่แล้ว
  · `WebExposureTest` กวาดโฟลเดอร์จริงมาเทียบกับกฎ เพิ่มโฟลเดอร์ใหม่แล้วลืมปิด = เทสต์แดงทันที
  · ⚠️ **เทสต์ตรวจได้แค่ "กฎในไฟล์" ไม่ใช่พฤติกรรมจริง** (`php -S` ไม่อ่าน `.htaccess` · nginx ก็ไม่อ่าน) — **หลัง deploy ต้องเปิด `https://โดเมน/.env` ดูด้วยตาว่าได้ 403**

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

> 📖 **รายละเอียดทั้งหมด → [`docs/LESSONS-STRUCTURE.md`](docs/LESSONS-STRUCTURE.md)** (~33,000 ตัวอักษร)
> **เปิดก่อนแก้:** ชั้น response · ฐานข้อมูล/schema · session · ตัวจำกัดจำนวนครั้ง ·
> ลิงก์รีเซ็ตรหัสผ่าน/เปลี่ยนอีเมล · นำเข้าไฟล์ CSV · transaction และการล็อกแถว

**ต้องรู้ไว้ก่อนแม้ยังไม่เปิดไฟล์:**

- **Response layer:** controller ตอบผ่าน `api_respond()` (เลือก JSON หรือ redirect+flash อัตโนมัติ) ·
  Service คืน result-array · controller เป็นคนแปลงเป็น response
- ⚠️ **ห้ามใช้ `PDO::MYSQL_ATTR_INIT_COMMAND`** — เลิกใช้ใน PHP 8.5 คำเตือนหลุดกลางหน้าเว็บ
  ใช้ `SET SESSION sql_mode` เป็น query ธรรมดาหลังต่อแทน
- ⚠️ **`database/schema.sql` เป็น DROP + CREATE และฝังชื่อ DB จริงไว้** — ห้ามรันทับ DB ที่มีข้อมูล
  ใช้ `database/migrations/` แทน · `php tools/check-schema.php` บอกได้ว่าต้องรันตัวไหน
- ⚠️ **แก้ schema ต้องอัปเดต Schema Guard ด้วย** (ตาราง · คอลัมน์ · ชนิด · index · collation · engine · FK)
- ⚠️ **ตัวจำกัดจำนวนครั้งต้อง "จองก่อนตรวจ"** ไม่ใช่ "ถามแล้วค่อยนับ" —
  วัดจริง: เพดาน 5 แต่ยิงพร้อมกัน 40 ผ่านเข้าไป 28
- ⚠️ **ลำดับล็อก `users` ก่อนเสมอ** แล้วค่อย `password_reset_tokens`/`email_change_requests` ไม่งั้น deadlock
- ⚠️ **ทุกช่องที่ผู้ใช้พิมพ์ใช้ `trim_unicode_whitespace()`** ไม่ใช่ `trim()` (ยกเว้นรหัสผ่าน — ห้ามตัด)
- ⚠️ **หน้าที่รับ token ห้ามเปลี่ยนสถานะด้วย GET** — ตัวสแกนลิงก์ในอีเมลจะกดให้เองก่อนผู้ใช้

## หน้าตา/การใช้งาน (UI) — กติกาที่ต้องรักษา

> 📖 **รายละเอียดทั้งหมด → [`docs/LESSONS-UI.md`](docs/LESSONS-UI.md)** (~32,000 ตัวอักษร)
> **เปิดก่อนแก้:** ตาราง · ปุ่ม · สี · ฟอร์ม · หน้าต่างซ้อน · ข้อความบนจอ · การแสดงตัวเลข
>
> ⚠️ ทุกข้อในนั้นมาจากการ **วัดบนหน้าจอจริง** (ยกเซิร์ฟเวอร์ + ข้อมูลเสมือนจริง แล้ววัดพิกเซล/คอนทราสต์)
> — ตรวจ UI จากมาร์กอัปอย่างเดียวมองไม่เห็นปัญหาพวกนั้นเลยสักข้อ

**ต้องรู้ไว้ก่อนแม้ยังไม่เปิดไฟล์:**

- ⚠️ **ตารางทุกตัวต้องมีคลาส `table-cards`** — จอ < 1024px แถวกลายเป็นการ์ด
  (ยกเว้นตารางกรอกหลายวันกับกริดฤดูกาล ซึ่ง **ตั้งใจไม่แปลง** มีเหตุผลเขียนไว้ในไฟล์)
- ⚠️ **ช่องที่ผู้ใช้พิมพ์เองต้องมีเพดานความกว้าง + ตัดบรรทัดได้** (`break-words lg:max-w-[16rem]`)
  ไทยไม่มีเว้นวรรค ช่องจะกว้างจนดันคอลัมน์อื่นออกนอกจอ
- ⚠️ **สีปุ่มอยู่ที่ `includes/brand-colors.php` ที่เดียว** ห้ามเขียนเลขสีเอง
  (`error.php` ตั้งใจไม่ include แต่ต้องใช้ค่าชุดเดียวกัน)
- ⚠️ **ห้ามใช้ `text-slate-500/600/700`** คอนทราสต์ไม่ผ่าน · **วงโฟกัสต้องเป็น `outline` สีทึบ**
- ⚠️ **"ยังไม่เคยกรอก" ≠ "ทำได้ ฿0"** — ต้องเป็นขีด ไม่ใช่เลขศูนย์ ทุกหน้า ทุกไฟล์
- ⚠️ **หน้าต่างซ้อนต้องเรียก `setupAccessibleModal()`** ใน `includes/header.php` เท่านั้น
- ⚠️ **ช่อง `<input type="month">` ต้องมี `data-thai-month-for` กำกับทุกช่อง**
- ⚠️ **เลขที่ผู้ใช้กรอกเรียกว่า "รายได้" คำเดียวทั้งระบบ**

## Logic ที่อยู่ที่ controller/view (ไม่ใช่ service)

> 📖 **รายละเอียดทั้งหมด → [`docs/LESSONS-LOGIC.md`](docs/LESSONS-LOGIC.md)** (~73,000 ตัวอักษร — ยาวที่สุด)
> **เปิดก่อนแก้:** สูตรคำนวณ · การเทียบช่วงเวลา · การปัดเศษ · รายงาน/ไฟล์ Excel/CSV ·
> JavaScript ในหน้าบันทึก · เป้าหมาย · ประมาณการสิ้นปี
>
> ⚠️ **นี่คือหัวใจของแอป** — เจ้าของร้านเอาตัวเลขไปตัดสินใจธุรกิจ
> บั๊กที่นี่คือ "ตัดสินใจผิดด้วยความมั่นใจ" ซึ่งอันตรายกว่าไม่มีเลข

**ต้องรู้ไว้ก่อนแม้ยังไม่เปิดไฟล์:**

- ⚠️ **ยอดที่บวก/ลบในภาษา PHP ต้องผ่าน `money_total()`** (ปัดสตางค์ครั้งเดียวตอนจบ)
  ไม่งั้นหน้าที่บวกเองกับหน้าที่ใช้ `SUM()` ของ MySQL ได้คนละค่า
- ⚠️ **`change_percent()` หารด้วย `abs($previous)` และคืน null เมื่อฐานเป็นศูนย์เป๊ะ**
  ห้ามใช้เกณฑ์ที่ผูกกับหน่วยเงิน — ฟังก์ชันนี้ถูกเรียกด้วย ROAS ซึ่งเป็นอัตราส่วน
- ⚠️ **ROAS รวมหลายวันคิดแบบ Σรายได้ ÷ Σค่าแอด** ไม่ใช่เฉลี่ยของ ROAS รายวัน
- ⚠️ **"เดือนนี้" ตัดที่วันนี้ทุกจุด** ผ่าน `resolve_comparison_cutoff_day()` + `comparison_range_end()`
  · เทียบเดือนก่อนใช้ `resolve_month_over_month_cutoff_day()` — **คนละตัว ห้ามสลับ**
- ⚠️ **เดือนที่ยังไม่จบตัดสินไม่ได้** — ห้ามเข้าการ์ด "ดีสุด/แย่สุด" และห้ามระบายสีกริดฤดูกาล
- ⚠️ **กติกาของรายงานต้องลงถึงไฟล์ Excel/CSV ด้วยเสมอ ไม่ใช่แค่หน้าจอ**
- ⚠️ **ห้ามเขียนกติกาซ้ำ** — มี helper กลางแล้ว (`month_is_unfinished()` ·
  `compare_shop_rows_for_ranking()` · `extremes_are_comparable()` · `distribute_profit_share()` ฯลฯ)
  และมีตัวกวาดในเทสต์ห้ามเขียนซ้ำ
- ⚠️ **JavaScript ในหน้าบันทึกไม่มีตัวรันเทสต์** — แก้แล้วต้องเปิดเบราว์เซอร์ลองเอง

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
| ชั้น controller (`api/*.php`) — ด่านตรวจ (auth/405/415/CSRF/409) | ครอบแล้ว (`EndpointGuardChainTest`, `RecordEndpointGuardTest`) |
| **ทางเขียนจริง → รายงาน (lineage)** | ครอบแล้ว (`ReportingWritePathLineageTest`) — ยิง endpoint จริงทั้ง 3 ทาง + เป้าหมาย แล้วไล่ต่อถึงฐานข้อมูล · หน้ารายปี · ไฟล์ CSV ⚠️ reconciliation test ตัวอื่น **INSERT ตรงเข้าฐานข้อมูล** จึงข้ามโฟลว์จริงไปทั้งเส้น |
| **หน้าหนึ่งเทียบอีกหน้าหนึ่ง (cross-report)** | ครอบแล้ว (`CrossReportDimensionParityTest`) — ถามเดือนเดียวกันผ่านแดชบอร์ด/รายปี/ประวัติ/รวมร้าน แล้วเทียบกับ SQL ตรง ๆ |
| **ไฟล์ CSV ที่มีโน้ตจุลภาค/ขึ้นบรรทัด** | ครอบแล้ว (`CsvArtifactRoundTripTest`) — อ่านกลับด้วย `fgetcsv()` จาก stream จริง ⚠️ helper เดิมหั่นบรรทัดเองก่อน parse จึงพิสูจน์เรื่องนี้ไม่ได้เลย |
| ชั้น controller — ตรรกะเฉพาะของแต่ละ action | ครอบแล้วทุกไฟล์: `records.php` ทุกคำสั่งรวมนำเข้า CSV (`RecordActionEndpointTest`) · `shops.php` (`ShopEndpointTest`) · `goals.php`/`profile.php` (`GoalAndProfileEndpointTest`) · `auth.php` รวม forgot/reset (`AuthEndpointTest`) · `*-data.php` (`DataEndpointParityTest`) |
| Session หมดอายุ / เตะเครื่องอื่นออก | ครอบแล้ว (`SessionLifetimeTest`) |
| ไฟล์ที่ดาวน์โหลดจริง (CSV กันสูตร Excel, BOM, xlsx) | ครอบแล้ว (`ExportEndpointTest`) |
| ชั้นเพจ — เปิดขึ้นจริง, กันคนไม่ล็อกอิน, ไม่โกหก ฿0, หดช่วงอนาคต | ครอบแล้ว (`PageRenderTest`) |
| JS ทุกหน้า — parse ผ่านไหม + เรียกฟังก์ชันที่ไม่มีนิยามไหม | ครอบแล้ว (`BrowserScriptParityTest` — 8 หน้า + header/footer) ⚠️ `php -l` บอก "ไม่มี error" แม้ JS ในหน้านั้นพังทั้งบล็อก |
| JS ที่ต้องตรงกับ PHP (อ่านจำนวนเงิน / วันที่กำกวม / แยกหัวตาราง) | ครอบแล้ว (ดึงฟังก์ชันจาก `add-record.php` ไปรันด้วย node แล้วเทียบกับกติกา PHP ตัวจริง) |
| **JS ส่วนที่ต้องมี DOM** (วางจาก Excel ทั้งกระบวนการ, เติมค่าเมื่อเปลี่ยนวัน, month-grid, typed-confirm) | **ยังต้อง verify มือ** — ไม่มี DOM ในเทสต์ |
| **EmailService การส่งจริง** | กฎ "ตั้งค่าครบไหม" ครอบด้วยพฤติกรรมแล้ว (constructor รับค่าแทนที่ได้) — **การส่งถึงกล่องจดหมายจริงยังต้อง verify มือ** |

⚠️ **รายการที่ต้องลองด้วยมือ อยู่ที่ [docs/manual-checks.md](docs/manual-checks.md)** — การวางจาก Excel (ต้องมีคลิปบอร์ดจริง) และอีเมลรีเซ็ตรหัสผ่าน (ต้องมีกล่องจดหมายจริง) · ทั้งสองเรื่องระบบทดสอบแทนไม่ได้ และการวางเคยพังแบบเงียบสนิทมาแล้วโดยที่เทสต์เขียวหมด

**JS ส่วนที่เหลือยังไม่มีตาข่าย** — logic ที่อยู่ตรงนั้นให้ย้ายลง service/helper ก่อนแก้
(มีตัวอย่างในหัวข้อด้านบน) หรือถ้าย้ายไม่ได้ ให้ verify ด้วยเบราว์เซอร์จริง

- ⚠️ **เทสต์ที่เขียวไม่ได้แปลว่าโค้ดถูก — ต้องลองทำให้โค้ดพังแล้วดูว่าแดงไหมทุกครั้ง** กับดักที่เจอมาแล้วจริงในโปรเจกต์นี้:
  · **เทสต์ concurrency ไปจองล็อกเองด้วยวิธีที่เข้มกว่าโค้ด** → เขียวแม้โค้ดใช้ล็อกที่กันอะไรไม่ได้ (ต้องจองผ่านเมธอดเดียวกับที่โค้ดใช้)
  · **`assertNotSame(200, …)`** → 404 "ไม่รู้จักคำสั่ง" ก็ผ่าน ทั้งที่ยังไม่ถึงด่านที่จะตรวจ (ต้องส่ง payload ที่ใช้ได้จริงแล้วยืนยันรหัสสถานะเป๊ะ ๆ)
  · **`assertContains($status, [302,401,403])`** → ถอด `requireAuth()` ออกก็ยังผ่าน เพราะด่าน CSRF ตอบรหัสเดียวกัน (ต้องดู `Location` ว่าไป `login.php` ไหม)
  · **`value="…" selected`** กับ `<input type="month">` → คำว่า selected ไม่มีวันปรากฏ เทสต์จึงไม่ได้ตรวจอะไร (ต้องยืนยันทั้ง "ค่าอนาคตไม่มี" และ "ค่าที่หดแล้วมี")
  · **หา `"Warning:"` ในหน้า HTML** → PHP ห่อเป็น `<b>Warning</b>:` ต้อง `strip_tags()` ก่อน · และเซิร์ฟเวอร์ทดสอบต้องรันด้วย `APP_ENV=development` ไม่งั้น `display_errors` ปิดอยู่ ไม่มีอะไรให้ตรวจตั้งแต่แรก
  · **`markTestSkipped` ตอนยกเซิร์ฟเวอร์ไม่ขึ้น** → เทสต์ทั้งชั้นหายไปโดย CI ยังเขียว (ต้อง `fail()` เมื่อ DB พร้อมแล้วแต่เซิร์ฟเวอร์ไม่ขึ้น)
  · **สถานะ 0 จากคำขอที่ไปไม่ถึงเซิร์ฟเวอร์** → ผ่านทุก assert ที่เขียนว่า "ไม่ใช่ 200"

- ⚠️ **เทสต์ต้องเรียก "กติกาตัวจริง" ไม่ใช่เขียนกติกาซ้ำไว้ในเทสต์** — `BrowserScriptParityTest` ดึงฟังก์ชัน JS จาก `add-record.php` และเรียก `RecordService::parseImportDate()` + `isAmbiguousSlashDate()` ผ่าน Reflection (สองตัวรวมกัน เพราะ `parseImportCsv()` ก็ทำแบบนั้น) · เคยเขียนกติกาซ้ำไว้ในเทสต์แล้วกลายเป็นสำเนาที่ 3 ให้เพี้ยน และเคยใช้ตัวเดียวจนเทสต์ **ผ่านแม้ทำให้ JS คืน null ทุกกรณี**
- ⚠️ **เทสต์อัปโหลดไฟล์ต้องรันเซิร์ฟเวอร์ด้วย `upload_max_filesize` ที่สูงกว่าเพดานของแอป** — เพดานของแอปคือ 2MB (`api/records.php`) ถ้า php.ini ตั้งไว้ 2M พอดี PHP จะปฏิเสธก่อน ด่านของแอปไม่มีวันทำงาน แล้วเทสต์ที่ตั้งชื่อว่าตรวจด่านนั้นจะผ่านด้วยข้อความคนละอัน (เซิร์ฟเวอร์จริงตั้งไว้สูงกว่า ด่านของแอปจึงเป็นตัวจริงบน production)
- ⚠️ **ด่านตรวจสิทธิ์ของ `ExportService` (CSV) เทสต์ที่ endpoint ไม่ได้** — `api/export.php` เรียก `resolve_current_shop_id()` ซึ่งซ่อม session กลับไปร้านของเจ้าตัวก่อนเสมอ คำขอจึงไม่มีวันเดินมาถึง · ต้องเทสต์ที่ชั้น Service (stub `findByIdAndUserId` → null) เหมือน `ExportServiceXlsxTest`
- ⚠️ **`startSession()` ในเทสต์อ่านอีเมลจริงของผู้ใช้จาก DB** — ไม่ใช่ค่าคงที่ เพราะบางหน้าเทียบอีเมลใน session กับข้อมูลอื่น (หน้าตั้งรหัสใหม่เตือนเมื่อลิงก์เป็นของคนละบัญชี) ค่าคงที่ทำให้เทสต์เรื่องนั้นเขียนไม่ได้เลย

- ⚠️ **เทสต์ที่เทียบ "สองทางต้องตอบเหมือนกัน" ต้องเลือกค่าที่แยกกันได้จริง** — กับดักที่เจอมาแล้ว:
  · เทียบ `?month=` (ไม่มี range) กับ `?range=month_pick&month=…` แล้วดู `range.type` → ค่านั้นสะท้อน `range` ตรง ๆ อยู่แล้ว เป็นจริงโดยโครงสร้าง
  · ใช้ปี พ.ศ. **ของปีปัจจุบัน** ทดสอบการแปลงปี → ถอดการแปลงออกแล้วค่าตกนอกช่วง 2000–2100 กลับไปใช้ปีปัจจุบันพอดี ได้คำตอบเดียวกันโดยบังเอิญ (ต้องใช้ปีในอดีต เช่น 2565 → 2022)
  · **ที่พิสูจน์แล้วว่าแยกไม่ได้จริง ๆ:** "`month=` ว่าง" กับ "`month=` เดือนปัจจุบัน" ให้ผลเหมือนกันทุกไบต์ทั้งบนหน้าเว็บและใน endpoint — สิ่งที่ตรวจได้คือ "สองไฟล์ตอบตรงกันไหม" (`testThePageAndTheEndpointNeverDisagree` ไล่ query 9 แบบ)
- ⚠️ **`startSession()` ของเทสต์ต้องมีคีย์ชุดเดียวกับ `AuthService::establishSession()`** — เทสต์ชั้น controller ทุกตัวใช้ session ที่สร้างเอง ถ้าคีย์ไม่ตรง ทั้งชั้นจะรันอยู่บน session ที่ไม่มีวันเกิดขึ้นจริง · `AuthEndpointTest::testTheTestSessionHasTheSameShapeAsARealLogin()` ล็อกไว้ (ล็อกอินจริงแล้วเทียบคีย์) ⚠️ ต้องหาไฟล์ session ที่ "เพิ่งเกิดใหม่" เพราะล็อกอินสำเร็จเรียก `session_regenerate_id` — ค้นด้วย user_id จะไปเจอไฟล์ของเทสต์ก่อนหน้า

- ⚠️ **ตัวปักคีย์ session ต้องล็อกอินจาก session ที่ยังไม่มีคีย์ auth เลย** (`startBlankSession()`) — `session_regenerate_id(true)` ยก `$_SESSION` เดิมข้ามไปไฟล์ใหม่ทั้งก้อน ถ้าเริ่มจาก session ที่เทสต์เขียนคีย์ครบไว้แล้ว ไฟล์หลังล็อกอินจะมีคีย์ครบเสมอ **ไม่ว่าแอปจะเขียนอะไรจริง ๆ** (เทสต์เคยเขียวแม้ตัด `establishSession()` เหลือคีย์เดียว) · และต้องยืนยัน "รายชื่อคีย์ที่คาดหวัง" แบบตายตัวด้วย กันกรณีสองฝั่งขาดเหมือนกัน
- ⚠️ **ตัวตรวจ JS ต้องดึงหน้าจากเซิร์ฟเวอร์จริง ไม่ใช่อ่านไฟล์ดิบ** — โค้ดที่ PHP แทรก (`<?= json_encode(...) ?>`) เป็นส่วนหนึ่งของ JS ที่เบราว์เซอร์ได้รับ · เวอร์ชันแรกอ่านไฟล์แล้วแทน `<?= … ?>` ด้วย `null` จึงไม่มีวันจับได้ว่า PHP ปล่อย JS ผิดไวยากรณ์ออกมา ทั้งที่ docblock อ้างว่าครอบ
- ⚠️ **ตัดคอมเมนต์ก่อนตัดสตริงเสมอ** ตอนสแกนโค้ด JS — คอมเมนต์ที่มี `'` อยู่ข้างในจะจับคู่กับเครื่องหมายคำพูดตัวถัดไปในโค้ดจริง แล้วลบทุกอย่างระหว่างกลางทิ้ง โค้ดช่วงนั้นหายจากการตรวจโดยไม่มีอะไรบอก
- ⚠️ **ไฟล์ที่ฝัง `<script>` ทุกไฟล์ต้องอยู่ใน `scriptedPageProvider()`** — `testEveryFileWithInlineScriptIsChecked()` กวาดเทียบให้ (แบบเดียวกับ `testEveryApiFileIsAccountedFor` ของฝั่ง `api/`) เดิมรายชื่อเป็นค่าตายตัว เพิ่มสคริปต์ลงหน้าที่ไม่อยู่ในรายชื่อแล้วมันพังยังไงก็ไม่มีใครรู้
- ⚠️ **ห้ามทิ้งค่าที่ `GET_LOCK` คืนมา** — คืน `0` เมื่อรอจนหมดเวลา และ `NULL` เมื่อผิดพลาด ทิ้งไว้เฉย ๆ = ชุดเทสต์เดินต่อโดยไม่มีล็อก ซึ่งคือปัญหาเดิมที่ตั้งใจแก้พอดี · ตอนนี้ skip พร้อมบอกเหตุผล

- ⚠️ **ค่าตัวเลขจาก env ต้องผ่าน `config_positive_int()` ทุกตัว ไม่มีข้อยกเว้น** — เคยมี 5 ตัวที่ข้ามไปใช้ `(int)(getenv() ?: default)` ซึ่ง `(int)"eight"` = 0 และ `(int)"-5"` = -5 ผ่านเข้าไปเงียบ ๆ · ผลจริงที่วัดได้: พิมพ์ `PASSWORD_MIN_LENGTH=eight` ผิดตัวเดียว **ความยาวรหัสผ่านขั้นต่ำเหลือ 4** สำหรับทั้งการสมัคร รีเซ็ต และเปลี่ยนรหัส โดยไม่มี log อะไรเลย · `ConfigEnvironmentTest` ล็อกไว้แล้ว
- ⚠️ **`SCHEMA_GUARD_ENABLED` ห้ามอ่านด้วย `getenv() ?: 'true'`** — `"0"` เป็น falsy จึงกลายเป็น `'true'` (เปิดการ์ด) ขณะที่ค่าขยะอย่าง `"disabled"` กลายเป็น false (ปิดการ์ดเงียบ ๆ) = **กลับหัวกับที่ผู้ตั้งค่าคาดหวังทั้งสองทาง** · ตอนนี้ค่าขยะ = เปิดไว้ + เขียน log
- ⚠️ **ข้อความ TTL ในอีเมลรีเซ็ตต้องผ่าน `max(1, …)` เหมือนที่ repository ทำ** — ไม่งั้นอีเมลบอกอายุลิงก์คนละค่ากับที่ระบบใช้จริง
- ⚠️ **เทสต์ล็อกอินต้อง "ใช้งานต่อ" ด้วย session ที่ได้มา ไม่ใช่ดูแค่ปลายทางของ redirect** — เคยพิสูจน์แล้วว่าเปลี่ยน `auth_started_at` เป็น `0` ทำให้ผู้ใช้หลุดทันทีในคำขอถัดไป (นับว่าหมดอายุ) **โดยที่เทสต์ทั้ง 933 ตัวยังเขียว** เพราะไม่มีใครแตะ session ที่การล็อกอินสร้าง · `ControllerTestCase::sessionIdFrom()` ดึง id ใหม่จาก `Set-Cookie` มาให้
- ⚠️ **ตัดคอมเมนต์/สตริงออกจาก JS ต้องเดินทีละตัวอักษร ห้ามใช้ regex สองชั้น** — ตัดสตริงก่อน คอมเมนต์ที่มี `'` จะกลืนโค้ด · ตัดคอมเมนต์ก่อน สตริงที่มี `//` จะกลืนโค้ด **ทั้งสองลำดับพังคนละทาง** (พิสูจน์ทั้งคู่แล้ว) → `stripCommentsAndStrings()`
- ⚠️ **หน้าที่ฝัง `<script>` ต้องอยู่ใน *ทั้งสอง* รายชื่อ** — `scriptedPageProvider` (เรียกฟังก์ชันที่ไม่มีนิยาม) และ `renderedPageProvider` (parse ผ่านไหม) · ลงทะเบียนแค่รายชื่อเดียวแล้วอีกด้านยังบอด · `testEveryFileWithInlineScriptIsChecked()` เช็กทั้งคู่

### เทสต์ชั้นหน้าเว็บ (controller + page) — `ControllerTestCase`

`api/*.php` เรียก `includes/bootstrap.php` (session_start + security header + `db()` + schema
guard) จึง `require` เข้ามาเรียกใน process ของ PHPUnit ไม่ได้ · `tests/Integration/ControllerTestCase.php`
จึง **ยก `php -S` ขึ้นมา 1 ตัวต่อคลาสเทสต์** ชี้ `DB_*` ไปที่ test DB เดิม แล้วยิง HTTP จริง

- `startSession($userId, $shopId)` — เขียนไฟล์ session ตรง ๆ (ไม่ต้องรู้รหัสผ่าน, เลี่ยง rate limit) ⚠️ **คีย์ต้องตรงกับ `AuthService::establishSession()` เป๊ะ ๆ** (`email`, `auth_started_at`, `last_activity_at`, `current_shop_id`, `current_shop_name`, `session_version`) — เดิมเขียนคีย์ที่แอปไม่ได้อ่าน แล้ว `isAuthSessionAlive()` เติมให้เองเงียบ ๆ ทำให้ด่านหมดเวลาเทสต์ไม่ได้เลย
- `get/post` = โหมดฟอร์ม (ตอบ **302 + flash**) · `getJson/postJson` = โหมด XHR (ตอบ **รหัสสถานะจริง**)
  ⚠️ **ทั้งสองโหมดต้องตัดสินเหมือนกัน ต่างแค่วิธีบอกผู้ใช้** — เทสต์ 409/405 ต้องยิงทั้งคู่
  (`api_respond()` เลือกจาก `Accept: application/json`)
- `csrfTokenFor($sessionId)` — ดึง token จากหน้าจริง · `flashMessages()` — อ่านไฟล์ session ดิบ (fail เมื่อไฟล์หาย เพราะ `session_regenerate_id(true)` ลบไฟล์ทิ้ง)
- ⚠️ ชื่อ field คือ **`shop_context_id`** (จาก `shop_context_field()`) ไม่ใช่ `shop_context`
- ⚠️ **เพิ่ม endpoint ใหม่ใน `api/` ต้องเพิ่มชื่อใน `EndpointGuardChainTest`** — มี `testEveryApiFileIsAccountedFor()` กวาด `glob('api/*.php')` เทียบกับรายชื่อในเทสต์ ลืมแล้วชุดเทสต์แดงทันที
- ⚠️ **payload ในรายชื่อต้องเป็นคำสั่งที่ไฟล์นั้นรู้จักจริง** — `api/shops.php`/`api/profile.php` วางด่าน CSRF ไว้ *ข้างใน* แต่ละ `if ($action === …)` ส่งคำสั่งมั่ว ๆ จะตกไป 404 ตั้งแต่ยังไม่ถึงด่าน
- **เทสต์ทั้งชั้นนี้แชร์ test DB เดียวกัน** — `IntegrationTestCase` จับ `GET_LOCK('ad_profit_test_suite')` ของ MySQL ตอน `setUpBeforeClass` สองโปรเซสที่รันพร้อมกันจึง **เข้าคิว** แทนที่จะล้างข้อมูลของกันและกันกลางคัน (เดิมเจอ `Duplicate entry` / `Table … doesn't exist` ที่ไม่เกี่ยวกับโค้ดเลย)
  · ⚠️ **ห้ามเปลี่ยนกลับไปใช้ `flock` บนไฟล์** — `ControllerTestCase` ยก `php -S` ด้วย `proc_open` ลูกจะ **สืบทอด file descriptor ของล็อก** ถ้า phpunit ถูกฆ่ากลางคัน เซิร์ฟเวอร์ที่ค้างอยู่จะถือล็อกไว้ตลอดไป การรันครั้งต่อ ๆ ไปค้างรอเงียบ ๆ โดยไม่มีอะไรบอกว่าทำไม (เกิดขึ้นจริงมาแล้ว — ใช้ `lsof <lock>` หาตัวที่ถืออยู่) · ล็อกของ MySQL ผูกกับ **การเชื่อมต่อ** จึงคืนเองเมื่อโปรเซสตาย
- ⚠️ **เทสต์ที่ส่ง payload ใหญ่ ๆ ทำให้ชุดเทสต์ทั้งชุดล้มด้วยหน่วยความจำ** — รันไฟล์เดียวผ่าน แต่รันรวมตาย (`Premature end of PHP process`) · ถ้าจะทดสอบด่านที่เกี่ยวกับขนาด ให้จำลอง **สภาพที่ด่านนั้นตรวจจริง** แทนการส่งของใหญ่จริง (เช่นด่าน 413 ตรวจว่า "body มีความยาวแต่ PHP แกะไม่ได้" → ส่ง multipart ที่ boundary ไม่ตรงกับเนื้อ)

### Framework
- **PHPUnit** เป็น dev dependency, รันด้วย `composer test`
- ⚠️ **ชุดเทสต์ใช้เวลา ~4 นาที ซึ่งเกินเพดานปริยาย 300 วินาทีของ composer** — `composer.json` จึงตั้ง `config.process-timeout` ไว้ · ถ้าไม่ตั้ง `composer test` จะถูกฆ่ากลางคันพร้อมข้อความ "exceeded the timeout" ซึ่งอ่านแล้วนึกว่าเทสต์ค้าง ทั้งที่มันแค่ยังไม่เสร็จ
- ⚠️ **ต้องใช้ PHP 8.4+ ในการรันเทสต์** (PHPUnit 13 require `php >= 8.4.1`) — เครื่องที่เป็น 8.3 จะ `composer install` dev dependency ไม่ผ่าน; ความเข้ากันได้กับ 8.3 คุมด้วย `php -l` ใน CI job `lint-php83` เท่านั้น (syntax ไม่ใช่ runtime)
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

### ⭐ วิธีหาบั๊กรายงานที่ได้ผลที่สุด: "ร้านที่นิ่งสนิท เดินทีละวันทั้งปี"

สร้างร้านที่ทำกำไร **วันละเท่ากันเป๊ะ** ทุกวัน กรอกครบทุกวัน (ทั้งปีนี้และปีก่อน) แล้ววนเรียก
service ด้วย `$today` ไล่ทีละวันตั้งแต่ 1 ม.ค. ถึง 31 ธ.ค. · **ตัวเลขไหนขยับ = บั๊ก**
เพราะธุรกิจไม่มีอะไรเปลี่ยนเลยแม้แต่วันเดียว

วิธีนี้จับได้จริงในรอบเดียว:
- ประมาณการสิ้นปีกระโดด **+฿7,527 ข้ามคืน** (16 พ.ค.) และ **+฿4,790** (16 มี.ค.)
- ก่อนหน้านั้นจับ "เทียบปีก่อน ↓9.9%" ที่ดีขึ้นเองทุกวัน · การ์ดเดือนแย่สุดชี้เดือนปัจจุบันตลอดต้นเดือน
- กราฟ 6 เดือนเปลี่ยนค่าเมื่อกดสลับช่วงเวลาเฉย ๆ

⚠️ **ต้องให้ stub ของ repository จำลองการตัดวันจริง** (`$notAfterDate`) ไม่งั้นได้ผลเหมือนกัน
ทั้งก่อนและหลังแก้ · และ **ข้อมูลตั้งต้นต้องเขียนเป็น "อัตราต่อวัน × จำนวนวันของเดือนนั้น"**
ไม่ใช่ยอดเดือนลอย ๆ ไม่งั้นเคสเดือนสั้น (ก.พ.) จะไม่เคยถูกเดินผ่าน

ตัวแปรอื่นที่ใช้ได้ผลเหมือนกัน: **"สองที่ต้องพูดตรงกัน"** — หน้าจอ vs ไฟล์ Excel vs CSV,
แดชบอร์ด vs หน้ารายปี, กราฟ vs ตารางที่อยู่เหนือมัน · บั๊กรายงานเกือบทั้งหมดในโปรเจกต์นี้
เป็นรูปแบบ "กติกาถูกบังคับใช้ที่หนึ่งแต่ไปไม่ถึงอีกที่หนึ่ง"

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
