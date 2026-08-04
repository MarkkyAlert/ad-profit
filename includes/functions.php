<?php

declare(strict_types=1);

function app_path(string $path = ''): string
{
    $basePath = dirname(__DIR__);
    if ($path === '') {
        return $basePath;
    }

    return $basePath . '/' . ltrim($path, '/');
}

function app_url(string $path = ''): string
{
    $baseUrl = APP_URL;

    if ($path === '') {
        return $baseUrl;
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $normalizedPath = '/' . ltrim($path, '/');

    return $baseUrl . $normalizedPath;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * เงินบาท — แสดงสตางค์ "เฉพาะตอนที่มีจริง"
 *
 * ⚠️ เดิมตัดสตางค์ทิ้งทุกช่อง ทำให้แถวรวมไม่เท่ากับผลบวกของแถวที่ผู้ใช้เห็น
 * (3 แถว แถวละ ฿100.40 → เห็น ฿100 ×3 แต่แถวรวมขึ้น ฿301)
 * ตัวเลขกลม ๆ ยังแสดงเหมือนเดิมเพื่อไม่ให้หน้าจอรก
 */
function formatMoney(float|int $value): string
{
    $amount = (float)$value;
    $decimals = abs($amount - round($amount)) > 0.0000001 ? 2 : 0;

    return '฿' . number_format($amount, $decimals);
}

function formatRoas(?float $value): string
{
    return $value === null ? '–' : number_format($value, 2);
}

function formatPercent(?float $value): string
{
    return $value === null ? '–' : number_format($value, 1) . '%';
}

/**
 * ป้าย "เปลี่ยนแปลงกี่ %" พร้อมลูกศรและสี — ใช้ร่วมกันทุกหน้า
 *
 * เดิมแต่ละหน้าเขียนเองแล้วออกมาไม่ตรงกัน: `annual.php`/`overview.php` (มุมรายปี)
 * ถือว่า 0% เป็นกลาง แต่ `history.php`/`dashboard.php`/ตารางรายปีในหน้ารวมร้าน
 * ใช้ `>= 0` จึงขึ้น "↑ 0.0%" สีเขียว ทั้งที่ยอดเท่าเดิมเป๊ะ
 *
 * ตัดสินจากค่า "หลังปัดทศนิยม 1 ตำแหน่ง" เพื่อให้ลูกศรกับตัวเลขที่เห็นตรงกันเสมอ
 * (−0.04% ปัดแล้วเป็น 0.0% ต้องไม่ขึ้นลูกศรลงสีแดงคู่กับเลขศูนย์)
 *
 * @return array{text:string,class:string,direction:int} direction: 1 ขึ้น · 0 เท่าเดิม · -1 ลง
 */
function format_change_badge(?float $percent, string $nullText = '–'): array
{
    if ($percent === null) {
        return ['text' => $nullText, 'class' => 'text-slate-400', 'direction' => 0];
    }

    $rounded = round($percent, 1);
    $direction = $rounded <=> 0.0;

    $arrow = match ($direction) {
        1 => '↑ ',
        -1 => '↓ ',
        default => '',
    };

    $class = match ($direction) {
        1 => 'text-green-400',
        -1 => 'text-red-400',
        default => 'text-slate-400',
    };

    return [
        'text' => $arrow . number_format(abs($rounded), 1) . '%',
        'class' => $class,
        'direction' => $direction,
    ];
}

function formatThaiDate(string $date): string
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        return $date;
    }

    $thaiMonths = [
        '01' => 'ม.ค.',
        '02' => 'ก.พ.',
        '03' => 'มี.ค.',
        '04' => 'เม.ย.',
        '05' => 'พ.ค.',
        '06' => 'มิ.ย.',
        '07' => 'ก.ค.',
        '08' => 'ส.ค.',
        '09' => 'ก.ย.',
        '10' => 'ต.ค.',
        '11' => 'พ.ย.',
        '12' => 'ธ.ค.',
    ];

    $month = $thaiMonths[$dateObject->format('m')] ?? $dateObject->format('m');
    $thaiYear = (int)$dateObject->format('Y') + 543;

    return $dateObject->format('j') . ' ' . $month . ' ' . $thaiYear;
}

