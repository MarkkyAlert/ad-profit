# 🏗️ Architecture & Coding Standards

> ไฟล์นี้กำหนดสถาปัตยกรรม มาตรฐานโค้ด และคุณภาพของระบบ  
> **ต้องยึดตามเอกสารนี้ทุกไฟล์ที่สร้าง ห้ามข้ามข้อใดข้อหนึ่ง**

---

## 1. บทบาทของคุณ

คุณเป็น **Senior PHP Developer + System Architect**
- เป้าหมาย: โค้ดอ่านง่าย แยกชั้นชัด เอาไปพัฒนาต่อ/ทำขายได้
- ใช้ Pure PHP เท่านั้น (ห้ามใช้ Framework: Laravel, Symfony, CodeIgniter ฯลฯ)
- ห้ามใช้ React, Vue, Angular, jQuery

---

## 2. สถาปัตยกรรม (บังคับ)

### Design Pattern: Controller → Service → Repository

```
Controller (หน้าเว็บ / API endpoint)
    ↓  เรียก
Service (Business logic, validation, transaction)
    ↓  เรียก
Repository (SQL ทั้งหมดอยู่ที่นี่ที่เดียว)
    ↓  ใช้
PDO (Database)
```

### กฎแต่ละชั้น

| ชั้น | หน้าที่ | ห้ามทำ |
|------|---------|--------|
| **Controller** (ไฟล์ .php ใน root, /admin, /api) | รับ request → เรียก Service → ส่งข้อมูลให้ View หรือ return JSON | ห้ามมี SQL, ห้ามมี business logic |
| **Service** (/app/Services) | Business rules, validation, transaction, คำนวณ | ห้ามมี SQL ตรงๆ (ต้องเรียกผ่าน Repository) |
| **Repository** (/app/Repositories) | รวม SQL ทั้งหมด, prepared statements | ห้ามมี business logic, ห้ามมี validation |
| **View** (ส่วน HTML ของหน้าเว็บ) | แสดงผลเท่านั้น ใช้ตัวแปรที่ Controller ส่งมา | ห้ามมี SQL, ห้ามเรียก Service/Repository ตรง |

### ตัวอย่าง flow

```
dashboard.php (Controller)
  → $dashboardService->getMonthlySummary($shopId, $month)
      → $recordRepository->getSumByMonth($shopId, $startDate, $endDate)
      → $recordRepository->getBestDay($shopId, $startDate, $endDate)
      → $goalRepository->getByShopAndMonth($shopId, $month)
      → คำนวณ ROAS, profit margin, change % (ใน Service)
  → ส่งผลลัพธ์ให้ View แสดง
```

---

## 3. โครงสร้างโฟลเดอร์ (บังคับ)

```
project/
├── index.php                    # หน้าแรก → redirect
├── login.php                    # หน้าล็อกอิน / สมัคร
├── dashboard.php                # แดชบอร์ด
├── add-record.php               # บันทึกข้อมูล
├── history.php                  # ประวัติรายการ
├── overview.php                 # รวมทุกร้าน
├── annual.php                   # สรุปประจำปี
│
├── api/                         # AJAX / JSON endpoints
│   ├── auth.php
│   ├── records.php
│   ├── shops.php
│   ├── goals.php
│   ├── dashboard-data.php
│   ├── overview-data.php
│   ├── annual-data.php
│   └── export.php
│
├── app/
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── RecordService.php
│   │   ├── ShopService.php
│   │   ├── GoalService.php
│   │   ├── DashboardService.php
│   │   ├── OverviewService.php
│   │   └── AnnualService.php
│   │
│   └── Repositories/
│       ├── UserRepository.php
│       ├── ShopRepository.php
│       ├── RecordRepository.php
│       └── GoalRepository.php
│
├── includes/
│   ├── bootstrap.php            # autoload, session start, error handler
│   ├── config.php               # DB credentials, app settings
│   ├── database.php             # PDO connection (singleton)
│   ├── functions.php            # Helper functions: e(), formatMoney(), csrf()
│   ├── auth.php                 # Session check middleware
│   ├── header.php               # HTML header + nav (View partial)
│   └── footer.php               # HTML footer + bottom nav (View partial)
│
├── database/
│   ├── schema.sql               # สร้างตาราง (รันครั้งเดียว)
│   └── sample_data.sql          # ข้อมูลตัวอย่างสำหรับทดสอบ
│
├── docs/
│   ├── README.md                # ภาพรวมโปรเจกต์
│   ├── INSTALL.md               # วิธีติดตั้ง step-by-step
│   ├── ARCHITECTURE.md          # อธิบาย pattern + โครงสร้าง
│   ├── FLOW.md                  # Flow ราย use case
│   └── WHERE_TO_EDIT.md         # จะแก้อะไร ไปแก้ไฟล์ไหน
│
├── uploads/                     # ไฟล์ที่ user upload (ถ้ามีในอนาคต)
├── logs/                        # Error logs
└── cron/                        # งานอัตโนมัติ (ถ้ามีในอนาคต)
```

