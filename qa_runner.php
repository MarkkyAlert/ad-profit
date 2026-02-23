<?php

declare(strict_types=1);

// Security: QA runner must never be executed via web requests.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Bangkok');

final class HttpClient
{
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $cookieFile = tempnam(sys_get_temp_dir(), 'qa_cookie_');

        if ($cookieFile === false) {
            throw new RuntimeException('Unable to create cookie jar');
        }

        $this->cookieFile = $cookieFile;
    }

    public function request(string $method, string $path, array $options = []): array
    {
        $method = strtoupper(trim($method));
        $query = isset($options['query']) && is_array($options['query']) ? $options['query'] : [];
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $form = isset($options['form']) && is_array($options['form']) ? $options['form'] : null;
        $rawBody = isset($options['raw_body']) ? (string)$options['raw_body'] : null;

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $body = null;
        if ($form !== null) {
            $body = http_build_query($form);
            if (!array_key_exists('Content-Type', $headers) && !array_key_exists('content-type', $headers)) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        } elseif ($rawBody !== null) {
            $body = $rawBody;
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_HTTPHEADER => $curlHeaders,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL request failed: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headerText = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);
        curl_close($ch);

        $responseHeaders = $this->parseHeaders($headerText);

        return [
            'status' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'request' => [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $form !== null ? $form : $body,
            ],
        ];
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    private function parseHeaders(string $headerText): array
    {
        $headerText = trim($headerText);
        if ($headerText === '') {
            return [];
        }

        $blocks = preg_split('/\r\n\r\n/', $headerText);
        if (!is_array($blocks) || empty($blocks)) {
            return [];
        }

        $lastBlock = trim((string)end($blocks));
        $lines = preg_split('/\r\n/', $lastBlock);
        if (!is_array($lines)) {
            return [];
        }

        $headers = [];
        foreach ($lines as $lineIndex => $line) {
            if ($lineIndex === 0) {
                $headers[':status-line'] = $line;
                continue;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($name === '') {
                continue;
            }

            if (isset($headers[$name])) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}

function decodeJsonBody(string $body): array
{
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function extractCsrfToken(string $html): ?string
{
    $matched = preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/', $html, $matches);
    if ($matched !== 1) {
        return null;
    }

    return isset($matches[1]) ? (string)$matches[1] : null;
}

function fetchCsrfToken(HttpClient $client, string $path): ?string
{
    $response = $client->request('GET', $path, [
        'headers' => [
            'Accept' => 'text/html',
        ],
    ]);

    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 400) {
        return null;
    }

    return extractCsrfToken((string)($response['body'] ?? ''));
}

function requireCsrfToken(HttpClient $client, string $path): string
{
    $token = fetchCsrfToken($client, $path);
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Unable to fetch CSRF token from ' . $path);
    }

    return $token;
}

function extractRecordIdForDate(string $html, string $recordDate): ?int
{
    $pattern = '/data-record-id="(\d+)"\s+data-record-date="' . preg_quote($recordDate, '/') . '"/';
    $matched = preg_match($pattern, $html, $matches);
    if ($matched !== 1) {
        return null;
    }

    return isset($matches[1]) ? (int)$matches[1] : null;
}

function fetchRecordIdForDate(HttpClient $client, string $month, string $recordDate): ?int
{
    $response = $client->request('GET', '/history.php', [
        'headers' => [
            'Accept' => 'text/html',
        ],
        'query' => [
            'month' => $month,
        ],
    ]);

    if ((int)($response['status'] ?? 0) !== 200) {
        return null;
    }

    return extractRecordIdForDate((string)($response['body'] ?? ''), $recordDate);
}

function maskValueByKey(string $key, mixed $value): mixed
{
    $normalizedKey = strtolower($key);
    $sensitiveKeywords = ['password', 'csrf', 'token', 'authorization', 'cookie'];

    foreach ($sensitiveKeywords as $keyword) {
        if (str_contains($normalizedKey, $keyword)) {
            return '***';
        }
    }

    if (is_array($value)) {
        return maskData($value);
    }

    return $value;
}

function maskData(mixed $data): mixed
{
    if (!is_array($data)) {
        return $data;
    }

    $masked = [];
    foreach ($data as $key => $value) {
        $stringKey = is_string($key) ? $key : (string)$key;
        $masked[$key] = maskValueByKey($stringKey, $value);
    }

    return $masked;
}

function appendJsonl($handle, array $row): void
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    fwrite($handle, $json . PHP_EOL);
}

function responseBodySnippet(string $body, int $maxLength = 400): string
{
    $normalized = preg_replace('/\s+/', ' ', $body);
    $normalized = is_string($normalized) ? trim($normalized) : '';

    if (strlen($normalized) <= $maxLength) {
        return $normalized;
    }

    return substr($normalized, 0, $maxLength) . '...';
}

$baseUrl = isset($argv[1]) && trim((string)$argv[1]) !== ''
    ? trim((string)$argv[1])
    : 'http://127.0.0.1/ad-profit';

$projectRoot = __DIR__;
$testCasesPath = $projectRoot . DIRECTORY_SEPARATOR . 'test_cases.md';
$runLogPath = $projectRoot . DIRECTORY_SEPARATOR . 'run_log.jsonl';
$reportPath = $projectRoot . DIRECTORY_SEPARATOR . 'report.md';

$runStartedAt = date('c');
$runStamp = date('Ymd_His') . '_' . (string)random_int(1000, 9999);

$runLogHandle = fopen($runLogPath, 'wb');
if ($runLogHandle === false) {
    throw new RuntimeException('Unable to create run_log.jsonl');
}

$caseSpecs = [];
$results = [];

$clientGuest = new HttpClient($baseUrl);
$clientUserA = new HttpClient($baseUrl);
$clientStaleUserA = new HttpClient($baseUrl);
$clientUserB = new HttpClient($baseUrl);
$clientUnauth = new HttpClient($baseUrl);

$apiJsonHeaders = [
    'Accept' => 'application/json',
    'X-Requested-With' => 'XMLHttpRequest',
];

$userAEmail = 'qa_a_' . $runStamp . '@example.com';
$userAPassword = 'QaPass123!';
$userANewEmail = 'qa_a_new_' . $runStamp . '@example.com';
$userANewPassword = 'QaPass456!';

$userBEmail = 'qa_b_' . $runStamp . '@example.com';
$userBPassword = 'QaPass123!';

$currentMonth = date('Y-m');
$recordDateA = date('Y-m-d');
$recordDateB = date('Y-m-d', strtotime('-1 day'));

$userADefaultShopId = 0;
$userASecondShopId = 0;
$userBDefaultShopId = 0;
$userARecordId = 0;
$userBRecordId = 0;

$runCase = function (
    string $id,
    string $category,
    string $title,
    callable $requestFactory,
    callable $assertion,
    string $expectedOutcome
) use (&$caseSpecs, &$results, $runLogHandle): void {
    $caseSpecs[] = [
        'id' => $id,
        'category' => $category,
        'title' => $title,
        'expected' => $expectedOutcome,
    ];

    $attempt = 1;
    $maxAttempts = 1;
    $status = 'failed';
    $finalMessage = '';
    $firstRequestMeta = null;

    while ($attempt <= $maxAttempts) {
        try {
            $requestDefinition = $requestFactory($attempt);
            $client = $requestDefinition['client'];
            if (!$client instanceof HttpClient) {
                throw new RuntimeException('Invalid request client');
            }

            $method = (string)($requestDefinition['method'] ?? 'GET');
            $path = (string)($requestDefinition['path'] ?? '/');
            $options = isset($requestDefinition['options']) && is_array($requestDefinition['options'])
                ? $requestDefinition['options']
                : [];

            $response = $client->request($method, $path, $options);
            if ($firstRequestMeta === null) {
                $firstRequestMeta = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'url' => (string)($response['request']['url'] ?? ''),
                ];
            }

            $assertionResult = $assertion($response, $attempt);
            $passed = (bool)($assertionResult[0] ?? false);
            $message = (string)($assertionResult[1] ?? '');

            appendJsonl($runLogHandle, [
                'timestamp' => date('c'),
                'case_id' => $id,
                'category' => $category,
                'title' => $title,
                'attempt' => $attempt,
                'request' => [
                    'method' => (string)($response['request']['method'] ?? strtoupper($method)),
                    'url' => (string)($response['request']['url'] ?? ''),
                    'headers' => maskData((array)($response['request']['headers'] ?? [])),
                    'body' => maskData($response['request']['body'] ?? null),
                ],
                'response' => [
                    'status' => (int)($response['status'] ?? 0),
                    'headers' => maskData((array)($response['headers'] ?? [])),
                    'body' => (string)($response['body'] ?? ''),
                ],
                'evaluation' => [
                    'passed' => $passed,
                    'message' => $message,
                    'expected' => $expectedOutcome,
                ],
            ]);

            if ($passed) {
                $status = $attempt === 1 ? 'passed' : 'flaky';
                $finalMessage = $message;
                break;
            }

            $finalMessage = $message;
            $isGet = strtoupper($method) === 'GET';
            if ($attempt === 1 && $isGet) {
                $maxAttempts = 2;
            } else {
                break;
            }
        } catch (Throwable $exception) {
            $finalMessage = $exception->getMessage();

            appendJsonl($runLogHandle, [
                'timestamp' => date('c'),
                'case_id' => $id,
                'category' => $category,
                'title' => $title,
                'attempt' => $attempt,
                'request' => null,
                'response' => null,
                'evaluation' => [
                    'passed' => false,
                    'message' => $exception->getMessage(),
                    'expected' => $expectedOutcome,
                ],
            ]);

            break;
        }

        $attempt++;
    }

    $results[] = [
        'id' => $id,
        'category' => $category,
        'title' => $title,
        'status' => $status,
        'expected' => $expectedOutcome,
        'message' => $finalMessage,
        'request' => $firstRequestMeta,
    ];
};