function formatThaiMonth(string $month): string
{
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return $month;
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $month . '-01');
    if (!$dateObject) {
        return $month;
    }

    $thaiMonths = [
        '01' => 'ม.ค.',
        '02' => 'ก.พ.',
        '03' => 'มี.ค.',
        '04' => 'เม.ย.',
        '05' => 'พ.ค.',
        '06' => 'มิ.ย.',
        '07' => 'ก.ค.',
        '08' => 'ส.ค.',
        '09' => 'ก.ย.',
        '10' => 'ต.ค.',
        '11' => 'พ.ย.',
        '12' => 'ธ.ค.',
    ];

    $monthText = $thaiMonths[$dateObject->format('m')] ?? $dateObject->format('m');
    $thaiYear = (int)$dateObject->format('Y') + 543;

    return $monthText . ' ' . $thaiYear;
}

/**
 * แปลงเลขวันในสัปดาห์แบบ ISO-8601 (1 = จันทร์ … 7 = อาทิตย์) เป็นชื่อวันภาษาไทย
 * นอกช่วง 1–7 → คืนค่าว่าง
 */
function formatThaiWeekday(int $weekday): string
{
    $thaiWeekdays = [
        1 => 'จันทร์',
        2 => 'อังคาร',
        3 => 'พุธ',
        4 => 'พฤหัสบดี',
        5 => 'ศุกร์',
        6 => 'เสาร์',
        7 => 'อาทิตย์',
    ];

    return $thaiWeekdays[$weekday] ?? '';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function is_post_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_api_request(): bool
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    return str_contains($scriptName, '/api/');
}

