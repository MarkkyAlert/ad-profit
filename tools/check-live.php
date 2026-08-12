<?php

declare(strict_types=1);

/**
 * ⭐⭐⭐ ตัวตรวจ "ไฟล์ลับโหลดผ่านเว็บไม่ได้" — รันจากเครื่องไหนก็ได้
 *
 *     php tools/check-live.php https://โดเมนของคุณ
 *
 * ⚠️⚠️ **ทำไมชุดเทสต์แทนไม่ได้:** ตัวกันคือไฟล์ `.htaccess` ซึ่งเป็นหน้าที่ของ
 * **เว็บเซิร์ฟเวอร์** ไม่ใช่ของโค้ด · เซิร์ฟเวอร์ที่ใช้ตอนทดสอบไม่อ่านไฟล์นั้นเลย
 * และ nginx ก็ไม่อ่าน — ชุดเทสต์ตรวจได้แค่ว่า "กฎถูกเขียนไว้ครบ" เท่านั้น
 * **ของจริงต้องยิงเข้าเว็บจริงหลัง deploy ทุกครั้ง**
 *
 * ⚠️ ตัวตรวจนี้ไม่ดาวน์โหลดเนื้อไฟล์เก็บไว้ และไม่พิมพ์เนื้อไฟล์ออกมา
 *    ถ้าเจอว่าโหลดได้จะบอกแค่ว่า "โหลดได้" พร้อมขนาด
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("ตัวตรวจนี้รันได้จากบรรทัดคำสั่งเท่านั้น\n");
}

$base = $argv[1] ?? '';
if ($base === '') {
    echo "\nวิธีใช้:  php tools/check-live.php https://โดเมนของคุณ\n\n";
    exit(2);
}

$base = rtrim($base, '/');
if (preg_match('#^https?://#i', $base) !== 1) {
    $base = 'https://' . $base;
}

/** ไฟล์ที่ห้ามโหลดได้เด็ดขาด — เรียงตามความร้ายแรง */
$mustBlock = [
    '/.env' => '⚠️ ร้ายแรงที่สุด — มีรหัสฐานข้อมูลและรหัสอีเมล',
    '/.env.example' => 'ตัวอย่างการตั้งค่า',
    '/composer.json' => 'รายชื่อไลบรารี (ไว้ให้คนหาช่องโหว่)',
    '/composer.lock' => 'เวอร์ชันไลบรารีแบบเป๊ะ ๆ',
    '/database/schema.sql' => 'โครงสร้างฐานข้อมูล',
    '/CLAUDE.md' => 'เอกสารภายใน',
    '/README.md' => 'เอกสารภายใน',
    '/tests/bootstrap.php' => 'ไฟล์ในชุดทดสอบ',
    '/vendor/autoload.php' => 'ไฟล์ไลบรารี',
    '/includes/config.php' => 'ไฟล์ตั้งค่าของระบบ',
    '/app/Services/RecordService.php' => 'ซอร์สโค้ด',
    '/tools/check-deploy.php' => 'ตัวตรวจของผู้ดูแลระบบ',
    '/logs/' => 'โฟลเดอร์บันทึกข้อผิดพลาด',
    '/phpunit.xml' => 'ไฟล์ตั้งค่าเครื่องมือ',
];

/** หน้าที่ต้องเปิดได้ตามปกติ — กันการ "แก้" ที่ปิดทั้งเว็บ */
$mustWork = [
    '/login.php' => 'หน้าเข้าสู่ระบบ',
    '/dashboard.php' => 'แดชบอร์ด (ต้องเด้งไปหน้าเข้าสู่ระบบ)',
];

/** @return array{status:int, size:int, error:string} */
$fetch = static function (string $url): array {
    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($handle, CURLOPT_TIMEOUT, 15);
    curl_setopt($handle, CURLOPT_USERAGENT, 'ad-profit-check-live');

    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = (string)curl_error($handle);

    return [
        'status' => $status,
        'size' => is_string($body) ? strlen($body) : 0,
        'error' => $error,
    ];
};

$line = str_repeat('─', 68);
echo "\n", $line, "\n  ตรวจเว็บจริง: ", $base, "\n", $line, "\n\n";

$leaks = [];
$broken = [];
$unreachable = 0;

echo "  ── ไฟล์ที่ต้องโหลดไม่ได้ ", str_repeat('─', 38), "\n\n";