---

## 4. มาตรฐานโค้ด (บังคับทุกข้อ)

### 4.1 Database & SQL

| กฎ | รายละเอียด |
|----|-----------|
| ใช้ PDO เท่านั้น | ห้ามใช้ mysqli |
| Prepared Statements จริง | ตั้ง `EMULATE_PREPARES = false` ใน PDO options |
| SQL อยู่ใน Repository เท่านั้น | ห้ามเขียน SQL ใน Controller, Service, หรือ View |
| Transaction | ใช้ `beginTransaction()` / `commit()` / `rollBack()` ใน Service สำหรับ flow ที่แก้ไขหลายตาราง |
| SELECT ... FOR UPDATE | ใช้ใน flow ที่ต้อง lock row ก่อนแก้ไข (เช่น เปลี่ยนสถานะ, ตัด stock) |

```php
// ตัวอย่าง PDO config ใน includes/database.php
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
```

### 4.2 Security

| กฎ | วิธีทำ |
|----|--------|
| XSS Prevention | ใช้ `e()` helper (wrapper ของ `htmlspecialchars`) ก่อนแสดงค่าจาก DB/user ทุกจุดใน HTML |
| SQL Injection | Prepared statements เท่านั้น — ห้ามต่อ string เข้า query |
| CSRF Protection | สร้าง token เก็บใน `$_SESSION` → ใส่ `<input type="hidden">` ทุก POST form → ตรวจก่อน process |
| Password | `password_hash($pw, PASSWORD_DEFAULT)` + `password_verify()` — ห้ามใช้ md5/sha1 |
| Session Hardening | `session_regenerate_id(true)` หลัง login สำเร็จ |
| Rate Limiting | จำกัดจำนวนครั้งต่อ IP/session บน login, register (เช่น 5 ครั้ง/นาที) |
| Upload (ถ้ามี) | ตรวจ MIME type จริง ด้วย `finfo_file()` + จำกัดชนิด/ขนาด |

```php
// ตัวอย่าง helper functions ใน includes/functions.php

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid CSRF token']));
    }
}
```

### 4.3 Idempotency

สำหรับ action ที่ user กดซ้ำได้ (บันทึกข้อมูล, ตั้งเป้า):

```php
// สร้าง idempotency key ฝั่ง client (hidden field)
// ส่งมาพร้อม form → ตรวจใน Service ว่าเคย process แล้วหรือยัง
// ถ้าเคย → return ผลลัพธ์เดิม ไม่ทำซ้ำ
```

- ระบบนี้ไม่มี payment แต่ flow upsert (กรอกวันซ้ำ → อัปเดตทับ) ต้อง handle gracefully
- ห้ามสร้าง duplicate record จากการกด submit ซ้ำ

### 4.4 Coding Style