function wants_json_response(): bool
{
    $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_respond(array $payload, int $statusCode, string $redirectUrl, bool $wantsJson): never
{
    if ($wantsJson) {
        jsonResponse($payload, $statusCode);
    }

    if (($payload['success'] ?? false) === true) {
        if (isset($payload['message'])) {
            set_flash('success', (string)$payload['message']);
        }
    } elseif (isset($payload['error'])) {
        set_flash('error', (string)$payload['error']);
    }

    redirect($redirectUrl);
}

function resolve_safe_redirect_path(string $fallback, ?string $postRedirectTo = null, ?string $referer = null): string
{
    $basePath = (string)(parse_url(APP_URL, PHP_URL_PATH) ?? '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');

    $candidates = [];
    if (is_string($postRedirectTo) && trim($postRedirectTo) !== '') {
        $candidates[] = $postRedirectTo;
    }
    if (is_string($referer) && trim($referer) !== '') {
        $candidates[] = $referer;
    }

    foreach ($candidates as $candidateRaw) {
        $candidate = trim($candidateRaw);
        if ($candidate === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $parsedUrl = parse_url($candidate);
            if (!is_array($parsedUrl)) {
                continue;
            }

            $path = (string)($parsedUrl['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $query = (string)($parsedUrl['query'] ?? '');
            $candidate = $path . ($query !== '' ? '?' . $query : '');
        }

        if (!str_starts_with($candidate, '/')) {
            continue;
        }

        if (str_starts_with($candidate, '//')) {
            continue;
        }

        if ($basePath !== '') {
            if ($candidate === $basePath) {
                return $fallback;
            }

            if (str_starts_with($candidate, $basePath . '/')) {
                $candidate = substr($candidate, strlen($basePath));
                if ($candidate === '') {
                    return $fallback;
                }
            }
        }

        return $candidate;
    }

    return $fallback;
}

function ensure_post_request_or_respond(bool $wantsJson, string $redirectUrl): void
{
    if (!is_post_request()) {
        // ⚠️ 405 ต้องมาคู่กับ header `Allow` เสมอ (RFC 9110 บังคับ) ไม่งั้นฝั่งที่เรียก
        // ได้แต่ "ห้ามใช้วิธีนี้" โดยไม่มีทางรู้ว่าต้องใช้วิธีไหนแทน
        if (!headers_sent()) {
            header('Allow: POST');
        }

        api_respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, $redirectUrl, $wantsJson);
    }
}

/**
 * ตรวจว่า body ใหญ่เกิน post_max_size จน PHP ทิ้ง $_POST/$_FILES ทั้งก้อนหรือไม่
 *
 * เมื่อเกิดเคสนี้ $_POST จะว่างเปล่าทั้งที่ Content-Length มีค่า → ทุก endpoint อ่าน
 * $action ไม่เจอแล้วตกไปตอบ "Invalid action" (404) ซึ่งไม่มีทางเดาได้ว่าเกิดอะไรขึ้น
 * เดิมเป็นปัญหาชัดที่สุดตอนอัปโหลด CSV ใหญ่ (ผู้ใช้เห็น 404 แทน "ไฟล์ใหญ่เกิน")
 */
function ensure_post_body_not_truncated_or_respond(bool $wantsJson, string $redirectUrl): void
{
    if (!is_post_request() || $_POST !== [] || $_FILES !== []) {
        return;
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0) {
        return;
    }

    $limit = trim((string)ini_get('post_max_size'));
    error_log(sprintf(
        '[request] POST body dropped by PHP: content_length=%d post_max_size=%s',
        $contentLength,
        $limit
    ));

    api_respond([
        'success' => false,
        'error' => 'ข้อมูลที่ส่งมาใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้'
            . ($limit !== '' ? ' (จำกัด ' . $limit . ')' : '')
            . ' กรุณาลดขนาดไฟล์แล้วลองใหม่',
    ], 413, $redirectUrl, $wantsJson);
}

function ensure_form_content_type_or_respond(bool $wantsJson, string $redirectUrl): void
{
    if (!is_post_request()) {
        return;
    }

    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($contentType === '') {
        // Some clients may omit Content-Type for empty bodies; allow for small-system compatibility.
        return;
    }

    $isUrlEncoded = str_starts_with($contentType, 'application/x-www-form-urlencoded');
    $isMultipart = str_starts_with($contentType, 'multipart/form-data');

    if ($isUrlEncoded || $isMultipart) {
        return;
    }

    api_respond([
        'success' => false,
        'error' => 'Unsupported Media Type',
    ], 415, $redirectUrl, $wantsJson);
}

function ensure_valid_csrf_or_respond(bool $wantsJson, string $redirectUrl, ?string $token = null): void
{
    if (!verify_csrf($token)) {
        api_respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, $redirectUrl, $wantsJson);
    }
}

/**
 * ช่องซ่อนบอกว่า "หน้านี้ถูกเรนเดอร์ให้ร้านไหน"
 *
 * ทุก endpoint ที่เขียนข้อมูลอ่านรหัสร้านจาก `$_SESSION['current_shop_id']` ซึ่งเปลี่ยนได้
 * จากอีกแท็บ — เปิดหน้าบันทึกของร้าน A ค้างไว้ สลับไปร้าน B ในอีกแท็บ แล้วกลับมากดบันทึก
 * ข้อมูลจะลงร้าน B และถ้าร้าน B มีวันนั้นอยู่แล้ว ตัวเลขจริงจะถูกเขียนทับ พร้อมข้อความ
 * "บันทึกเรียบร้อยแล้ว" สีเขียว
 *
 * ไม่ใช่กลไกความปลอดภัย (สิทธิ์ยังตรวจที่ Service เหมือนเดิม) — เป็นการกันอุบัติเหตุ
 * ของผู้ใช้กับร้านของตัวเอง
 */
/**
 * รายการร้านของผู้ใช้ + ร้านที่กำลังใช้งาน พร้อม "ซ่อม" session ให้ถ้าชี้ไปร้านที่หายไปแล้ว
 *
 * ⚠️ ทุกเพจต้องเรียกตัวนี้ "ก่อน" ดึงข้อมูลใด ๆ
 * เดิมการซ่อมอยู่ใน `includes/header.php` ซึ่งถูก include ท้ายไฟล์ — หน้าเว็บจึงยิง query
 * ด้วยรหัสร้านที่ตายแล้วไปก่อน ได้ผลลัพธ์ "คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้" กับยอด ฿0
 * ทั้งหน้า แล้วค่อยซ่อม session ทีหลัง (รีเฟรชอีกครั้งถึงจะปกติ) — เกิดจริงเมื่อผู้ใช้
 * ลบร้านจากอีกอุปกรณ์/อีกเบราว์เซอร์ที่ล็อกอินบัญชีเดียวกัน
 *
 * cache ต่อ request เพื่อไม่ให้ header.php ยิง query ซ้ำอีกรอบ
 *
 * @return array{shops:array<int,array<string,mixed>>,current_shop:array<string,mixed>|null,switched:bool}
 */
function shop_context_for_user(PDO $pdo, int $userId): array
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $requestedShopId = (int)($_SESSION['current_shop_id'] ?? 0);
    $context = (new ShopService(new ShopRepository($pdo), new UserRepository($pdo)))
        ->getShopContext($userId, $requestedShopId > 0 ? $requestedShopId : null);

    $shops = is_array($context['shops'] ?? null) ? array_values((array)$context['shops']) : [];
    $currentShop = is_array($context['current_shop'] ?? null) ? (array)$context['current_shop'] : null;
    $currentShopId = $currentShop !== null ? (int)($currentShop['id'] ?? 0) : 0;

    // ร้านที่ session ชี้อยู่หายไป (ถูกลบจากอุปกรณ์อื่น) แล้วระบบเลือกร้านอื่นให้แทน
    $switched = $requestedShopId > 0 && $currentShopId > 0 && $currentShopId !== $requestedShopId;

    if ($currentShop !== null) {
        $_SESSION['current_shop_id'] = $currentShopId;
        $_SESSION['current_shop_name'] = (string)($currentShop['name'] ?? '');
    }

    $cache[$userId] = [
        'shops' => $shops,
        'current_shop' => $currentShop,
        'switched' => $switched,
    ];

    return $cache[$userId];
}