$assertJsonSuccess = static function (array $response): bool {
    $json = decodeJsonBody((string)($response['body'] ?? ''));
    return (bool)($json['success'] ?? false) === true;
};

$assertStatusAndJsonErrorContains = static function (array $response, int $status, string $errorKeyword): array {
    $json = decodeJsonBody((string)($response['body'] ?? ''));
    $actualStatus = (int)($response['status'] ?? 0);
    $error = (string)($json['error'] ?? '');

    $ok = $actualStatus === $status && ($errorKeyword === '' || str_contains($error, $errorKeyword));

    return [
        $ok,
        'Expected status ' . $status . ' with error containing "' . $errorKeyword . '", got status ' . $actualStatus . ' and error "' . $error . '"',
    ];
};

// Reachability pre-check.
$preflight = $clientGuest->request('GET', '/login.php', [
    'headers' => ['Accept' => 'text/html'],
]);
if ((int)($preflight['status'] ?? 0) !== 200) {
    fclose($runLogHandle);
    throw new RuntimeException('Target application is unreachable at ' . $baseUrl . '/login.php');
}

// -------------------------------
// Happy Path (21)
// -------------------------------
$runCase(
    'HP01',
    'Happy Path',
    'Register user A',
    function () use ($clientUserA, $apiJsonHeaders, $userAEmail, $userAPassword) {
        $csrf = requireCsrfToken($clientUserA, '/login.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'register',
                    'csrf_token' => $csrf,
                    'email' => $userAEmail,
                    'password' => $userAPassword,
                    'password_confirm' => $userAPassword,
                ],
            ],
        ];
    },
    function (array $response) use (&$userADefaultShopId) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $shopId = (int)($json['data']['shop_id'] ?? 0);
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true && $shopId > 0;

        if ($ok) {
            $userADefaultShopId = $shopId;
        }

        return [$ok, 'Expected 200 success=true with data.shop_id > 0'];
    },
    '200 + success=true + data.shop_id > 0'
);

