<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RuntimeException;
use Throwable;

/**
 * Base class สำหรับเทสต์ "ชั้นหน้าเว็บ" (api/*.php และเพจ) ด้วยการยิง HTTP จริง
 *
 * ทำไมต้องยิง HTTP จริง ไม่ใช่ require ไฟล์เข้ามาเรียก:
 *  · `api/*.php` เรียก `includes/bootstrap.php` ซึ่งสั่ง session_start, ตั้ง security header
 *    และเปิด DB — ทำงานใน process ของ PHPUnit ไม่ได้ (headers already sent / session ซ้อน)
 *  · ตรรกะที่อยากล็อกส่วนใหญ่ *คือ* พฤติกรรม HTTP เอง (405 / 415 / 409 / CSRF / redirect)
 *    ซึ่งพิสูจน์ได้ทางเดียวคือดูสถานะและ header ที่ตอบกลับมาจริง
 *
 * วิธีทำงาน: ยก `php -S` ขึ้นมา 1 ตัวต่อ 1 คลาสเทสต์ ชี้ DB ไปที่ test DB เดียวกับ
 * IntegrationTestCase (ผ่าน env `DB_*`) และแยกโฟลเดอร์ session ของตัวเอง
 *
 * ⚠️ เซิร์ฟเวอร์ใช้ DB ที่ชื่อลงท้าย `_test` เท่านั้น (บังคับที่ IntegrationTestCase)
 */
abstract class ControllerTestCase extends IntegrationTestCase
{
    /** @var resource|null */
    private static $serverProcess = null;
    private static ?string $baseUrl = null;
    private static ?string $sessionDir = null;
    private static ?string $startupError = null;

    /**
     * ค่า env เพิ่มเติมต่อคลาสเทสต์ — คลาสลูกเขียนทับได้
     *
     * ⚠️ มีไว้ให้เทสต์จำลอง "สภาพเซิร์ฟเวอร์" ที่ต่างจากปริยายได้จริง เช่นเปิดระบบอีเมล
     * แล้วชี้ไปโฮสต์ที่ไม่มีอยู่ เพื่อให้ได้ "ตั้งค่าแล้วแต่ส่งไม่ออก" ของจริง
     * ซึ่งเป็นคนละเรื่องกับ "ยังไม่ได้ตั้งค่า" และระบบต้องตอบคนละอย่าง
     *
     * @return array<string,string>
     */
    protected static function serverEnvironmentOverrides(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$serverProcess !== null) {
            return;
        }

        $credentials = self::testDatabaseCredentials();
        if ($credentials === null) {
            return; // ต่อ DB ไม่ได้ → setUp() ของ base class จะ skip ให้เอง
        }