/**
 * รหัสร้านที่กำลังใช้งาน หลังซ่อม session แล้ว — สิ่งที่เพจควรใช้แทนการอ่าน session ตรง ๆ
 *
 * บอกผู้ใช้ด้วยว่าถูกสลับร้านให้ ไม่งั้นจะงงว่าทำไมตัวเลขเปลี่ยนไปทั้งหน้า
 */
function resolve_current_shop_id(PDO $pdo, int $userId): int
{
    $context = shop_context_for_user($pdo, $userId);

    if (($context['switched'] ?? false) === true) {
        $shopName = (string)($context['current_shop']['name'] ?? '');
        set_flash(
            'error',
            'ร้านที่คุณเปิดค้างไว้ไม่มีอยู่แล้ว (อาจถูกลบจากอุปกรณ์อื่น) '
            . 'ระบบสลับไปที่ร้าน "' . $shopName . '" ให้แล้ว'
        );
    }

    return $context['current_shop'] !== null ? (int)($context['current_shop']['id'] ?? 0) : 0;
}

function shop_context_field(int $shopId): string
{
    return '<input type="hidden" name="shop_context_id" value="' . (int)$shopId . '">';
}

/**
 * ปฏิเสธเมื่อฟอร์มถูกเรนเดอร์ให้ร้านหนึ่ง แต่ session ชี้ไปอีกร้านแล้ว
 *
 * ฟอร์มที่ไม่ได้ส่งค่านี้มา (หน้าที่ค้างอยู่ตั้งแต่ก่อนอัปเดต) ทำงานเหมือนเดิม —
 * ตั้งใจให้ผ่อนผัน เพราะกันอุบัติเหตุ ไม่ได้กันการโจมตี
 */
function ensure_shop_context_or_respond(bool $wantsJson, string $redirectUrl, int $sessionShopId): void
{
    $submittedShopId = isset($_POST['shop_context_id']) ? (int)$_POST['shop_context_id'] : 0;

    if ($submittedShopId <= 0 || $submittedShopId === $sessionShopId) {
        return;
    }

    api_respond(
        [
            'success' => false,
            'error' => 'หน้านี้เปิดค้างไว้ตอนที่คุณอยู่อีกร้านหนึ่ง '
                . 'ระบบไม่บันทึกให้เพื่อไม่ให้ข้อมูลลงผิดร้าน กรุณาโหลดหน้านี้ใหม่แล้วลองอีกครั้ง',
        ],
        409,
        $redirectUrl,
        $wantsJson
    );
}

function normalize_month_input(?string $month, ?string $fallback = null): string
{
    $normalizedFallback = is_string($fallback) && preg_match('/^\d{4}-\d{2}$/', $fallback) === 1
        ? $fallback
        : date('Y-m');

    if (!is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return $normalizedFallback;
    }

    return $month;
}

/**
 * แปลงปีที่รับจาก query string ให้เป็น ค.ศ. ที่ใช้งานได้เสมอ
 *
 * รับได้ทั้ง ค.ศ. และ พ.ศ. (2400–2700 → −543) ค่าที่ใช้ไม่ได้ตกไปที่ $fallbackYear
 * แล้วจึงเป็นปีปัจจุบัน ผลลัพธ์อยู่ในช่วง 2000–2100 เสมอ (ช่วงเดียวกับที่ Service ยอมรับ)
 *
 * รวมตรรกะที่เคยคัดลอกอยู่ 4 ที่ (annual.php, overview.php, api/annual-data.php,
 * api/export-xlsx.php) ซึ่ง 3 ที่เหมือนกันแต่ api/export-xlsx.php ไม่มีทั้งการตรวจรูปแบบ
 * และการ clamp → หน้าเดียวกันตอบต่างกันเมื่อได้ ?year ที่ใช้ไม่ได้
 */
