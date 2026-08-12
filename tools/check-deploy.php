<?php

declare(strict_types=1);

/**
 * ⭐⭐ ตัวตรวจความพร้อมของเซิร์ฟเวอร์ — รันบนเครื่องที่ติดตั้งจริง
 *
 *     php tools/check-deploy.php
 *
 * ตอบคำถามเดียว: "อัปโหลดขึ้นไปแล้ว เปิดใช้ได้เลยไหม หรือยังขาดอะไร"
 *
 * ⚠️ ตั้งใจให้รันได้แม้ระบบยังตั้งค่าไม่ครบ — ไม่ `require bootstrap.php`
 * (bootstrap จะตายก่อนถ้าฐานข้อมูลต่อไม่ได้ แล้วเราจะไม่รู้ว่าตายเพราะอะไร)
 *
 * ⚠️ ห้ามพิมพ์ค่ารหัสผ่าน/คีย์ออกมา — บอกแค่ "มี/ไม่มี"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("ตัวตรวจนี้รันได้จากบรรทัดคำสั่งเท่านั้น\n");
}

$root = dirname(__DIR__);
$problems = [];
$warnings = [];
$passed = [];

$check = static function (bool $ok, string $label, string $howToFix = '') use (&$problems, &$passed): void {
    if ($ok) {
        $passed[] = $label;
        return;
    }
    $problems[] = $label . ($howToFix !== '' ? "\n      → " . $howToFix : '');
};

$warn = static function (bool $ok, string $label, string $advice) use (&$warnings, &$passed): void {
    if ($ok) {
        $passed[] = $label;
        return;
    }
    $warnings[] = $label . "\n      → " . $advice;
};

// ── 1. PHP ────────────────────────────────────────────────────────────────
$check(
    PHP_VERSION_ID >= 80300,
    'PHP ' . PHP_VERSION . ' (ต้อง 8.3 ขึ้นไป)',
    'เปลี่ยนเวอร์ชัน PHP ในหน้าจัดการโฮสต์ · ต่ำกว่านี้ติดตั้งไลบรารีไม่ผ่านตั้งแต่แรก'
);

// ⚠️ zip/gd เป็น hard requirement ของ phpoffice/phpspreadsheet — ขาดแล้ว composer ล้ม
foreach (['pdo_mysql' => 'ต่อฐานข้อมูล', 'mbstring' => 'ตัวอักษรไทย', 'zip' => 'ไฟล์ Excel', 'gd' => 'ไฟล์ Excel'] as $ext => $why) {
    $check(
        extension_loaded($ext),
        'ส่วนเสริม ' . $ext . ' (' . $why . ')',
        'เปิดใน php.ini หรือหน้าจัดการโฮสต์'
    );
}

// ── 2. ไลบรารี ────────────────────────────────────────────────────────────
$check(
    is_file($root . '/vendor/autoload.php'),
    'ติดตั้งไลบรารีแล้ว (vendor/)',
    'รัน: composer install --no-dev --optimize-autoloader'
);

// ── 3. ไฟล์ตั้งค่า ────────────────────────────────────────────────────────
$envPath = $root . '/.env';
$check(is_file($envPath), 'มีไฟล์ .env', 'คัดลอกจาก .env.example แล้วกรอกค่าจริง');

$env = [];
if (is_file($envPath)) {
    foreach ((array)file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }

    /* ⚠️⚠️ ต้องจับ "ค่าตัวอย่างที่ยังไม่ได้แทนที่" ด้วย ไม่ใช่แค่ "ว่างหรือไม่ว่าง"
       · แพ็กเกจที่เตรียมไว้ให้ใส่คำว่า "ใส่…" เป็นตัวอย่าง ซึ่ง **ไม่ว่าง**
         ตัวตรวจรุ่นแรกจึงตอบว่าผ่าน ทั้งที่ยังไม่ได้กรอกอะไรเลย (เจอตอนทดสอบแพ็กเกจ)
       · เป็นความผิดพลาดแบบเดียวกับ "ตัวกันที่ไม่มีวันทำงาน" ที่โปรเจกต์นี้เจอมาหลายรอบ */
    $looksUnfilled = static function (string $value): bool {
        return $value === '' || str_contains($value, 'ใส่') || str_contains($value, 'YOUR_');
    };

    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        $check(
            !$looksUnfilled((string)($env[$key] ?? '')),
            'ตั้งค่า ' . $key . ' แล้ว',
            'ยังเป็นค่าตัวอย่าง — เปิด .env แล้วแทนที่คำว่า "ใส่…" ด้วยค่าจริง'
        );
    }

    /* ⚠️⚠️ ลืมตั้ง APP_URL แล้วลิงก์ในอีเมลจะเป็น /reset-password.php?token=…
       ซึ่งกดจากกล่องจดหมายไม่ได้ ขณะที่หน้าเว็บบอกว่า "ส่งลิงก์แล้ว"
       และ .env.example ส่งค่าว่างมา จึงพลาดได้ง่ายมาก */
    $appUrl = (string)($env['APP_URL'] ?? '');
    $check(
        !$looksUnfilled($appUrl) && preg_match('#^https?://#i', $appUrl) === 1,
        'ตั้งค่า APP_URL เป็นที่อยู่เว็บเต็ม (ตอนนี้: ' . ($appUrl === '' ? 'ว่าง' : $appUrl) . ')',
        'ต้องเป็นแบบ https://โดเมนของคุณ · ถ้าว่าง ลิงก์ในอีเมลจะกดไม่ได้'
    );

    $check(
        ($env['APP_ENV'] ?? '') === 'production',
        'APP_ENV = production (ตอนนี้: ' . ($env['APP_ENV'] ?? 'ไม่ได้ตั้ง') . ')',
        'ตั้งเป็น production ไม่งั้นข้อความ error ภายในจะโผล่ให้ผู้ใช้เห็น'
    );

    $mailKeys = ['MAIL_ENABLED', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'];
    $mailReady = true;
    foreach ($mailKeys as $key) {
        if ($looksUnfilled((string)($env[$key] ?? ''))) {
            $mailReady = false;
        }
    }
    $warn(
        $mailReady,
        'ตั้งค่าอีเมลครบ (ลืมรหัสผ่าน / เปลี่ยนอีเมล ใช้ได้)',
        'ต้องมีครบทั้ง 4 ค่า: ' . implode(', ', $mailKeys)
            . ' · ถ้ายังไม่ตั้ง ผู้ใช้ที่ลืมรหัสผ่านจะเข้าระบบไม่ได้เลย'
    );
}

