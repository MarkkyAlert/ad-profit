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

        $command = sprintf(
            'php -d session.save_path=%s -d error_reporting=E_ALL -S 127.0.0.1:%d -t %s',
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
            'APP_ENV' => 'testing',
        ]);

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

        if (self::$baseUrl === null) {
            $this->markTestSkipped('ยก php -S สำหรับเทสต์ชั้นหน้าเว็บไม่ได้');
        }
    }

    /**
     * สร้างไฟล์ session ให้ผู้ใช้ที่ระบุ แล้วคืน session id
     *
     * ⚠️ เขียนไฟล์ตรง ๆ แทนการล็อกอินผ่านฟอร์ม เพื่อไม่ให้เทสต์ต้องรู้จักรหัสผ่าน
     * และไม่ให้ rate limit ของหน้า login มายุ่งกับเทสต์เรื่องอื่น
     */
    protected function startSession(int $userId, int $shopId, int $sessionVersion = 1): string
    {
        $sessionId = 'ctrl' . bin2hex(random_bytes(12));
        $now = time();
        $values = [
            'user_id' => $userId,
            'user_email' => 'owner@example.com',
            'display_name' => 'เจ้าของร้าน',
            'session_version' => $sessionVersion,
            'current_shop_id' => $shopId,
            'last_activity' => $now,
            'created_at' => $now,
        ];

        $payload = '';
        foreach ($values as $key => $value) {
            $payload .= $key . '|' . serialize($value);
        }

        file_put_contents((string)self::$sessionDir . '/sess_' . $sessionId, $payload);

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
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody === false ? '' : $responseBody,
        ];
    }

    /** ข้อความ flash ที่ถูกตั้งไว้ใน session (redirect+flash คือทางตอบปกติของฟอร์ม) */
    protected function flashMessages(string $sessionId): string
    {
        $file = (string)self::$sessionDir . '/sess_' . $sessionId;

        return is_file($file) ? (string)file_get_contents($file) : '';
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

    private static function waitForServer(string $baseUrl): bool
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client(
                str_replace('http://', 'tcp://', $baseUrl),
                $errorNumber,
                $errorMessage,
                0.2
            );

            if (is_resource($connection)) {
                fclose($connection);
                return true;
            }

            usleep(100000);
        }

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