function resolve_calendar_year(mixed $rawYear, mixed $fallbackYear = null): int
{
    $normalize = static function (mixed $value): ?int {
        $text = trim((string)$value);
        if (preg_match('/^\d{4}$/', $text) !== 1) {
            return null;
        }

        $year = (int)$text;
        if ($year >= 2400 && $year <= 2700) {
            $year -= 543; // พ.ศ. → ค.ศ.
        }

        return ($year >= 2000 && $year <= 2100) ? $year : null;
    };

    $currentYear = (int)date('Y');
    $resolved = $normalize($rawYear) ?? $normalize($fallbackYear) ?? $currentYear;

    // ⚠️ ไม่รับปีอนาคต — เกณฑ์เดียวกับ `resolve_calendar_month()`
    // เดิม `?year=2573` (พ.ศ. → ค.ศ. 2030) เปิดได้ แล้วหน้าขึ้นทุกการ์ดเป็น ฿0 พร้อม
    // คำเชิญ "ลองเริ่มบันทึกข้อมูล" ของปีที่ยังมาไม่ถึง
    return $resolved > $currentYear ? $currentYear : $resolved;
}

/**
 * แปลงเดือนที่รับจาก query string ให้ใช้งานได้เสมอ และไม่เกินเดือนปัจจุบัน
 *
 * ค่าที่ใช้ไม่ได้ตกไปที่ $fallbackMonth แล้วจึงเป็นเดือนปัจจุบัน · เดือนอนาคตถูกดึงกลับ
 * มาเป็นเดือนปัจจุบัน — เลือกเดือนหน้าแล้วเห็นหน้าเต็มที่เป็น ฿0 พร้อม "เทียบเดือนก่อน
 * −100%" ไม่ได้สื่ออะไร (ฝั่งรายปีมี cutoff แบบนี้อยู่แล้วใน AnnualService/OverviewAnnualService)
 *
 * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
 */
/**
 * วันสุดท้ายที่ควรใช้เทียบของเดือนหนึ่ง — เดือนปัจจุบันตัดที่วันนี้ · เดือนที่จบแล้วใช้ทั้งเดือน (null)
 *
 * ⚠️ ใช้ร่วมกันระหว่าง `DashboardService` และ `OverviewService` — เดิมมีแค่แดชบอร์ดที่ตัดวัน
 * หน้ารวมร้านจึงเทียบ "4 วันแรกของเดือนนี้" กับ "ทั้งเดือนก่อน" ได้ −87.1% ขณะที่แดชบอร์ด
 * บอก 0% สำหรับข้อมูลชุดเดียวกัน (พิสูจน์แล้วว่าเกิดจริง)
 */
function resolve_comparison_cutoff_day(string $selectedMonth, ?string $today = null): ?int
{
    $todayInput = is_string($today) ? trim($today) : '';
    $todayObject = $todayInput !== ''
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $todayInput)
        : false;

    if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
        $todayObject = new DateTimeImmutable('today');
    }

    return $selectedMonth === $todayObject->format('Y-m') ? (int)$todayObject->format('j') : null;
}

/**
 * วันสุดท้ายของช่วงเทียบในรูป Y-m-d — หดวันตัดให้พอดีกับเดือนที่สั้นกว่า
 * (ตัดวันที่ 31 กับเดือน ก.พ. ต้องได้ทั้งเดือน ไม่ใช่ช่วงว่าง)
 */
function comparison_range_end(string $month, ?int $cutoffDay): string
{
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    if (!$monthStart) {
        return $month . '-01';
    }

    $lastDay = (int)$monthStart->format('t');
    $endDay = $cutoffDay === null ? $lastDay : max(1, min($cutoffDay, $lastDay));

    return $monthStart->setDate(
        (int)$monthStart->format('Y'),
        (int)$monthStart->format('n'),
        $endDay
    )->format('Y-m-d');
}

function resolve_calendar_month(mixed $rawMonth, mixed $fallbackMonth = null, ?string $today = null): string
{
    $normalize = static function (mixed $value): ?string {
        $text = trim((string)$value);

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $text) === 1 ? $text : null;
    };

    $todayInput = is_string($today) ? trim($today) : '';
    $todayObject = $todayInput !== ''
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $todayInput)
        : false;
    if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
        $todayObject = new DateTimeImmutable('today');
    }

    $currentMonth = $todayObject->format('Y-m');
    $resolved = $normalize($rawMonth) ?? $normalize($fallbackMonth) ?? $currentMonth;

    return $resolved > $currentMonth ? $currentMonth : $resolved;
}