// ── 4. ฐานข้อมูล ──────────────────────────────────────────────────────────
if (($env['DB_NAME'] ?? '') !== '' && extension_loaded('pdo_mysql')) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_NAME']
        );
        $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $passed[] = 'ต่อฐานข้อมูลได้';

        $tables = [];
        foreach ((array)$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $tables[] = (string)$name;
        }

        $needed = ['users', 'shops', 'daily_records', 'monthly_goals',
            'password_reset_tokens', 'email_change_requests', 'auth_rate_limits'];
        $missing = array_values(array_diff($needed, $tables));

        $check(
            $missing === [],
            'ตารางครบทั้ง ' . count($needed) . ' ตาราง',
            'ยังขาด: ' . implode(', ', $missing) . ' → นำเข้า database/schema.sql'
        );
    } catch (Throwable $exception) {
        $problems[] = 'ต่อฐานข้อมูลไม่ได้'
            . "\n      → " . $exception->getMessage()
            . "\n      → ตรวจ DB_HOST / DB_NAME / DB_USER / DB_PASS ใน .env";
    }
}

// ── 5. เขียนไฟล์บันทึกได้ไหม ──────────────────────────────────────────────
$logFile = ($env['LOG_FILE'] ?? '') !== ''
    ? $env['LOG_FILE']
    : sys_get_temp_dir() . '/ad-profit/php-error.log';
$logDir = dirname((string)$logFile);
$warn(
    (is_dir($logDir) && is_writable($logDir)) || (!is_dir($logDir) && is_writable(dirname($logDir))),
    'เขียนไฟล์บันทึกข้อผิดพลาดได้ (' . $logDir . ')',
    'ถ้าเขียนไม่ได้ ข้อความของระบบจะหายเงียบ — เว็บยังใช้ได้ แต่หาสาเหตุตอนมีปัญหาไม่ได้'
);

// ── 6. ไฟล์กันการเข้าถึง ──────────────────────────────────────────────────
$check(
    is_file($root . '/.htaccess'),
    'มีไฟล์ .htaccess (ตัวกันไม่ให้ดาวน์โหลดไฟล์ลับ)',
    '⚠️ ถ้าหาย https://โดเมน/.env จะดาวน์โหลดได้ → รหัสฐานข้อมูลและรหัสอีเมลหลุด'
);

// ── สรุป ──────────────────────────────────────────────────────────────────
$line = str_repeat('─', 64);
echo "\n", $line, "\n  ตรวจความพร้อมของเซิร์ฟเวอร์\n", $line, "\n\n";

foreach ($passed as $item) {
    echo "  ✅ ", $item, "\n";
}

if ($warnings !== []) {
    echo "\n  ── ควรแก้ แต่เว็บยังใช้ได้ ", str_repeat('─', 30), "\n\n";
    foreach ($warnings as $item) {
        echo "  ⚠️  ", $item, "\n";
    }
}

if ($problems !== []) {
    echo "\n  ── ต้องแก้ก่อนใช้งาน ", str_repeat('─', 34), "\n\n";
    foreach ($problems as $item) {
        echo "  ❌ ", $item, "\n";
    }
    echo "\n", $line, "\n  ยังไม่พร้อม — เหลือ ", count($problems), " เรื่องต้องแก้\n", $line, "\n\n";
    exit(1);
}

echo "\n", $line, "\n  พร้อมใช้งาน";
if ($warnings !== []) {
    echo " (มี ", count($warnings), " เรื่องที่ควรแก้)";
}
echo "\n\n  ⚠️ เหลืออีก 1 อย่างที่ตัวตรวจนี้ทำแทนไม่ได้ — ต้องเปิดเบราว์เซอร์ดูเอง:\n";
echo "     php tools/check-live.php https://โดเมนของคุณ\n";
echo $line, "\n\n";
exit(0);