$runCase(
    'HP02',
    'Happy Path',
    'Logout user A',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'logout',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected logout success 200'],
    '200 + success=true'
);

$runCase(
    'HP03',
    'Happy Path',
    'Login user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userAEmail, &$userAPassword) {
        $csrf = requireCsrfToken($clientUserA, '/login.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'login',
                    'csrf_token' => $csrf,
                    'email' => $userAEmail,
                    'password' => $userAPassword,
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected login success 200'],
    '200 + success=true'
);

$runCase(
    'HP04',
    'Happy Path',
    'Create second shop for user A',
    function () use ($clientUserA, $apiJsonHeaders, $runStamp) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'create',
                    'csrf_token' => $csrf,
                    'name' => 'QA Shop A ' . $runStamp,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    function (array $response) use (&$userASecondShopId) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $shopId = (int)($json['data']['shop_id'] ?? 0);
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true && $shopId > 0;
        if ($ok) {
            $userASecondShopId = $shopId;
        }

        return [$ok, 'Expected create shop success with shop_id > 0'];
    },
    '200 + success=true + data.shop_id > 0'
);

$runCase(
    'HP05',
    'Happy Path',
    'Switch back to default shop for user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userADefaultShopId) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'switch',
                    'csrf_token' => $csrf,
                    'shop_id' => (string)$userADefaultShopId,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected switch shop success'],
    '200 + success=true'
);

$runCase(
    'HP06',
    'Happy Path',
    'Rename second shop for user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userASecondShopId, $runStamp) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'rename',
                    'csrf_token' => $csrf,
                    'shop_id' => (string)$userASecondShopId,
                    'name' => 'QA Shop A Renamed ' . $runStamp,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected rename shop success'],
    '200 + success=true'
);

$runCase(
    'HP07',
    'Happy Path',
    'Upsert monthly goal for user A',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'goal_month' => $currentMonth,
                    'target_revenue' => '12345.67',
                    'target_profit' => '',
                    'redirect_to' => '/dashboard.php',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected goal upsert success'],
    '200 + success=true'
);

$runCase(
    'HP08',
    'Happy Path',
    'Delete monthly goal for user A',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'delete',
                    'csrf_token' => $csrf,
                    'goal_month' => $currentMonth,
                    'redirect_to' => '/dashboard.php',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected goal delete success'],
    '200 + success=true'
);