/**
 * แปลงข้อความจำนวนเงินให้เป็นสตริงตัวเลขล้วน — คืน null เมื่ออ่านไม่ได้หรือ "กำกวม"
 *
 * ⚠️ ตัวนี้คือจุดเดียวของกฎการอ่านตัวเลขเงินทั้งระบบ — ฟอร์มเดี่ยว · ตารางหลายวัน ·
 * เป้าหมาย · ไฟล์ CSV ต้องเรียกผ่านตัวนี้ทั้งหมด (คู่แฝดฝั่ง JS อยู่ที่ `add-record.php`)
 *
 * ประวัติ: เดิมลบจุลภาคทิ้งเสมอ → ไฟล์ยุโรป `1234,56` กลายเป็น 123,456 (100 เท่า)
 * แล้วรอบต่อมาเปลี่ยนเป็น "เดาตัวคั่นเอง" → `1.234` กลายเป็น 1,234 (1000 เท่า)
 * และ `1.2.3` ถูกยอมรับเป็น 12.30 · ตอนนี้ใช้หลักเดียวกับ "วันที่กำกวม": อ่านได้
 * หลายแบบ = ปฏิเสธ อย่าเดา
 *
 * รับ: `1234` `1234.56` `1234,56` `1,234.56` `1.234,56` `1,234,567.89` `1 234,56` `฿1,234.56`
 * ปฏิเสธ: `1.234` / `1,234` (กำกวม — เป็นได้ทั้งหลักพันและทศนิยม 3 ตำแหน่ง) ·
 *         `1.2.3` · `1..2` · `1e3` · ทศนิยมเกิน 2 ตำแหน่ง
 */
function normalize_money_string(string $raw): ?string
{
    $value = trim($raw);

    // ตัวกันสูตรของ Excel ที่ export ใส่ไว้
    if ($value !== '' && $value[0] === "'") {
        $value = substr($value, 1);
    }

    // สัญลักษณ์เงินและช่องว่างทุกชนิดไม่ใช่ตัวคั่นทศนิยมแน่นอน
    $value = str_replace(['฿', ' ', "\xC2\xA0"], '', trim($value));

    if ($value === '') {
        return null;
    }

    $sign = '';
    if ($value[0] === '-' || $value[0] === '+') {
        $sign = $value[0] === '-' ? '-' : '';
        $value = substr($value, 1);
    }

    // ไม่มีตัวคั่นเลย
    if (preg_match('/^\d+$/', $value) === 1) {
        return $sign . $value;
    }

    // ตัวคั่นเดียว ตามด้วย 1–2 หลัก = ทศนิยมแน่นอน (3 ตำแหน่งขึ้นไปเก็บไม่ได้อยู่แล้ว)
    if (preg_match('/^(\d+)[.,](\d{1,2})$/', $value, $matched) === 1) {
        return $sign . $matched[1] . '.' . $matched[2];
    }

    // ⚠️ ตัวคั่นเดียว ตามด้วย 3 หลักพอดี = กำกวม (`1.234` เป็นได้ทั้ง 1234 และ 1.234)
    // ปฏิเสธ ดีกว่าเดาผิดแล้วยอดโตขึ้น 1000 เท่าโดยไม่มีใครรู้
    if (preg_match('/^\d+[.,]\d{3}$/', $value) === 1) {
        return null;
    }

    // คั่นหลักพันแบบอังกฤษ: 1,234 / 1,234,567 (+ ทศนิยมจุด)
    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $value) === 1) {
        return $sign . str_replace(',', '', $value);
    }

    // คั่นหลักพันแบบยุโรป: 1.234 / 1.234.567 (+ ทศนิยมจุลภาค)
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value) === 1) {
        $value = str_replace('.', '', $value);

        return $sign . str_replace(',', '.', $value);
    }

    return null;
}

/**
 * @return array{valid:bool,value:float|null}
 */
function parse_decimal_input(mixed $raw, bool $allowEmpty = false): array
{
    $normalized = trim((string)$raw);
    if ($normalized === '') {
        return [
            'valid' => $allowEmpty,
            'value' => null,
        ];
    }

    $money = normalize_money_string($normalized);
    if ($money === null) {
        return [
            'valid' => false,
            'value' => null,
        ];
    }

    return [
        'valid' => true,
        'value' => (float)$money,
    ];
}

function infer_http_status_from_error(string $errorMessage, int $defaultStatus = 422): int
{
    $normalized = strtolower(trim($errorMessage));
    if ($normalized === '') {
        return $defaultStatus;
    }

    if (str_contains($normalized, 'unauthorized') || str_contains($normalized, 'session expired')) {
        return 401;
    }

    if (str_contains($normalized, 'ไม่มีสิทธิ์') || str_contains($normalized, 'forbidden')) {
        return 403;
    }

    if (str_contains($normalized, 'method not allowed')) {
        return 405;
    }

    if (str_contains($normalized, 'invalid csrf token')) {
        return 403;
    }

    return $defaultStatus;
}

function set_flash(string $key, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return is_string($message) ? $message : null;
}