| กฎ | ตัวอย่าง |
|----|---------|
| Class: PascalCase | `RecordService`, `UserRepository` |
| Method: camelCase | `getMonthlySummary()`, `upsertRecord()` |
| Variable: camelCase | `$shopId`, `$totalRevenue` |
| File: kebab-case (public) | `add-record.php`, `dashboard-data.php` |
| File: PascalCase (class) | `RecordService.php`, `UserRepository.php` |
| Indent: 4 spaces | ห้ามใช้ tab |
| PHP tag | ใช้ `<?php` เท่านั้น ห้ามใช้ `<?` |

### 4.5 Error Handling

- API endpoints → return JSON: `{ "success": false, "error": "message" }` + HTTP status code
- หน้าเว็บ → แสดง error ใน UI (toast / inline message) ไม่ใช่ die() หรือ blank page
- Log errors ลง `/logs/` ด้วย `error_log()` ห้ามแสดง stack trace ให้ user
- Production: `display_errors = Off`, `log_errors = On`

---

## 5. ขั้นตอน Bootstrap

ทุกไฟล์ .php ที่เป็น entry point ต้องเริ่มด้วย:

```php
<?php
require_once __DIR__ . '/includes/bootstrap.php';
```

`bootstrap.php` ทำหน้าที่:
1. `session_start()` พร้อม secure settings
2. Set error reporting
3. Require `config.php`, `database.php`, `functions.php`
4. Autoload classes จาก `/app/` (ใช้ `spl_autoload_register`)

```php
// includes/bootstrap.php
<?php
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Lax',
]);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

spl_autoload_register(function ($class) {
    // App\Services\RecordService → app/Services/RecordService.php
    $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $class) . '.php';
    // Also try: app/Services/RecordService.php
    $altPath = dirname(__DIR__) . '/app/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    } elseif (file_exists($altPath)) {
        require_once $altPath;
    }
});
```

---

## 6. ตัวอย่าง Pattern การเขียนแต่ละชั้น

### Repository (SQL อยู่ที่นี่เท่านั้น)

```php
// app/Repositories/RecordRepository.php
class RecordRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function upsert(int $shopId, string $date, float $revenue, float $adCost, ?string $note): bool {
        $sql = "INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note)
                VALUES (:shop_id, :date, :revenue, :ad_cost, :note)
                ON DUPLICATE KEY UPDATE
                    revenue = VALUES(revenue),
                    ad_cost = VALUES(ad_cost),
                    note = VALUES(note)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':shop_id'  => $shopId,
            ':date'     => $date,
            ':revenue'  => $revenue,
            ':ad_cost'  => $adCost,
            ':note'     => $note,
        ]);
    }

    public function getSumByDateRange(int $shopId, string $startDate, string $endDate): ?array {
        $sql = "SELECT SUM(revenue) AS total_revenue, SUM(ad_cost) AS total_ad_cost,
                       COUNT(*) AS days_count
                FROM daily_records
                WHERE shop_id = :shop_id AND record_date BETWEEN :start AND :end";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':shop_id' => $shopId, ':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetch() ?: null;
    }
}
```

### Service (Business logic อยู่ที่นี่)

```php
// app/Services/DashboardService.php
class DashboardService {
    private RecordRepository $recordRepo;
    private GoalRepository $goalRepo;

    public function __construct(RecordRepository $recordRepo, GoalRepository $goalRepo) {
        $this->recordRepo = $recordRepo;
        $this->goalRepo   = $goalRepo;
    }

    public function getSummary(int $shopId, string $startDate, string $endDate): array {
        $data = $this->recordRepo->getSumByDateRange($shopId, $startDate, $endDate);

        $totalRevenue = (float)($data['total_revenue'] ?? 0);
        $totalAdCost  = (float)($data['total_ad_cost'] ?? 0);
        $profit       = $totalRevenue - $totalAdCost;

        return [
            'total_revenue'      => $totalRevenue,
            'total_ad_cost'      => $totalAdCost,
            'profit'             => $profit,
            'roas'               => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
            'profit_margin'      => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
            'avg_revenue_per_day'=> ($data['days_count'] ?? 0) > 0
                                    ? round($totalRevenue / $data['days_count'])
                                    : null,
            'days_count'         => (int)($data['days_count'] ?? 0),
        ];
    }
}
```