$runCase(
    'HP09',
    'Happy Path',
    'Upsert daily record for user A',
    function () use ($clientUserA, $apiJsonHeaders, $recordDateA) {
        $csrf = requireCsrfToken($clientUserA, '/add-record.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'record_date' => $recordDateA,
                    'revenue' => '3000.00',
                    'ad_cost' => '1000.00',
                    'note' => 'qa hp09',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected record upsert success'],
    '200 + success=true'
);

$userARecordId = (int)(fetchRecordIdForDate($clientUserA, $currentMonth, $recordDateA) ?? 0);

$runCase(
    'HP10',
    'Happy Path',
    'Update daily record for user A',
    function () use ($clientUserA, $apiJsonHeaders, $recordDateA, &$userARecordId, $currentMonth) {
        if ($userARecordId <= 0) {
            throw new RuntimeException('Missing record_id for HP10');
        }

        $csrf = requireCsrfToken($clientUserA, '/history.php?month=' . urlencode($currentMonth));

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'update',
                    'csrf_token' => $csrf,
                    'month' => $currentMonth,
                    'record_id' => (string)$userARecordId,
                    'record_date' => $recordDateA,
                    'revenue' => '3500.00',
                    'ad_cost' => '1200.00',
                    'note' => 'qa hp10',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected record update success'],
    '200 + success=true'
);

$runCase(
    'HP11',
    'Happy Path',
    'Delete daily record for user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userARecordId, $currentMonth) {
        if ($userARecordId <= 0) {
            throw new RuntimeException('Missing record_id for HP11');
        }

        $csrf = requireCsrfToken($clientUserA, '/history.php?month=' . urlencode($currentMonth));

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'delete',
                    'csrf_token' => $csrf,
                    'month' => $currentMonth,
                    'record_id' => (string)$userARecordId,
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected record delete success'],
    '200 + success=true'
);

$runCase(
    'HP12',
    'Happy Path',
    'Update profile display name for user A',
    function () use ($clientUserA, $apiJsonHeaders, $runStamp) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'update_profile',
                    'csrf_token' => $csrf,
                    'display_name' => 'QA User ' . $runStamp,
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected update_profile success'],
    '200 + success=true'
);

$runCase(
    'HP13',
    'Happy Path',
    'Change email for user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userANewEmail, &$userAPassword) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'change_email',
                    'csrf_token' => $csrf,
                    'email' => $userANewEmail,
                    'current_password' => $userAPassword,
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    function (array $response) use (&$userAEmail, &$userANewEmail) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true;

        if ($ok) {
            $userAEmail = $userANewEmail;
        }

        return [$ok, 'Expected change_email success'];
    },
    '200 + success=true'
);

$runCase(
    'HP14',
    'Happy Path',
    'Login stale session client for user A before password change',
    function () use ($clientStaleUserA, $apiJsonHeaders, &$userAEmail, &$userAPassword) {
        $csrf = requireCsrfToken($clientStaleUserA, '/login.php');

        return [
            'client' => $clientStaleUserA,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'login',
                    'csrf_token' => $csrf,
                    'email' => $userAEmail,
                    'password' => $userAPassword,
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected stale client login success'],
    '200 + success=true'
);

$runCase(
    'HP15',
    'Happy Path',
    'Change password for user A',
    function () use ($clientUserA, $apiJsonHeaders, &$userAPassword, &$userANewPassword) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');

        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'change_password',
                    'csrf_token' => $csrf,
                    'current_password' => $userAPassword,
                    'password' => $userANewPassword,
                    'password_confirm' => $userANewPassword,
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    function (array $response) use (&$userAPassword, &$userANewPassword) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true;

        if ($ok) {
            $userAPassword = $userANewPassword;
        }

        return [$ok, 'Expected change_password success'];
    },
    '200 + success=true'
);

$runCase(
    'S06',
    'Security',
    'Session revocation: stale session should be rejected after password change',
    function () use ($clientStaleUserA, $apiJsonHeaders) {
        return [
            'client' => $clientStaleUserA,
            'method' => 'GET',
            'path' => '/api/dashboard-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
            ],
        ];
    },
    function (array $response) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $error = (string)($json['error'] ?? '');
        $ok = (int)($response['status'] ?? 0) === 401 && ($error === 'Session expired' || $error === 'Unauthorized');

        return [$ok, 'Expected stale session to return 401 with Session expired/Unauthorized'];
    },
    '401 + error in {Session expired, Unauthorized}'
);

$runCase(
    'HP16',
    'Happy Path',
    'Fetch dashboard data',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/dashboard-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'range' => 'month_this',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected dashboard-data success'],
    '200 + success=true'
);

$runCase(
    'HP17',
    'Happy Path',
    'Fetch annual data',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/annual-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'year' => date('Y'),
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected annual-data success'],
    '200 + success=true'
);

$runCase(
    'HP18',
    'Happy Path',
    'Fetch overview data',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/overview-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'month' => $currentMonth,
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected overview-data success'],
    '200 + success=true'
);

$runCase(
    'HP19',
    'Happy Path',
    'Export CSV for selected month',
    function () use ($clientUserA, $currentMonth) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/export.php',
            'options' => [
                'headers' => [
                    'Accept' => 'text/csv',
                ],
                'query' => [
                    'month' => $currentMonth,
                ],
            ],
        ];
    },
    function (array $response) {
        $status = (int)($response['status'] ?? 0);
        $headers = array_change_key_case((array)($response['headers'] ?? []), CASE_LOWER);
        $contentType = (string)($headers['content-type'] ?? '');

        $ok = $status === 200 && str_contains(strtolower($contentType), 'text/csv');

        return [$ok, 'Expected 200 CSV response'];
    },
    '200 + Content-Type contains text/csv'
);

$runCase(
    'HP20',
    'Happy Path',
    'Register user B',
    function () use ($clientUserB, $apiJsonHeaders, $userBEmail, $userBPassword) {
        $csrf = requireCsrfToken($clientUserB, '/login.php');

        return [
            'client' => $clientUserB,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'register',
                    'csrf_token' => $csrf,
                    'email' => $userBEmail,
                    'password' => $userBPassword,
                    'password_confirm' => $userBPassword,
                ],
            ],
        ];
    },
    function (array $response) use (&$userBDefaultShopId) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $shopId = (int)($json['data']['shop_id'] ?? 0);
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true && $shopId > 0;
        if ($ok) {
            $userBDefaultShopId = $shopId;
        }

        return [$ok, 'Expected user B register success with shop_id'];
    },
    '200 + success=true + data.shop_id > 0'
);

