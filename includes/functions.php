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

function formatMoney(float|int $value): string
{
    return '฿' . number_format((float)$value, 0);
}

function formatRoas(?float $value): string
{
    return $value === null ? '–' : number_format($value, 2);
}

function formatPercent(?float $value): string
{
    return $value === null ? '–' : number_format($value, 1) . '%';
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
        api_respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, $redirectUrl, $wantsJson);
    }
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

    return $normalize($rawYear) ?? $normalize($fallbackYear) ?? (int)date('Y');
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

function parse_decimal_input(mixed $raw, bool $allowEmpty = false): array
{
    $normalized = trim((string)$raw);
    if ($normalized === '') {
        return [
            'valid' => $allowEmpty,
            'value' => null,
        ];
    }

    $normalized = str_replace(',', '', $normalized);
    if (!is_numeric($normalized)) {
        return [
            'valid' => false,
            'value' => null,
        ];
    }

    return [
        'valid' => true,
        'value' => (float)$normalized,
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