### Controller (เรียก Service → ส่งให้ View)

```php
// dashboard.php (Controller)
<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจ login

$shopId   = $_SESSION['current_shop_id'];
$month    = $_GET['month'] ?? date('Y-m');
$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// สร้าง dependencies
$recordRepo = new RecordRepository($pdo);
$goalRepo   = new GoalRepository($pdo);
$service    = new DashboardService($recordRepo, $goalRepo);

// เรียก Service
$summary = $service->getSummary($shopId, $startDate, $endDate);

// ส่งให้ View
require __DIR__ . '/includes/header.php';
// ... HTML ใช้ตัวแปร $summary เช่น e(formatMoney($summary['total_revenue']))
require __DIR__ . '/includes/footer.php';
```

### API Endpoint (return JSON)

```php
// api/dashboard-data.php
<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$shopId    = $_SESSION['current_shop_id'];
$month     = $_GET['month'] ?? date('Y-m');
// ... validate, สร้าง Service, เรียก method
echo json_encode(['success' => true, 'data' => $result]);
```

---

## 7. สิ่งที่ต้องส่งมอบในแต่ละ Phase

| สิ่งที่ต้องทำ | รายละเอียด |
|--------------|-----------|
| ✅ โค้ดที่รันได้จริง | ไม่ใช่ pseudo code — ต้อง test ได้ |
| ✅ ตาม pattern ข้างบน | Controller → Service → Repository |
| ✅ SQL ใน Repository เท่านั้น | ห้ามมี SQL ในไฟล์อื่น |
| ✅ Security ทุกข้อ | XSS, CSRF, prepared statements, password hash |
| ✅ Error handling | API return JSON error, หน้าเว็บแสดง toast |
| ✅ อัปเดตเอกสาร | เมื่อเพิ่มไฟล์ใหม่ ต้องอัปเดต docs/ ด้วย |

---

## 8. เอกสารที่ต้องสร้าง (Phase 1)

สร้างใน `/docs/` ตั้งแต่ Phase 1 และอัปเดตทุก Phase:

| ไฟล์ | เนื้อหา |
|------|---------|
| `README.md` | ภาพรวม, ฟีเจอร์, Tech Stack, วิธี setup เบื้องต้น |
| `INSTALL.md` | ขั้นตอนติดตั้ง step-by-step (server requirements, import SQL, config DB) |
| `ARCHITECTURE.md` | อธิบาย pattern Controller→Service→Repository, โครงสร้างโฟลเดอร์ |
| `FLOW.md` | Flow ราย use case (login, บันทึกข้อมูล, ดูแดชบอร์ด ฯลฯ) |
| `WHERE_TO_EDIT.md` | อยากแก้อะไร → ไปแก้ไฟล์ไหน (เช่น "เพิ่ม column ใหม่ → แก้ Repository + Service + View") |

---

## 9. ข้อห้าม

- ❌ ห้ามเดา requirement เอง — ถ้าไม่ชัดให้ทำเป็น TODO list แล้วถามกลับ
- ❌ ห้าม refactor เกินจำเป็น — ทำตาม spec ไม่ต้องเพิ่มสิ่งที่ไม่ได้ขอ
- ❌ ห้ามอธิบายยาวเรื่อง syntax พื้นฐาน
- ❌ ห้ามใช้ Framework, jQuery, หรือ frontend framework
- ❌ ห้ามเขียน SQL นอก Repository
- ❌ ห้ามแสดง stack trace / DB error ให้ user เห็น