$runCase(
    'HP21',
    'Happy Path',
    'Upsert daily record for user B',
    function () use ($clientUserB, $apiJsonHeaders, $recordDateB) {
        $csrf = requireCsrfToken($clientUserB, '/add-record.php');

        return [
            'client' => $clientUserB,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'record_date' => $recordDateB,
                    'revenue' => '2222.00',
                    'ad_cost' => '111.00',
                    'note' => 'qa hp21 userb',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected user B record upsert success'],
    '200 + success=true'
);

$userBRecordId = (int)(fetchRecordIdForDate($clientUserB, $currentMonth, $recordDateB) ?? 0);

// -------------------------------
// Validation (18)
// -------------------------------
$runCase(
    'V01',
    'Validation',
    'Register with invalid email format',
    function () use ($clientGuest, $apiJsonHeaders, $userAPassword) {
        $csrf = requireCsrfToken($clientGuest, '/login.php');
        return [
            'client' => $clientGuest,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'register',
                    'csrf_token' => $csrf,
                    'email' => 'invalid-email',
                    'password' => $userAPassword,
                    'password_confirm' => $userAPassword,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'อีเมล'),
    '422 + error mentions invalid email'
);

$runCase(
    'V02',
    'Validation',
    'Register with short password',
    function () use ($clientGuest, $apiJsonHeaders, $runStamp) {
        $csrf = requireCsrfToken($clientGuest, '/login.php');
        return [
            'client' => $clientGuest,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'register',
                    'csrf_token' => $csrf,
                    'email' => 'qa_short_' . $runStamp . '@example.com',
                    'password' => '123',
                    'password_confirm' => '123',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'รหัสผ่านต้องมีอย่างน้อย'),
    '422 + error mentions minimum password length'
);

$runCase(
    'V03',
    'Validation',
    'Register with password confirmation mismatch',
    function () use ($clientGuest, $apiJsonHeaders, $runStamp) {
        $csrf = requireCsrfToken($clientGuest, '/login.php');
        return [
            'client' => $clientGuest,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'register',
                    'csrf_token' => $csrf,
                    'email' => 'qa_mismatch_' . $runStamp . '@example.com',
                    'password' => 'QaPass123!',
                    'password_confirm' => 'QaPass123!x',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ยืนยันรหัสผ่านไม่ตรงกัน'),
    '422 + error mentions password confirmation mismatch'
);

$runCase(
    'V04',
    'Validation',
    'Login with missing password',
    function () use ($clientGuest, $apiJsonHeaders, &$userAEmail) {
        $csrf = requireCsrfToken($clientGuest, '/login.php');
        return [
            'client' => $clientGuest,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'login',
                    'csrf_token' => $csrf,
                    'email' => $userAEmail,
                    'password' => '',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'กรุณากรอกอีเมลและรหัสผ่าน'),
    '422 + error for missing email/password'
);

$runCase(
    'V05',
    'Validation',
    'Create shop with empty name',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'create',
                    'csrf_token' => $csrf,
                    'name' => '',
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'กรุณาระบุชื่อร้านค้า'),
    '422 + error for empty shop name'
);

$runCase(
    'V06',
    'Validation',
    'Create shop with name length > 100',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'create',
                    'csrf_token' => $csrf,
                    'name' => str_repeat('A', 101),
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ยาวเกิน 100'),
    '422 + error for shop name too long'
);

$runCase(
    'V07',
    'Validation',
    'Rename shop with invalid shop_id=0',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'rename',
                    'csrf_token' => $csrf,
                    'shop_id' => '0',
                    'name' => 'Invalid Shop',
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ไม่พบร้านค้า'),
    '422 + error for invalid shop id'
);

$runCase(
    'V08',
    'Validation',
    'Upsert goal with non-numeric target_revenue',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'goal_month' => $currentMonth,
                    'target_revenue' => 'abc',
                    'target_profit' => '',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'รูปแบบเป้าหมายไม่ถูกต้อง'),
    '422 + format error for non-numeric goal'
);

$runCase(
    'V09',
    'Validation',
    'Upsert goal with negative target_revenue',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'goal_month' => $currentMonth,
                    'target_revenue' => '-1',
                    'target_profit' => '',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ต้องไม่ติดลบ'),
    '422 + error for negative target_revenue'
);

$runCase(
    'V10',
    'Validation',
    'Upsert goal with empty revenue and profit',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'goal_month' => $currentMonth,
                    'target_revenue' => '',
                    'target_profit' => '',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'อย่างน้อย 1 เป้าหมาย'),
    '422 + error when both goal fields are empty'
);

$runCase(
    'V11',
    'Validation',
    'Upsert record with invalid date format',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/add-record.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'record_date' => '2026-99-99',
                    'revenue' => '100.00',
                    'ad_cost' => '10.00',
                    'note' => 'v11',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'รูปแบบวันที่ไม่ถูกต้อง'),
    '422 + error for invalid record date'
);

$runCase(
    'V12',
    'Validation',
    'Upsert record with negative revenue',
    function () use ($clientUserA, $apiJsonHeaders, $recordDateA) {
        $csrf = requireCsrfToken($clientUserA, '/add-record.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'record_date' => $recordDateA,
                    'revenue' => '-1',
                    'ad_cost' => '10',
                    'note' => 'v12',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ต้องไม่ติดลบ'),
    '422 + error for negative revenue'
);

$runCase(
    'V13',
    'Validation',
    'Upsert record with non-numeric ad_cost',
    function () use ($clientUserA, $apiJsonHeaders, $recordDateA) {
        $csrf = requireCsrfToken($clientUserA, '/add-record.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'upsert',
                    'csrf_token' => $csrf,
                    'record_date' => $recordDateA,
                    'revenue' => '100',
                    'ad_cost' => 'abc',
                    'note' => 'v13',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'รายได้และค่าแอด'),
    '422 + parse error for non-numeric ad_cost'
);

$runCase(
    'V14',
    'Validation',
    'Update record with record_id=0',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth, $recordDateA) {
        $csrf = requireCsrfToken($clientUserA, '/history.php?month=' . urlencode($currentMonth));
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'update',
                    'csrf_token' => $csrf,
                    'month' => $currentMonth,
                    'record_id' => '0',
                    'record_date' => $recordDateA,
                    'revenue' => '111',
                    'ad_cost' => '11',
                    'note' => 'v14',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ข้อมูลที่ส่งมาไม่ถูกต้อง'),
    '422 + error for invalid record_id in update'
);

$runCase(
    'V15',
    'Validation',
    'Delete record with record_id=0',
    function () use ($clientUserA, $apiJsonHeaders, $currentMonth) {
        $csrf = requireCsrfToken($clientUserA, '/history.php?month=' . urlencode($currentMonth));
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'delete',
                    'csrf_token' => $csrf,
                    'month' => $currentMonth,
                    'record_id' => '0',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'ไม่พบรายการที่ต้องการลบ'),
    '422 + error for invalid record_id in delete'
);

$runCase(
    'V16',
    'Validation',
    'Update profile with empty display_name',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'update_profile',
                    'csrf_token' => $csrf,
                    'display_name' => '',
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'กรุณากรอกชื่อที่แสดง'),
    '422 + error for empty display_name'
);

$runCase(
    'V17',
    'Validation',
    'Change email with invalid format',
    function () use ($clientUserA, $apiJsonHeaders, &$userAPassword) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'change_email',
                    'csrf_token' => $csrf,
                    'email' => 'invalid-email',
                    'current_password' => $userAPassword,
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'กรุณากรอกอีเมลที่ถูกต้อง'),
    '422 + error for invalid email format'
);

$runCase(
    'V18',
    'Validation',
    'Change email with wrong current password',
    function () use ($clientUserA, $apiJsonHeaders, $runStamp) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'change_email',
                    'csrf_token' => $csrf,
                    'email' => 'qa_wrong_pass_' . $runStamp . '@example.com',
                    'current_password' => 'WrongPass999!',
                    'redirect_to' => '/profile.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'รหัสผ่านปัจจุบันไม่ถูกต้อง'),
    '422 + error for wrong current password'
);

// -------------------------------
// Edge cases (10)
// -------------------------------
$runCase(
    'E01',
    'Edge Case',
    'Auth endpoint with invalid action',
    function () use ($clientGuest, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientGuest, '/login.php');
        return [
            'client' => $clientGuest,
            'method' => 'POST',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'invalid_action',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 404, 'Invalid action'),
    '404 + Invalid action'
);

$runCase(
    'E02',
    'Edge Case',
    'Shops endpoint with invalid action',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'invalid_action',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 404, 'Invalid action'),
    '404 + Invalid action'
);

$runCase(
    'E03',
    'Edge Case',
    'Goals endpoint with invalid action',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/dashboard.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/goals.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'invalid_action',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 404, 'Invalid action'),
    '404 + Invalid action'
);

$runCase(
    'E04',
    'Edge Case',
    'Profile endpoint with invalid action',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/profile.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/profile.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'invalid_action',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 404, 'Invalid action'),
    '404 + Invalid action'
);

$runCase(
    'E05',
    'Edge Case',
    'Records endpoint with invalid action',
    function () use ($clientUserA, $apiJsonHeaders) {
        $csrf = requireCsrfToken($clientUserA, '/add-record.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'invalid_action',
                    'csrf_token' => $csrf,
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 404, 'Invalid action'),
    '404 + Invalid action'
);

$runCase(
    'E06',
    'Edge Case',
    'Dashboard data with unsupported range falls back to month_this',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/dashboard-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'range' => 'totally_invalid_range',
                ],
            ],
        ];
    },
    function (array $response) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $rangeType = (string)($json['data']['range']['type'] ?? '');
        $ok = (int)($response['status'] ?? 0) === 200 && (bool)($json['success'] ?? false) === true && $rangeType === 'month_this';

        return [$ok, 'Expected fallback range type to month_this'];
    },
    '200 + success=true + data.range.type=month_this'
);