function client_ip(): string
{
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    $candidates = [];
    $proxyHeadersAllowed = false;

    if (
        $remoteAddr !== ''
        && defined('TRUST_PROXY')
        && TRUST_PROXY
        && defined('TRUSTED_PROXIES')
        && is_array(TRUSTED_PROXIES)
        && in_array($remoteAddr, TRUSTED_PROXIES, true)
    ) {
        $proxyHeadersAllowed = true;
    }

    if ($proxyHeadersAllowed) {
        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $firstHop = trim((string)explode(',', $forwardedFor)[0]);
            if ($firstHop !== '') {
                $candidates[] = $firstHop;
            }
        }

        $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '') {
            $candidates[] = $realIp;
        }
    }

    if ($remoteAddr !== '') {
        $candidates[] = $remoteAddr;
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            continue;
        }

        return substr($candidate, 0, 45);
    }

    return 'unknown';
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * ใช้เฉพาะตอน "ตั้งรหัสผ่านใหม่" (สมัคร / รีเซ็ต / เปลี่ยนรหัส) ไม่ได้ใช้ตอนล็อกอิน
 * → ผู้ใช้เดิมที่รหัสผ่านสั้นกว่าเกณฑ์ยังเข้าระบบได้ตามปกติ
 */
function validate_password_length(string $password, string $fieldLabel = 'รหัสผ่าน'): ?string
{
    // นับ "ตัวอักษร" ไม่ใช่ byte — strlen ทำให้รหัสผ่านไทย 3 ตัว (9 byte) ผ่านเกณฑ์ 8 ตัวอักษร
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    if ($length < PASSWORD_MIN_LENGTH) {
        return $fieldLabel . 'ต้องมีอย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร';
    }

    // bcrypt (PASSWORD_DEFAULT) ตัดที่ 72 byte เงียบ ๆ — ยาวกว่านั้นส่วนเกินไม่ถูกใช้ตรวจเลย
    // นับเป็น byte เพราะเป็นขีดจำกัดของ bcrypt จริง ๆ (อักษรไทย 1 ตัว = 3 byte)
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return $fieldLabel . 'ยาวเกินไป (สูงสุด ' . PASSWORD_MAX_BYTES . ' ไบต์ — อักษรไทย 1 ตัวนับเป็น 3)';
    }

    return null;
}

/**
 * unique index ที่ระบบพึ่งพาโดยตรง — ขาดไปแล้วพังเงียบ ไม่ใช่พังดัง
 *
 * กันข้อมูลซ้ำทั้งระบบพึ่ง key พวกนี้อย่างเดียว (ไม่มีชั้น idempotency แล้ว):
 *  - daily_records/monthly_goals: ถ้า key หาย ON DUPLICATE KEY UPDATE จะกลายเป็น
 *    INSERT ธรรมดา → กรอกวันเดิมซ้ำได้หลายแถว ยอดรวมทุกหน้ารายงานบวมโดยไม่มีสัญญาณ
 *  - auth_rate_limits: ถ้า key หาย ตัวนับจะสร้างแถวใหม่ทุกครั้งแทนการ +1 → rate limit ตาย
 *  - users.email/shops: กันบัญชีซ้ำและชื่อร้านซ้ำต่อผู้ใช้
 *
 * @return array<int,array{0:string,1:string}> [ชื่อตาราง, ชื่อ index]
 */
function schema_required_unique_indexes(): array
{
    return [
        ['users', 'uq_users_email'],
        ['shops', 'uq_shops_user_name'],
        ['daily_records', 'uq_daily_records_shop_date'],
        ['monthly_goals', 'uq_monthly_goals_shop_month'],
        ['auth_rate_limits', 'uq_auth_rate_limits_bucket'],
        ['password_reset_tokens', 'uq_password_reset_token_hash'],
        // 1 ผู้ใช้มี token ได้ใบเดียว — `PasswordResetRepository::createToken` อาศัย index นี้
        // ทำ ON DUPLICATE KEY UPDATE เพื่อให้ "ขอลิงก์ใหม่ = ลิงก์เก่าใช้ไม่ได้"
        // ถ้า index หาย การขอลิงก์ใหม่จะกลายเป็น INSERT ทำให้ลิงก์เก่าทุกใบยังใช้ได้ต่อ
        ['password_reset_tokens', 'uq_password_reset_user'],
    ];
}

function schema_table_exists(PDO $pdo, string $tableName): bool
{
    $sql = 'SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table_name' => $tableName]);

    return $stmt->fetchColumn() !== false;
}

function schema_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $sql = 'SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return $stmt->fetchColumn() !== false;
}