        $projectRoot = dirname(__DIR__, 2);
        $sessionDir = sys_get_temp_dir() . '/ad-profit-controller-tests-' . getmypid();
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            return;
        }

        $port = self::findFreePort();
        if ($port === null) {
            return;
        }

        // ⚠️ ต้องยอมให้อัปโหลดใหญ่กว่าเพดานของแอป (2MB) — ไม่งั้น PHP ปฏิเสธก่อน
        // ด่านของแอปจะไม่มีวันทำงาน และเทสต์ที่ตั้งชื่อว่าตรวจด่านนั้นก็ตรวจอะไรไม่ได้
        // เซิร์ฟเวอร์จริง (Hostinger) ตั้ง upload_max_filesize ไว้สูงกว่า 2MB อยู่แล้ว
        // ด่านของแอปจึงเป็นตัวจริงที่ทำงานบน production
        // ⚠️ ปัก `memory_limit` ไว้ที่ค่าปริยายของ PHP — ไม่งั้นเครื่องที่ตั้งเป็น "ไม่จำกัด"
        // จะทำให้เทสต์ที่ตรวจว่า "ไฟล์แถวเยอะต้องถูกปฏิเสธก่อนหน่วยความจำหมด" เขียว
        // โดยไม่ได้ตรวจอะไรเลย (ดู testAFileUnderTheSizeCapWithTooManyRowsIsRejectedInsteadOfCrashing)
        $command = sprintf(
            'php -d session.save_path=%s -d error_reporting=E_ALL'
                . ' -d upload_max_filesize=16M -d post_max_size=20M -d memory_limit=128M'
                . ' -S 127.0.0.1:%d -t %s',
            escapeshellarg($sessionDir),
            $port,
            escapeshellarg($projectRoot)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
        $environment = array_merge($_ENV, getenv(), [
            'DB_HOST' => $credentials['host'],
            'DB_PORT' => $credentials['port'],
            'DB_NAME' => $credentials['name'],
            'DB_USER' => $credentials['user'],
            'DB_PASS' => $credentials['pass'],
            // ⚠️ ต้องเป็น development — `includes/bootstrap.php` ปิด display_errors
            // ในโหมดอื่นทั้งหมด แล้ว warning/notice จะไม่โผล่บนหน้า เทสต์ที่ตรวจว่า
            // "ไม่มี error หลุดบนจอ" จึงผ่านตลอดโดยไม่ได้ตรวจอะไรเลย (เคยพลาดมาแล้ว)
            'APP_ENV' => 'development',
        ], static::serverEnvironmentOverrides());

        $process = @proc_open($command, $descriptors, $pipes, $projectRoot, $environment);
        if (!is_resource($process)) {
            return;
        }

        self::$serverProcess = $process;
        self::$sessionDir = $sessionDir;
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        if (!self::waitForServer(self::$baseUrl)) {
            self::stopServer();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ มาถึงตรงนี้ได้แปลว่า test DB พร้อมแล้ว (ไม่งั้น parent::setUp() skip ไปก่อน)
        // ดังนั้นถ้าเซิร์ฟเวอร์ไม่ขึ้น = พัง ต้องแดง ไม่ใช่ข้ามเงียบ ๆ
        // เดิม skip ทั้งชั้น controller+page ได้โดยที่ CI ยังเขียว (phpunit exit 0)
        if (self::$baseUrl === null) {
            $this->fail('ยก php -S สำหรับเทสต์ชั้นหน้าเว็บไม่ได้: ' . (self::$startupError ?? 'ไม่ทราบสาเหตุ'));
        }
    }

    /**
     * สร้างไฟล์ session ให้ผู้ใช้ที่ระบุ แล้วคืน session id
     *
     * ⚠️ เขียนไฟล์ตรง ๆ แทนการล็อกอินผ่านฟอร์ม เพื่อไม่ให้เทสต์ต้องรู้จักรหัสผ่าน
     * และไม่ให้ rate limit ของหน้า login มายุ่งกับเทสต์เรื่องอื่น
     */
    /**
     * @param int $startedSecondsAgo อายุของ session (ใช้ทดสอบด่านหมดเวลา)
     * @param int $idleSecondsAgo    เวลาที่ไม่มีการใช้งานล่าสุด
     */
    protected function startSession(
        int $userId,
        int $shopId,
        int $sessionVersion = 1,
        int $startedSecondsAgo = 0,
        int $idleSecondsAgo = 0
    ): string {
        $sessionId = 'ctrl' . bin2hex(random_bytes(12));
        $now = time();

        // ⚠️ ต้องเป็นคีย์ชุดเดียวกับ `AuthService::establishSession()` เป๊ะ ๆ
        // เดิมเขียนคีย์ที่แอปไม่ได้อ่าน (user_email/last_activity/created_at) แล้วขาด
        // คีย์ที่แอปอ่านจริง — `isAuthSessionAlive()` เติมให้เองเงียบ ๆ เทสต์จึงผ่าน
        // แต่ด่านหมดเวลา (idle/absolute) กลายเป็นสิ่งที่เทสต์ผ่านชั้นนี้ไม่ได้เลย
        // ⚠️ ต้องเป็นอีเมลจริงของผู้ใช้คนนั้น ไม่ใช่ค่าคงที่ — หน้าเว็บบางหน้าเทียบอีเมล
        // ใน session กับข้อมูลอื่น (เช่นหน้าตั้งรหัสใหม่เตือนเมื่อลิงก์เป็นของคนละบัญชี)
        // ค่าคงที่ทำให้เทสต์เรื่องนั้นเขียนไม่ได้เลย
        // ⚠️ `startSession(0, 0)` = "ยังไม่ได้ล็อกอิน" (ใช้ถือ CSRF token ของหน้าเข้าสู่ระบบ)
        // ต้องไม่มีอีเมลปลอมติดมา ไม่งั้นเทสต์ที่ตรวจว่า "ระบบไม่บอกใบ้อีเมล" จะไปเจอ
        // อีเมลของตัวเองที่ fixture เขียนไว้ แล้วแดงด้วยเหตุผลที่ผิด
        $email = $userId > 0
            ? (string)$this->pdo->query('SELECT email FROM users WHERE id = ' . $userId)->fetchColumn()
            : '';

        $values = [
            'user_id' => $userId,
            'email' => $email,
            'session_version' => $sessionVersion,
            'auth_started_at' => $now - $startedSecondsAgo,
            'last_activity_at' => $now - $idleSecondsAgo,
            'current_shop_id' => $shopId,
            'current_shop_name' => 'ร้านทดสอบ',
        ];

        $payload = '';
        foreach ($values as $key => $value) {
            $payload .= $key . '|' . serialize($value);
        }

        file_put_contents((string)self::$sessionDir . '/sess_' . $sessionId, $payload);

        return $sessionId;
    }

    /**
     * session เปล่า — มีแต่ id ไม่มีคีย์ของการล็อกอินเลย
     *
     * ใช้ตอนที่ต้องพิสูจน์ว่า "การล็อกอินจริงเขียนอะไรลง session บ้าง" — ถ้าเริ่มจาก
     * session ที่มีคีย์ครบอยู่แล้ว `session_regenerate_id()` จะยกของเดิมข้ามไป
     * แล้วดูไม่ออกว่าคีย์ไหนมาจากแอปจริง ๆ
     */
    protected function startBlankSession(): string
    {
        $sessionId = 'blank' . bin2hex(random_bytes(12));
        file_put_contents((string)self::$sessionDir . '/sess_' . $sessionId, '');

        return $sessionId;
    }

    /** อ่านค่า CSRF token ที่เซิร์ฟเวอร์ตั้งไว้ใน session ของ id นี้ */
    protected function csrfTokenFor(string $sessionId): string
    {
        $response = $this->get('/add-record.php', $sessionId);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $response['body'], $matched) === 1) {
            return $matched[1];
        }

        throw new RuntimeException('หา csrf_token ในหน้าไม่เจอ (status ' . $response['status'] . ')');
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function get(string $path, ?string $sessionId = null): array
    {
        return $this->request('GET', $path, [], $sessionId);
    }

    /**
     * @param array<string,scalar> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function post(string $path, array $fields, ?string $sessionId = null): array
    {
        return $this->request('POST', $path, $fields, $sessionId);
    }

    /**
     * ยิงแบบ "ขอคำตอบเป็น JSON" — controller จะตอบด้วยรหัสสถานะจริง (405/409/422)
     * แทนการ redirect+flash ซึ่งเป็นวิธีตอบสำหรับฟอร์มจากเบราว์เซอร์
     *
     * ⚠️ ทั้งสองโหมดต้องตัดสินเหมือนกัน ต่างกันแค่วิธีบอกผู้ใช้ — เทสต์จึงยิงทั้งคู่
     *
     * @param array<string,scalar> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function postJson(string $path, array $fields, ?string $sessionId = null): array
    {
        return $this->request('POST', $path, $fields, $sessionId, ['Accept' => 'application/json']);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function getJson(string $path, ?string $sessionId = null): array
    {
        return $this->request('GET', $path, [], $sessionId, ['Accept' => 'application/json']);
    }

    /**
     * ส่งฟอร์มพร้อมไฟล์แนบ (multipart/form-data) — ทางเดียวที่จะทดสอบการนำเข้า CSV
     *
     * ⚠️ `http_build_query` ส่งไฟล์ไม่ได้ ด่านตรวจการอัปโหลดทั้ง 6 ด่านใน
     * `api/records.php` จึงเคยไม่มีใครแตะเลย
     *
     * @param array<string,string> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function postFile(
        string $path,
        array $fields,
        string $fileField,
        string $fileName,
        string $fileContent,
        ?string $sessionId = null,
        string $fileMimeType = 'text/csv'
    ): array {
        $boundary = '----adprofit' . bin2hex(random_bytes(8));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
                . $value . "\r\n";
        }

        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $fileName . '"' . "\r\n"
            . 'Content-Type: ' . $fileMimeType . "\r\n\r\n"
            . $fileContent . "\r\n"
            . '--' . $boundary . "--\r\n";

        return $this->request(
            'POST',
            $path,
            [],
            $sessionId,
            ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            $body
        );
    }

    /**
     * @param array<string,scalar> $fields
     * @param array<string,string> $extraHeaders
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    protected function request(
        string $method,
        string $path,
        array $fields = [],
        ?string $sessionId = null,
        array $extraHeaders = [],
        ?string $rawBody = null
    ): array {
        $headers = $extraHeaders;
        if ($sessionId !== null) {
            $headers['Cookie'] = 'PHPSESSID=' . $sessionId;
        }

        $body = $rawBody;
        if ($body === null && $fields !== []) {
            $body = http_build_query($fields);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'ignore_errors' => true,   // ต้องอ่าน body ของ 4xx/5xx ด้วย
                'follow_location' => 0,    // redirect คือสิ่งที่กำลังตรวจ ห้ามตามไปเอง
                'timeout' => 20,
            ],
        ]);

        $responseBody = @file_get_contents(self::$baseUrl . $path, false, $context);

        $status = 0;
        $responseHeaders = [];
        // ⚠️ ตัวแปรวิเศษ $http_response_header ถูกประกาศเลิกใช้ตั้งแต่ PHP 8.4
        // และ phpunit.xml ตั้ง failOnWarning/failOnNotice ไว้ → ต้องใช้ตัวใหม่
        $rawHeaders = http_get_last_response_headers() ?? [];

        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matched) === 1) {
                $status = (int)$matched[1];
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);

                // ⚠️ Set-Cookie มาได้หลายบรรทัด — ต่อกันไว้ ไม่ใช่ทับตัวก่อนหน้า
                // (ต้องใช้หา session id ใหม่หลังล็อกอิน ซึ่ง session_regenerate_id เปลี่ยนให้)
                $responseHeaders[$name] = $name === 'set-cookie' && isset($responseHeaders[$name])
                    ? $responseHeaders[$name] . "\n" . $value
                    : $value;
            }
        }

        // ⚠️ ต่อไม่ติด/timeout จะได้ status = 0 ซึ่ง "ไม่เท่ากับ 200" — เทสต์ที่เช็กแค่
        // assertNotSame(200, …) จึงผ่านทั้งที่คำขอไปไม่ถึงแอปเลย ต้องแดงตรงนี้แทน
        if ($status === 0) {
            $this->fail(sprintf('ยิง %s %s ไม่ถึงเซิร์ฟเวอร์ทดสอบ', $method, $path));
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody === false ? '' : $responseBody,
        ];
    }

    /**
     * session id ที่เซิร์ฟเวอร์ตั้งกลับมาในคำตอบ (ว่างถ้าไม่ได้ตั้งใหม่)
     *
     * ล็อกอินสำเร็จเรียก `session_regenerate_id(true)` → ได้ id ใหม่
     * ต้องใช้ตัวนี้ถึงจะ "ใช้งานต่อด้วย session ที่การล็อกอินจริงสร้างขึ้น" ได้
     *
     * @param array{status:int,headers:array<string,string>,body:string} $response
     */
    protected function sessionIdFrom(array $response): string
    {
        $raw = (string)($response['headers']['set-cookie'] ?? '');

        return preg_match('/PHPSESSID=([^;\s]+)/', $raw, $matched) === 1 ? $matched[1] : '';
    }

    /** ข้อความ flash ที่ถูกตั้งไว้ใน session (redirect+flash คือทางตอบปกติของฟอร์ม) */
    /**
     * ⚠️ คืนสตริงว่างเงียบ ๆ ไม่ได้ — `session_regenerate_id(true)` (เปลี่ยนรหัสผ่าน/อีเมล,
     * ออกจากระบบ) **ลบไฟล์เดิมทิ้ง** เทสต์ที่อ่าน flash หลังจากนั้นจะได้ '' แล้วเข้าใจว่า
     * "ไม่มีข้อความ" ทั้งที่จริง ๆ คืออ่านผิดที่
     */
    protected function flashMessages(string $sessionId): string
    {
        $file = (string)self::$sessionDir . '/sess_' . $sessionId;

        if (!is_file($file)) {
            $this->fail('ไม่พบไฟล์ session ' . $sessionId . ' — อาจถูกสร้างใหม่ (session_regenerate_id)');
        }

        return (string)file_get_contents($file);
    }

    private static function findFreePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($socket === false) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            return null;
        }

        $port = (int)substr($name, (int)strrpos($name, ':') + 1);

        return $port > 0 ? $port : null;
    }

    /**
     * ⚠️ ต้องยิง HTTP จริงแล้วดูว่าได้หน้าของแอปกลับมา — เช็กแค่ "พอร์ตเปิด" ไม่พอ
     * เพราะโปรเซสอื่นอาจแย่งพอร์ตไปก่อน แล้วเทสต์ทั้งชุดจะไปยิงเซิร์ฟเวอร์ของคนอื่น
     */
    private static function waitForServer(string $baseUrl): bool
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 2, 'follow_location' => 0],
        ]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $body = @file_get_contents($baseUrl . '/login.php', false, $context);

            if (is_string($body) && str_contains($body, 'csrf_token')) {
                return true;
            }

            usleep(100000);
        }

        self::$startupError = 'ยิง ' . $baseUrl . '/login.php แล้วไม่ได้หน้าเข้าสู่ระบบกลับมาภายใน 10 วินาที';

        return false;
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            try {
                proc_terminate(self::$serverProcess);
                proc_close(self::$serverProcess);
            } catch (Throwable) {
                // ปิดไม่ได้ก็ปล่อย — process ตายพร้อม test run อยู่แล้ว
            }
        }

        if (self::$sessionDir !== null && is_dir(self::$sessionDir)) {
            foreach ((array)glob(self::$sessionDir . '/sess_*') as $file) {
                @unlink((string)$file);
            }

            @rmdir(self::$sessionDir);
        }

        self::$serverProcess = null;
        self::$baseUrl = null;
        self::$sessionDir = null;
    }
}