foreach ($mustBlock as $path => $why) {
    $result = $fetch($base . $path);

    if ($result['error'] !== '') {
        echo '  ⚠️  ', str_pad($path, 34), ' ติดต่อไม่ได้: ', $result['error'], "\n";
        $unreachable++;
        continue;
    }

    // 403 = ถูกปิด · 404 = ไม่มีไฟล์ (ปลอดภัยเหมือนกัน) · 301/302 = ถูกพาไปที่อื่น
    $safe = in_array($result['status'], [401, 403, 404], true)
        || ($result['status'] >= 300 && $result['status'] < 400);

    if ($safe) {
        echo '  ✅ ', str_pad($path, 34), ' ', $result['status'], "\n";
        continue;
    }

    echo '  ❌ ', str_pad($path, 34), ' ', $result['status'],
        ' — โหลดได้ ', number_format($result['size']), " ไบต์  ", $why, "\n";
    $leaks[] = $path;
}

echo "\n  ── หน้าที่ต้องเปิดได้ตามปกติ ", str_repeat('─', 34), "\n\n";

foreach ($mustWork as $path => $label) {
    $result = $fetch($base . $path);

    if ($result['error'] !== '') {
        echo '  ⚠️  ', str_pad($path, 34), ' ติดต่อไม่ได้: ', $result['error'], "\n";
        $unreachable++;
        continue;
    }

    $ok = $result['status'] === 200 || ($result['status'] >= 300 && $result['status'] < 400);
    echo($ok ? '  ✅ ' : '  ❌ '), str_pad($path, 34), ' ', $result['status'], '  ', $label, "\n";

    if (!$ok) {
        $broken[] = $path;
    }
}

// ── ลิงก์ที่ไม่มีอยู่จริง ต้องเข้าหน้าแจ้งข้อผิดพลาดของแอป ──────────────
echo "\n  ── ลิงก์ผิดต้องเจอหน้าภาษาไทยของแอป ", str_repeat('─', 26), "\n\n";

$notFound = $fetch($base . '/หน้านี้ไม่มีอยู่จริง-' . bin2hex(random_bytes(4)));
if ($notFound['error'] !== '') {
    echo '  ⚠️  ติดต่อไม่ได้: ', $notFound['error'], "\n";
    $unreachable++;
} else {
    $ok = $notFound['status'] === 404 && $notFound['size'] > 500;
    echo($ok ? '  ✅ ' : '  ⚠️  '), 'ลิงก์ที่ไม่มีอยู่ → ', $notFound['status'],
        ' (', number_format($notFound['size']), " ไบต์)\n";
    if (!$ok) {
        echo "      → ถ้าได้หน้าเปล่าภาษาอังกฤษของเซิร์ฟเวอร์ แปลว่า .htaccess ไม่ถูกอ่าน\n";
    }
}

// ── สรุป ──────────────────────────────────────────────────────────────────
echo "\n", $line, "\n";

if ($unreachable > 0 && $leaks === [] && $broken === []) {
    echo "  ติดต่อเว็บไม่ได้ ", $unreachable, " ครั้ง — ตรวจที่อยู่เว็บและการเชื่อมต่อ\n", $line, "\n\n";
    exit(2);
}

if ($leaks !== []) {
    echo "  🔴 ไฟล์ลับโหลดได้ ", count($leaks), " ไฟล์:\n";
    foreach ($leaks as $path) {
        echo "     ", $path, "\n";
    }
    echo "\n  ทำทันที:\n";
    if (in_array('/.env', $leaks, true)) {
        echo "  1) เปลี่ยนรหัสฐานข้อมูลและรหัสอีเมล — ถือว่าหลุดไปแล้ว\n";
        echo "  2) อัปโหลดไฟล์ .htaccess ที่รากเว็บ แล้วรันตัวตรวจนี้ใหม่\n";
    } else {
        echo "  1) อัปโหลดไฟล์ .htaccess ที่รากเว็บ แล้วรันตัวตรวจนี้ใหม่\n";
    }
    echo $line, "\n\n";
    exit(1);
}

if ($broken !== []) {
    echo "  ⚠️ หน้าที่ควรเปิดได้ กลับเปิดไม่ได้ ", count($broken), " หน้า — กฎกันไฟล์อาจเข้มเกินไป\n";
    echo $line, "\n\n";
    exit(1);
}

echo "  ✅ ไม่มีไฟล์ลับหลุด และหน้าเว็บเปิดได้ปกติ\n";
echo "\n  ⚠️ เหลืออีก 2 อย่างที่ตรวจแทนไม่ได้:\n";
echo "     · อีเมลลืมรหัสผ่าน ถึงกล่องจดหมายจริงไหม (ดูโฟลเดอร์ขยะด้วย)\n";
echo "     · เลขในรายงาน ตรงกับที่คุณนับเองไหม (ลองสัก 1 เดือน)\n";
echo $line, "\n\n";
exit(0);