function schema_unique_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $sql = 'SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND INDEX_NAME = :index_name
              AND NON_UNIQUE = 0
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);

    return $stmt->fetchColumn() !== false;
}

/**
 * ป้าย "เหลืออีก …" ของประมาณการสิ้นปี — ใช้ร่วมกันระหว่างหน้าเว็บกับไฟล์ Excel
 *
 * ⚠️ เดิมข้อความนี้ถูกคัดลอกไว้ 2 ที่ (`annual.php` กับ `XlsxReportService`) พร้อมคอมเมนต์
 * "แก้ต้องแก้คู่" — แล้วก็เพี้ยนกันจริง ๆ (หน้าเว็บ "ไม่คิดฤดูกาล/การเปิดรอบ" ส่วนไฟล์
 * Excel "ไม่คิดฤดูกาล") ผู้ใช้ที่พิมพ์รายงานออกมาอ่านจึงได้เงื่อนไขคนละชุดกับที่เห็นบนจอ
 *
 * @param array<string,mixed> $projection
 */
function projection_remaining_label(array $projection): string
{
    $months = (int)($projection['months_remaining'] ?? 0);
    $days = (int)($projection['current_month_remaining_days'] ?? 0);

    return $days > 0
        ? sprintf('%d เดือน + อีก %d วันของเดือนนี้', $months, $days)
        : sprintf('%d เดือน', $months);
}

/**
 * คำอธิบายใต้ตัวเลขประมาณการ — ต้องเหมือนกันทั้งบนจอและในไฟล์ Excel
 *
 * @param array<string,mixed> $projection
 */
function projection_footnote_text(array $projection): string
{
    return sprintf(
        'สมมติช่วงที่เหลือ (%s) ทำได้เท่า %d เดือนล่าสุด · ไม่คิดฤดูกาล/การเปิดรอบ — ใช้ประกอบ ไม่ใช่ตัวเลขที่เกิดขึ้นจริง',
        projection_remaining_label($projection),
        (int)($projection['basis_month_count'] ?? 0)
    );
}

/**
 * นับเดือนกำไร/ขาดทุน/เท่าทุนจากสรุปประจำปี
 *
 * ⚠️ "เท่าทุน" ไม่ได้มาจาก service — เป็นส่วนที่เหลือจากการลบ ถ้าคำนวณกันคนละที่จะหลุด
 * ได้ง่าย (ไฟล์ Excel เคยแสดงแค่ "กำไร A / ขาดทุน B" เดือนที่กำไรเป็น 0 พอดีจึงหายไป
 * เฉย ๆ ผู้ใช้บวกเลขแล้วไม่ครบตามจำนวนเดือนที่มีข้อมูล)
 *
 * @param array<string,mixed> $summary
 * @return array{with_data:int,profit:int,loss:int,break_even:int}
 */
function annual_month_outcome_counts(array $summary): array
{
    $withData = max(0, (int)($summary['months_with_data'] ?? 0));
    $profit = max(0, (int)($summary['profit_months'] ?? 0));
    $loss = max(0, (int)($summary['loss_months'] ?? 0));

    return [
        'with_data' => $withData,
        'profit' => $profit,
        'loss' => $loss,
        'break_even' => max(0, $withData - $profit - $loss),
    ];
}

/**
 * ข้อความสำหรับความล้มเหลวตอนเขียนข้อมูล — แยก "ชนกับอีกคำขอ" ออกจาก "ระบบพัง"
 *
 * ⚠️ สองกรณีนี้ผู้ใช้ต้องทำคนละอย่าง: อย่างแรก "กดใหม่อีกครั้ง" แล้วจบ ส่วนอย่างหลัง
 * กดกี่ครั้งก็เหมือนเดิม · เดิมตอบข้อความเดียวกันหมด ("ไม่สามารถบันทึกข้อมูลได้")
 * คนที่แค่บันทึกพร้อมกันสองเครื่องจึงเจอทางตัน ทั้งที่กดซ้ำก็ผ่านแล้ว
 *
 * MySQL 1205 = รอคิวนานเกินไป · 1213 = ถูกตัดออกจากวงรอ — ทั้งคู่แก้ด้วยการลองใหม่
 */
function write_failure_message(Throwable $exception, string $fallback): string
{
    $code = $exception instanceof PDOException ? (string)($exception->errorInfo[1] ?? '') : '';

    if ($code === '1205' || $code === '1213') {
        return 'มีการบันทึกจากอีกหน้าจอในเวลาเดียวกัน กรุณากดบันทึกอีกครั้ง';
    }

    return $fallback;
}
