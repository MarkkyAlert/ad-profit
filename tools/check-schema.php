<?php

declare(strict_types=1);

/**
 * ⭐⭐⭐ ตรวจว่าฐานข้อมูล "ที่มีข้อมูลจริงอยู่แล้ว" ตรงกับที่โค้ดต้องการหรือยัง
 *
 *     php tools/check-schema.php
 *
 * **อ่านอย่างเดียว ไม่แก้ไขอะไรทั้งสิ้น** — ตอบคำถามเดียว:
 * "ต้องรัน migration ตัวไหนบ้าง ก่อนอัปโค้ดใหม่ขึ้นไป"
 *
 * ⚠️⚠️ ทำไมถึงต้องมี: `database/schema.sql` เป็นคำสั่ง **ลบตารางแล้วสร้างใหม่**
 * เจ้าของที่มีข้อมูลจริงอยู่แล้วรันไฟล์นั้นไม่ได้เด็ดขาด — ต้องรัน migration ทีละตัวแทน
 * แต่จะรู้ได้ยังไงว่าต้องรันตัวไหน? ตัวนี้ตอบให้
 *
 * ⚠️ ใช้ `schema_*()` ชุดเดียวกับ Schema Guard ที่บูตแอป — ไม่ได้เขียนรายการซ้ำ
 * ถ้าวันหนึ่ง guard เปลี่ยน ตัวนี้เปลี่ยนตาม
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("ตัวตรวจนี้รันได้จากบรรทัดคำสั่งเท่านั้น\n");
}

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

// ── อ่าน .env เอง (ไม่ผ่าน bootstrap เพราะ bootstrap จะตายถ้า schema ไม่ตรง) ──
$envPath = $root . '/.env';
if (!is_file($envPath)) {
    exit("ไม่พบไฟล์ .env — ต้องตั้งค่าการเชื่อมต่อฐานข้อมูลก่อน\n");
}

$env = [];
foreach ((array)file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value);
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_NAME'] ?? ''
        ),
        $env['DB_USER'] ?? '',
        $env['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Throwable $exception) {
    exit("ต่อฐานข้อมูลไม่ได้: " . $exception->getMessage() . "\n");
}

$line = str_repeat('─', 68);
echo "\n", $line, "\n  ตรวจโครงสร้างฐานข้อมูล: ", ($env['DB_NAME'] ?? '?'), "\n";
echo "  (อ่านอย่างเดียว — ไม่แก้ไขอะไรทั้งสิ้น)\n", $line, "\n\n";

/** @var array<string,string> ปัญหา → ไฟล์ migration ที่แก้ */
$todo = [];
$ok = [];

// ── 1. ตารางที่ต้องมี ────────────────────────────────────────────────────
$requiredTables = [
    'users' => null,
    'shops' => null,
    'daily_records' => null,
    'monthly_goals' => null,
    'password_reset_tokens' => null,
    'auth_rate_limits' => null,
    'email_change_requests' => '2026-08-05-email-change-requests.sql',
];

foreach ($requiredTables as $table => $migration) {
    if (schema_table_exists($pdo, $table)) {
        $ok[] = 'ตาราง ' . $table;
        continue;
    }

    $todo['ไม่มีตาราง ' . $table] = $migration
        ?? '⚠️ ไม่มีไฟล์ migration — ฐานข้อมูลนี้เก่ากว่าที่ระบบรองรับ';
}

// ── 2. ตารางที่ต้อง "ไม่มี" แล้ว ─────────────────────────────────────────
if (schema_table_exists($pdo, 'idempotency_requests')) {
    $todo['ยังมีตาราง idempotency_requests ที่เลิกใช้แล้ว']
        = '2026-08-04-drop-idempotency-requests.sql';
} else {
    $ok[] = 'ไม่มีตารางที่เลิกใช้แล้ว';
}

// ── 3. กติกา "ชื่อร้านซ้ำกันไหม" (อิโมจิ) ────────────────────────────────
foreach (schema_required_collations() as [$table, $column, $expected]) {
    if (!schema_table_exists($pdo, $table)) {
        continue;
    }

    $result = schema_collation_matches($pdo, $table, $column, $expected);
    if (($result['ok'] ?? false) === true) {
        $ok[] = 'กติกาเทียบชื่อ ' . $table . '.' . $column;
        continue;
    }

    $todo[sprintf(
        '%s.%s ใช้กติกาเทียบตัวอักษรเก่า (ตอนนี้: %s · ต้องเป็น: %s) — ตั้งชื่อร้านเป็นอิโมจิแล้วข้อมูลลงผิดร้าน',
        $table,
        $column,
        (string)($result['actual'] ?? 'ไม่ทราบ'),
        $expected
    )] = '2026-08-05-shop-name-collation.sql';
}

