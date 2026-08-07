<?php

declare(strict_types=1);

/**
 * ⭐⭐ ตรวจว่าโค้ดของเรา "รันได้จริง" บนเวอร์ชัน PHP ที่เซิร์ฟเวอร์จริงใช้
 *
 * ⚠️⚠️ ทำไมต้องมีตัวนี้ ทั้งที่มีเทสต์ 1,199 ตัวแล้ว:
 *   test suite ใช้ PHPUnit 13 ซึ่ง require php >= 8.4.1 → **รันบน 8.3 ไม่ได้เลย**
 *   แต่เซิร์ฟเวอร์จริง (Hostinger) ใช้ **8.3** · ที่ผ่านมาจึงพิสูจน์ได้แค่
 *     · syntax ผ่าน (`php -l`)
 *     · ติดตั้ง dependency ได้ (`composer install --no-dev`)
 *   ส่วน **พฤติกรรมตอนรันของโค้ดเราเอง บน 8.3 ไม่เคยมีใครพิสูจน์**
 *
 * ตัวนี้จึงเขียนแบบไม่พึ่ง PHPUnit เลย — รันด้วย php ตัวไหนก็ได้:
 *     php tests/smoke/pages.php http://127.0.0.1:8080
 *
 * ⚠️ เซิร์ฟเวอร์ที่ทดสอบ **ต้องรันด้วย APP_ENV=development** ไม่งั้น `display_errors`
 * ปิดอยู่ คำเตือนจะไม่ถูกพิมพ์ออกมา แล้วตัวนี้จะเขียวโดยไม่ได้ตรวจอะไรเลย
 * (บทเรียนเดียวกับ `PageRenderTest` — เคยพลาดมาแล้ว)
 */