$runCase(
    'E07',
    'Edge Case',
    'Dashboard custom range with start_date > end_date',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/dashboard-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'range' => 'custom',
                    'start_date' => '2026-12-31',
                    'end_date' => '2026-01-01',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 422, 'วันที่เริ่มต้นต้องไม่มากกว่า'),
    '422 + custom date order validation error'
);

$runCase(
    'E08',
    'Edge Case',
    'Annual data accepts Buddhist year input',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/annual-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'year' => '2569',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected annual-data success with Buddhist year'],
    '200 + success=true'
);

$runCase(
    'E09',
    'Edge Case',
    'Overview data with invalid month falls back to current month',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/overview-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'month' => 'invalid-month',
                ],
            ],
        ];
    },
    fn(array $response) => [(int)($response['status'] ?? 0) === 200 && $assertJsonSuccess($response), 'Expected overview-data fallback success'],
    '200 + success=true'
);

$runCase(
    'E10',
    'Edge Case',
    'Export CSV with invalid month falls back to current month',
    function () use ($clientUserA) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/export.php',
            'options' => [
                'headers' => [
                    'Accept' => 'text/csv',
                ],
                'query' => [
                    'month' => 'invalid-month',
                ],
            ],
        ];
    },
    function (array $response) {
        $status = (int)($response['status'] ?? 0);
        $headers = array_change_key_case((array)($response['headers'] ?? []), CASE_LOWER);
        $contentType = (string)($headers['content-type'] ?? '');

        $ok = $status === 200 && str_contains(strtolower($contentType), 'text/csv');

        return [$ok, 'Expected CSV export fallback success'];
    },
    '200 + Content-Type contains text/csv'
);