// ── 4. คีย์ที่บังคับกติกาสำคัญ ───────────────────────────────────────────
foreach (schema_required_unique_indexes() as [$table, $index]) {
    if (!schema_table_exists($pdo, $table)) {
        continue;
    }

    if (schema_unique_index_exists($pdo, $table, $index)) {
        $ok[] = 'คีย์ ' . $index;
        continue;
    }

    $todo['ขาดคีย์ ' . $index . ' บนตาราง ' . $table]
        = '⚠️ ต้องสร้างเอง — คีย์นี้คือสิ่งที่กันข้อมูลซ้ำ';
}

// ── 5. ชนิดคอลัมน์ ──────────────────────────────────────────────────────
foreach (schema_required_column_types() as [$table, $column, $expected]) {
    if (!schema_table_exists($pdo, $table)) {
        continue;
    }

    $result = schema_column_type_matches($pdo, $table, $column, $expected);
    if (($result['ok'] ?? false) === true) {
        $ok[] = 'ชนิดของ ' . $table . '.' . $column;
        continue;
    }

    $todo[sprintf(
        '%s.%s ชนิดไม่ตรง (ตอนนี้: %s · ต้องเป็น: %s)',
        $table,
        $column,
        (string)($result['actual'] ?? 'ไม่ทราบ'),
        $expected
    )] = '⚠️ ต้องแก้เอง — ชนิดที่เล็กกว่าจะตัดข้อมูลทิ้งเงียบ ๆ';
}

// ── 6. เครื่องยนต์ตาราง (ต้องเป็น InnoDB ไม่งั้นย้อนกลับไม่ได้) ─────────
foreach (schema_transactional_tables() as $table) {
    if (!schema_table_exists($pdo, $table)) {
        continue;
    }

    if (schema_table_is_innodb($pdo, $table)) {
        $ok[] = 'เครื่องยนต์ของ ' . $table;
        continue;
    }

    $todo['ตาราง ' . $table . ' ไม่ใช่ InnoDB — การยกเลิกกลางคันจะไม่ย้อนข้อมูลให้']
        = '⚠️ ต้องแก้เอง: ALTER TABLE ' . $table . ' ENGINE=InnoDB;';
}

// ── 7. การลบต่อเนื่อง (ลบร้านแล้วข้อมูลในร้านต้องหายตาม) ────────────────
foreach (schema_required_cascades() as [$table, $constraint]) {
    if (!schema_table_exists($pdo, $table)) {
        continue;
    }

    if (schema_cascade_exists($pdo, $table, $constraint)) {
        $ok[] = 'การลบต่อเนื่อง ' . $constraint;
        continue;
    }

    $todo['ขาดการลบต่อเนื่อง ' . $constraint . ' บน ' . $table . ' — ลบร้านแล้วข้อมูลจะค้าง']
        = '⚠️ ต้องสร้าง foreign key เอง';
}

// ── สรุป ────────────────────────────────────────────────────────────────
$rows = 0;
foreach (['users', 'shops', 'daily_records'] as $table) {
    if (schema_table_exists($pdo, $table)) {
        $rows += (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
}

echo '  ผ่าน ', count($ok), " รายการ\n";
echo '  ข้อมูลในฐานข้อมูลตอนนี้: ', number_format($rows), " แถว (ผู้ใช้ + ร้าน + รายการรายวัน)\n\n";

if ($todo === []) {
    echo $line, "\n  ✅ ฐานข้อมูลตรงกับโค้ดแล้ว — อัปโค้ดใหม่ขึ้นไปได้เลย\n";
    echo "     ไม่ต้องรัน migration อะไรทั้งสิ้น\n", $line, "\n\n";
    exit(0);
}

echo "  ── ต้องแก้ก่อนอัปโค้ดใหม่ ", str_repeat('─', 36), "\n\n";

$files = [];
foreach ($todo as $problem => $fix) {
    echo '  ❌ ', $problem, "\n";
    echo '      → ', $fix, "\n\n";
    if (str_ends_with($fix, '.sql')) {
        $files[$fix] = true;
    }
}

if ($files !== []) {
    echo "  ── คำสั่งที่ต้องรัน (เรียงตามลำดับ) ", str_repeat('─', 28), "\n\n";
    echo "  # สำรองข้อมูลก่อนเสมอ\n";
    echo '  mysqldump -u ' . ($env['DB_USER'] ?? 'USER') . ' -p '
        . ($env['DB_NAME'] ?? 'DBNAME') . " > ~/backup-\$(date +%F).sql\n\n";

    foreach (array_keys($files) as $file) {
        echo '  mysql -u ' . ($env['DB_USER'] ?? 'USER') . ' -p '
            . ($env['DB_NAME'] ?? 'DBNAME')
            . ' < database/migrations/' . $file . "\n";
    }
    echo "\n  แล้วรันตัวตรวจนี้อีกครั้ง — ต้องขึ้นว่าตรงกันแล้ว\n";
}

echo "\n", $line, "\n";
echo "  ⚠️⚠️ ห้ามรัน database/schema.sql เด็ดขาด — เป็นคำสั่งลบตารางแล้วสร้างใหม่\n";
echo "        ข้อมูล ", number_format($rows), " แถวจะหายทั้งหมด\n";
echo $line, "\n\n";
exit(1);