$baseUrl = rtrim((string)($argv[1] ?? getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'smoke-cookies-');
$failures = [];
$checked = 0;

register_shutdown_function(static function () use ($cookieJar): void {
    @unlink($cookieJar);
});

/**
 * @param array<string,string>|null $post
 * @return array{status:int,body:string,url:string}
 */
function request(string $url, ?array $post = null): array
{
    global $cookieJar;

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = (string)curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    // ⚠️ ไม่เรียก `curl_close()` — ไม่มีผลตั้งแต่ PHP 8.0 และถูกประกาศเลิกใช้ใน 8.5
    // ตัวตรวจที่ปล่อยคำเตือนออกมาเองจะไปปนกับคำเตือนที่กำลังตามหา

    return ['status' => $status, 'body' => $body, 'url' => $finalUrl];
}

function csrfTokenFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/**
 * ⚠️ ต้อง `strip_tags()` ก่อนหา — PHP ห่อข้อความไว้เป็น HTML (`<b>Warning</b>:`)
 * การหาสตริง "Warning:" ตรง ๆ จึงไม่มีวันเจอ
 */
function problemsIn(string $html): array
{
    $plain = strip_tags($html);
    $found = [];

    foreach (['Fatal error', 'Parse error', 'Warning', 'Notice', 'Deprecated'] as $needle) {
        if (stripos($plain, $needle . ':') !== false) {
            $offset = (int)stripos($plain, $needle . ':');
            $found[] = $needle . ' → ' . trim(substr($plain, $offset, 160));
        }
    }

    return $found;
}

echo "ตรวจ {$baseUrl} ด้วย PHP " . PHP_VERSION . "\n\n";

// ── 1) สมัครสมาชิกผ่านฟอร์มจริง (ได้ทั้ง user + ร้านเริ่มต้นในทางเดียว) ──────
$login = request($baseUrl . '/login.php');
$token = csrfTokenFrom($login['body']);
if ($token === '') {
    fwrite(STDERR, "⛔ หา csrf_token ในหน้า login ไม่เจอ — เซิร์ฟเวอร์ไม่พร้อม\n");
    exit(1);
}

$email = 'smoke-' . bin2hex(random_bytes(4)) . '@example.com';
$registered = request($baseUrl . '/api/auth.php', [
    'csrf_token' => $token,
    'action' => 'register',
    'email' => $email,
    'password' => 'SmokeTest2569!',
    'password_confirm' => 'SmokeTest2569!',
]);

if (!str_contains($registered['url'], 'dashboard.php')) {
    fwrite(STDERR, "⛔ สมัครสมาชิกไม่สำเร็จ — ปลายทาง: {$registered['url']}\n");
    fwrite(STDERR, substr(strip_tags($registered['body']), 0, 400) . "\n");
    exit(1);
}
echo "  ✓ สมัครสมาชิก + สร้างร้านเริ่มต้น\n";

// ── 2) บันทึกข้อมูล 1 วัน เพื่อให้ทุกหน้ามีของจริงให้เรนเดอร์ ────────────────
$addRecord = request($baseUrl . '/add-record.php');
$token = csrfTokenFrom($addRecord['body']);
preg_match('/name="shop_context_id"\s+value="(\d+)"/', $addRecord['body'], $shopMatch);

$saved = request($baseUrl . '/api/records.php', [
    'csrf_token' => $token,
    'shop_context_id' => $shopMatch[1] ?? '0',
    'action' => 'upsert',
    'record_date' => date('Y-m-d'),
    'revenue' => '12500.50',
    'ad_cost' => '4200',
    'note' => 'smoke test',
]);
echo '  ' . ($saved['status'] === 200 ? '✓' : '⚠️') . " บันทึกข้อมูล 1 วัน\n";

// ── 3) เปิดทุกหน้า + ทุก endpoint ที่คืน JSON ────────────────────────────────
$pages = [
    '/index.php', '/dashboard.php', '/add-record.php', '/history.php',
    '/annual.php', '/overview.php', '/shops.php', '/profile.php',
    // ช่วง/เดือน/ปีแบบต่าง ๆ — เส้นทางที่คำนวณต่างกันคนละกิ่ง
    '/dashboard.php?range=week_this', '/dashboard.php?range=month_last',
    '/dashboard.php?range=custom&start_date=' . date('Y-m-01') . '&end_date=' . date('Y-m-d'),
    '/history.php?month=' . date('Y-m'),
    '/annual.php?year=' . (int)(date('Y') + 543),
    '/overview.php?view=day', '/overview.php?view=year',
];

$jsonEndpoints = [
    '/api/dashboard-data.php', '/api/annual-data.php', '/api/overview-data.php',
    '/api/month-grid.php?month=' . date('Y-m'),
];

$downloads = ['/api/export.php?month=' . date('Y-m'), '/api/export-xlsx.php?year=' . (int)(date('Y') + 543)];

foreach ($pages as $path) {
    $response = request($baseUrl . $path);
    $checked++;

    if ($response['status'] !== 200) {
        $failures[] = "{$path} → HTTP {$response['status']}";
        continue;
    }

    foreach (problemsIn($response['body']) as $problem) {
        $failures[] = "{$path} → {$problem}";
    }
}
echo "  ✓ เปิดหน้าเว็บ " . count($pages) . " หน้า\n";

foreach ($jsonEndpoints as $path) {
    $response = request($baseUrl . $path);
    $checked++;

    if ($response['status'] !== 200) {
        $failures[] = "{$path} → HTTP {$response['status']}";
        continue;
    }

    if (json_decode($response['body'], true) === null) {
        $failures[] = "{$path} → ตอบไม่ใช่ JSON: " . substr(strip_tags($response['body']), 0, 120);
    }
}
echo '  ✓ endpoint ที่คืน JSON ' . count($jsonEndpoints) . " ตัว\n";

foreach ($downloads as $path) {
    $response = request($baseUrl . $path);
    $checked++;

    if ($response['status'] !== 200) {
        $failures[] = "{$path} → HTTP {$response['status']}";
        continue;
    }

    // ⚠️ ไฟล์ที่ดาวน์โหลดต้องไม่มีคำเตือนของ PHP ปนอยู่ข้างใน — ถ้าปนจะเปิดไม่ขึ้น
    foreach (problemsIn(substr($response['body'], 0, 4000)) as $problem) {
        $failures[] = "{$path} (ไฟล์ดาวน์โหลด) → {$problem}";
    }

    if (trim($response['body']) === '') {
        $failures[] = "{$path} → ไฟล์ว่างเปล่า";
    }
}
echo '  ✓ ไฟล์ดาวน์โหลด ' . count($downloads) . " ไฟล์\n";

// ── สรุป ────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures === []) {
    echo "✅ ผ่านทั้งหมด {$checked} รายการ บน PHP " . PHP_VERSION . "\n";
    exit(0);
}

fwrite(STDERR, "⛔ พบปัญหา " . count($failures) . " รายการ บน PHP " . PHP_VERSION . ":\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "   · {$failure}\n");
}
exit(1);