// -------------------------------
// Security (remaining 15)
// -------------------------------
$runCase(
    'S01',
    'Security',
    'Auth login action via GET must be blocked by method guard',
    function () use ($clientGuest, $apiJsonHeaders) {
        return [
            'client' => $clientGuest,
            'method' => 'GET',
            'path' => '/api/auth.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'action' => 'login',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 405, 'Method Not Allowed'),
    '405 + Method Not Allowed'
);

$runCase(
    'S02',
    'Security',
    'Shops create via GET must be blocked by method guard',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'GET',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'query' => [
                    'action' => 'create',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 405, 'Method Not Allowed'),
    '405 + Method Not Allowed'
);

$runCase(
    'S03',
    'Security',
    'Annual data via POST must be blocked by method guard',
    function () use ($clientUserA, $apiJsonHeaders) {
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/annual-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'year' => date('Y'),
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 405, 'Method Not Allowed'),
    '405 + Method Not Allowed'
);

$runCase(
    'S04',
    'Security',
    'Create shop without CSRF token must be rejected',
    function () use ($clientUserA, $apiJsonHeaders, $runStamp) {
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'create',
                    'name' => 'NoCSRF ' . $runStamp,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 403, 'Invalid CSRF token'),
    '403 + Invalid CSRF token'
);

$runCase(
    'S05',
    'Security',
    'Create shop with invalid CSRF token must be rejected',
    function () use ($clientUserA, $apiJsonHeaders, $runStamp) {
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'create',
                    'csrf_token' => 'invalid-csrf-token',
                    'name' => 'BadCSRF ' . $runStamp,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 403, 'Invalid CSRF token'),
    '403 + Invalid CSRF token'
);

$runCase(
    'S07',
    'Security',
    'IDOR check: user A cannot rename user B shop',
    function () use ($clientUserA, $apiJsonHeaders, &$userBDefaultShopId) {
        if ($userBDefaultShopId <= 0) {
            throw new RuntimeException('Missing user B shop id for S07');
        }

        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'rename',
                    'csrf_token' => $csrf,
                    'shop_id' => (string)$userBDefaultShopId,
                    'name' => 'HACK_RENAME_ATTEMPT',
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 403, 'ไม่มีสิทธิ์'),
    '403 + authorization error'
);

$runCase(
    'S08',
    'Security',
    'IDOR check: user A cannot switch to user B shop',
    function () use ($clientUserA, $apiJsonHeaders, &$userBDefaultShopId) {
        if ($userBDefaultShopId <= 0) {
            throw new RuntimeException('Missing user B shop id for S08');
        }

        $csrf = requireCsrfToken($clientUserA, '/shops.php');
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/shops.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'switch',
                    'csrf_token' => $csrf,
                    'shop_id' => (string)$userBDefaultShopId,
                    'redirect_to' => '/shops.php',
                ],
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 403, 'ไม่มีสิทธิ์'),
    '403 + authorization error'
);

$runCase(
    'S09',
    'Security',
    'IDOR check: user A cannot delete record owned by user B',
    function () use ($clientUserA, $apiJsonHeaders, &$userBRecordId, $currentMonth) {
        if ($userBRecordId <= 0) {
            throw new RuntimeException('Missing user B record id for S09');
        }

        $csrf = requireCsrfToken($clientUserA, '/history.php?month=' . urlencode($currentMonth));
        return [
            'client' => $clientUserA,
            'method' => 'POST',
            'path' => '/api/records.php',
            'options' => [
                'headers' => $apiJsonHeaders,
                'form' => [
                    'action' => 'delete',
                    'csrf_token' => $csrf,
                    'month' => $currentMonth,
                    'record_id' => (string)$userBRecordId,
                ],
            ],
        ];
    },
    function (array $response) {
        $json = decodeJsonBody((string)($response['body'] ?? ''));
        $status = (int)($response['status'] ?? 0);
        $error = (string)($json['error'] ?? '');
        $ok = $status === 422 && str_contains($error, 'ไม่พบรายการ');

        return [$ok, 'Expected 422 not found for cross-tenant record delete'];
    },
    '422 + not found (no cross-tenant delete)'
);

$runCase(
    'S10',
    'Security',
    'Unauthenticated client cannot access dashboard-data',
    function () use ($clientUnauth, $apiJsonHeaders) {
        return [
            'client' => $clientUnauth,
            'method' => 'GET',
            'path' => '/api/dashboard-data.php',
            'options' => [
                'headers' => $apiJsonHeaders,
            ],
        ];
    },
    fn(array $response) => $assertStatusAndJsonErrorContains($response, 401, 'Unauthorized'),
    '401 + Unauthorized'
);

for ($attemptIndex = 1; $attemptIndex <= 6; $attemptIndex++) {
    $caseId = 'S11' . chr(64 + $attemptIndex);
    $expectedStatus = $attemptIndex < 6 ? 422 : 429;

    $runCase(
        $caseId,
        'Security',
        'Brute-force-lite profile change_password attempt #' . $attemptIndex,
        function () use ($clientUserA, $apiJsonHeaders) {
            $csrf = requireCsrfToken($clientUserA, '/profile.php');

            return [
                'client' => $clientUserA,
                'method' => 'POST',
                'path' => '/api/profile.php',
                'options' => [
                    'headers' => $apiJsonHeaders,
                    'form' => [
                        'action' => 'change_password',
                        'csrf_token' => $csrf,
                        'current_password' => 'WrongPassword!999',
                        'password' => 'TempPass123!',
                        'password_confirm' => 'TempPass123!',
                        'redirect_to' => '/profile.php',
                    ],
                ],
            ];
        },
        function (array $response) use ($expectedStatus) {
            $json = decodeJsonBody((string)($response['body'] ?? ''));
            $status = (int)($response['status'] ?? 0);
            $error = (string)($json['error'] ?? '');

            if ($expectedStatus === 422) {
                $ok = $status === 422 && str_contains($error, 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
                return [$ok, 'Expected 422 wrong current password before rate limit'];
            }

            $ok = $status === 429 && str_contains($error, 'ลองเปลี่ยนรหัสผ่านบ่อยเกินไป');
            return [$ok, 'Expected 429 rate-limit on 6th attempt'];
        },
        $expectedStatus . ' with expected brute-force response'
    );
}

fclose($runLogHandle);

$passedCount = 0;
$failedCount = 0;
$flakyCount = 0;

$categorySummary = [];
foreach ($results as $result) {
    $status = (string)$result['status'];
    $category = (string)$result['category'];

    if (!isset($categorySummary[$category])) {
        $categorySummary[$category] = [
            'passed' => 0,
            'failed' => 0,
            'flaky' => 0,
            'total' => 0,
        ];
    }

    $categorySummary[$category]['total']++;

    if ($status === 'passed') {
        $passedCount++;
        $categorySummary[$category]['passed']++;
    } elseif ($status === 'flaky') {
        $flakyCount++;
        $categorySummary[$category]['flaky']++;
    } else {
        $failedCount++;
        $categorySummary[$category]['failed']++;
    }
}

$testCasesMd = [];
$testCasesMd[] = '# QA API Test Cases';
$testCasesMd[] = '';
$testCasesMd[] = '- Generated at: ' . $runStartedAt;
$testCasesMd[] = '- Base URL: `' . $baseUrl . '`';
$testCasesMd[] = '- Total Cases: ' . count($caseSpecs);
$testCasesMd[] = '- Flaky Criteria: first attempt fails, immediate retry (GET-only) passes';
$testCasesMd[] = '';
$testCasesMd[] = '| ID | Category | Title | Expected |';
$testCasesMd[] = '|---|---|---|---|';

foreach ($caseSpecs as $spec) {
    $testCasesMd[] = '| ' . $spec['id'] . ' | ' . $spec['category'] . ' | ' . str_replace('|', '\\|', $spec['title']) . ' | ' . str_replace('|', '\\|', $spec['expected']) . ' |';
}

file_put_contents($testCasesPath, implode(PHP_EOL, $testCasesMd) . PHP_EOL);

$failedOrFlaky = array_values(array_filter(
    $results,
    static fn(array $row): bool => in_array((string)$row['status'], ['failed', 'flaky'], true)
));

$reportMd = [];
$reportMd[] = '# QA Run Report';
$reportMd[] = '';
$reportMd[] = '- Started At: ' . $runStartedAt;
$reportMd[] = '- Finished At: ' . date('c');
$reportMd[] = '- Base URL: `' . $baseUrl . '`';
$reportMd[] = '- Tooling: PHP cURL-based API runner (`qa_runner.php`)';
$reportMd[] = '- Flaky Criteria: first attempt fails, immediate retry (GET-only) passes';
$reportMd[] = '';
$reportMd[] = '## Summary';
$reportMd[] = '';
$reportMd[] = '- Total: ' . count($results);
$reportMd[] = '- Passed: ' . $passedCount;
$reportMd[] = '- Failed: ' . $failedCount;
$reportMd[] = '- Flaky: ' . $flakyCount;
$reportMd[] = '';
$reportMd[] = '### By Category';
$reportMd[] = '';
$reportMd[] = '| Category | Total | Passed | Failed | Flaky |';
$reportMd[] = '|---|---:|---:|---:|---:|';

ksort($categorySummary);
foreach ($categorySummary as $category => $stats) {
    $reportMd[] = '| ' . $category . ' | ' . $stats['total'] . ' | ' . $stats['passed'] . ' | ' . $stats['failed'] . ' | ' . $stats['flaky'] . ' |';
}

$reportMd[] = '';
$reportMd[] = '## Failed / Flaky Details';
$reportMd[] = '';

if (empty($failedOrFlaky)) {
    $reportMd[] = 'No failed or flaky cases in this run.';
} else {
    foreach ($failedOrFlaky as $row) {
        $requestMeta = is_array($row['request']) ? $row['request'] : [];
        $method = (string)($requestMeta['method'] ?? '');
        $url = (string)($requestMeta['url'] ?? '');
        $path = (string)($requestMeta['path'] ?? '');

        $reportMd[] = '### ' . $row['id'] . ' - ' . $row['title'];
        $reportMd[] = '';
        $reportMd[] = '- Status: **' . strtoupper((string)$row['status']) . '**';
        $reportMd[] = '- Expected: ' . (string)$row['expected'];
        $reportMd[] = '- Observed: ' . (string)$row['message'];
        $reportMd[] = '- Reproducible Steps:';
        $reportMd[] = '  1. Send `' . $method . '` to `' . $url . '`';
        $reportMd[] = '  2. Observe mismatch against expected assertion';

        $rootCauseHint = 'Inspect endpoint behavior and validation branch for this action.';
        if (str_contains($path, '/api/profile.php')) {
            $rootCauseHint = 'Likely in profile API action flow @api/profile.php#116-199';
        } elseif (str_contains($path, '/api/shops.php')) {
            $rootCauseHint = 'Likely in shops API action flow @api/shops.php#28-193';
        } elseif (str_contains($path, '/api/records.php')) {
            $rootCauseHint = 'Likely in records API action flow @api/records.php#24-129';
        } elseif (str_contains($path, '/api/auth.php')) {
            $rootCauseHint = 'Likely in auth API action flow @api/auth.php#21-155';
        }

        $reportMd[] = '- Root Cause (inferred): ' . $rootCauseHint;
        $reportMd[] = '- Fix Recommendation:';
        $reportMd[] = '  - Add/adjust guard or validation branch to return expected status+payload.';
        $reportMd[] = '  - Add regression check in runner case `' . $row['id'] . '` after fix.';
        $reportMd[] = '';
    }
}

$reportMd[] = '';
$reportMd[] = '## Artifacts';
$reportMd[] = '';
$reportMd[] = '- A) `test_cases.md`';
$reportMd[] = '- B) `run_log.jsonl`';
$reportMd[] = '- C) `report.md`';

file_put_contents($reportPath, implode(PHP_EOL, $reportMd) . PHP_EOL);

echo 'QA run completed.' . PHP_EOL;
echo 'Base URL: ' . $baseUrl . PHP_EOL;
echo 'Total cases: ' . count($results) . PHP_EOL;
echo 'Passed: ' . $passedCount . PHP_EOL;
echo 'Failed: ' . $failedCount . PHP_EOL;
echo 'Flaky: ' . $flakyCount . PHP_EOL;
echo 'Artifacts:' . PHP_EOL;
echo ' - ' . $testCasesPath . PHP_EOL;
echo ' - ' . $runLogPath . PHP_EOL;
echo ' - ' . $reportPath . PHP_EOL;
