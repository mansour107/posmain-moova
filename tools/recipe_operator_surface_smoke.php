<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/recipe_surface_smoke_error_detector.php';

$options = getopt('', [
    'base-url::',
    'cookie::',
    'cookie-file::',
    'expect-mode-off',
    'json',
    'timeout::',
    'help',
]);

if (isset($options['help'])) {
    recipeOperatorSurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipeOperatorSurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$expectModeOff = isset($options['expect-mode-off']);

$result = recipeOperatorSurfaceSmoke($baseUrl, $cookie, $timeout, $expectModeOff);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeOperatorSurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeOperatorSurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_operator_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--expect-mode-off] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke against recipe operator/report pages.\n");
    fwrite(STDOUT, "Use an already-authenticated browser/session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "It does not apply migrations or make any database writes; it only reads authenticated page responses.\n");
    fwrite(STDOUT, "The smoke checks expected headings, login redirects, access-denied/fatal/SQL text, and optional mode-off disabled messaging. It complements browser QA but does not inspect JavaScript console logs or screenshots.\n");
}

function recipeOperatorSurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }
        return recipeOperatorSurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipeOperatorSurfaceSmokeNormalizeCookieSource(string $source): string
{
    $source = trim($source);
    if ($source === '') {
        return '';
    }

    $cookies = [];
    foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $isHttpOnlyCookieJarLine = strpos($line, '#HttpOnly_') === 0;
        if ($line[0] === '#' && !$isHttpOnlyCookieJarLine) {
            continue;
        }

        $fields = preg_split('/\t+/', $line);
        if (is_array($fields) && count($fields) >= 7) {
            $name = trim((string) $fields[5]);
            $value = trim((string) $fields[6]);
            if ($name !== '') {
                $cookies[] = $name . '=' . $value;
            }
        }
    }

    if ($cookies !== []) {
        return implode('; ', $cookies);
    }

    return $source;
}

function recipeOperatorSurfaceSmoke(string $baseUrl, string $cookie, int $timeout, bool $expectModeOff): array
{
    $blockers = [];
    $warnings = [];
    $pages = [];

    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'pages' => [],
            'blockers' => ['recipe_operator_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    if ($cookie === '') {
        $warnings[] = 'recipe_operator_surface_smoke_cookie_missing';
    }

    foreach (recipeOperatorSurfaceSmokePages() as $page) {
        $url = $baseUrl . '/' . $page['path'];
        $fetch = recipeOperatorSurfaceSmokeFetch($url, $cookie, $timeout);
        $body = (string) ($fetch['body'] ?? '');
        $pageBlockers = [];
        $status = (int) ($fetch['status'] ?? 0);
        $fetchError = (string) ($fetch['error'] ?? '');
        $loginDetected = recipeOperatorSurfaceSmokeLoginDetected($body);
        $accessDenied = recipeOperatorSurfaceSmokeAccessDenied($body);
        $fatalOrSqlText = recipeOperatorSurfaceSmokeFatalText($body);
        $headingFound = strpos($body, $page['heading']) !== false;

        if ($fetchError !== '') {
            $pageBlockers[] = 'http_fetch_failed';
        }
        if ($status >= 400 || $status < 200) {
            $pageBlockers[] = 'http_status_' . $status;
        }
        if ($loginDetected) {
            $pageBlockers[] = 'login_required';
        }
        if ($accessDenied) {
            $pageBlockers[] = 'access_denied';
        }
        if ($fatalOrSqlText) {
            $pageBlockers[] = 'fatal_or_sql_text';
        }

        $pageSpecificChecksAllowed = $pageBlockers === [];
        if ($pageSpecificChecksAllowed && !$headingFound) {
            $pageBlockers[] = 'expected_heading_missing';
        }
        $modeOffRequired = $expectModeOff && !empty($page['mode_off_message']);
        $modeOffFound = $page['mode_off_message'] === '' || strpos($body, $page['mode_off_message']) !== false;
        if ($pageSpecificChecksAllowed && $modeOffRequired && !$modeOffFound) {
            $pageBlockers[] = 'mode_off_message_missing';
        }

        $pages[] = [
            'name' => $page['name'],
            'url' => $url,
            'status' => $status,
            'heading' => $page['heading'],
            'heading_found' => $headingFound,
            'mode_off_expected' => $modeOffRequired,
            'mode_off_found' => $modeOffFound,
            'login_detected' => $loginDetected,
            'access_denied' => $accessDenied,
            'fatal_or_sql_text' => $fatalOrSqlText,
            'body_bytes' => strlen($body),
            'error' => $fetchError,
            'blockers' => $pageBlockers,
        ];

        foreach ($pageBlockers as $blocker) {
            $blockers[] = 'recipe_operator_surface_' . $page['name'] . '_' . $blocker;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'expect_mode_off' => $expectModeOff,
        'pages' => $pages,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipeOperatorSurfaceSmokePages(): array
{
    return [
        [
            'name' => 'operational_dashboard',
            'path' => 'recipe_operational_dashboard.php',
            'heading' => 'Recipe Operational Dashboard',
            'mode_off_message' => '',
        ],
        [
            'name' => 'stock_reconciliation',
            'path' => 'recipe_stock_reconciliation.php',
            'heading' => 'Recipe Stock Reconciliation',
            'mode_off_message' => '',
        ],
        [
            'name' => 'operations_report',
            'path' => 'recipe_operations_report.php',
            'heading' => 'Recipe Operations Reports',
            'mode_off_message' => '',
        ],
        [
            'name' => 'recipe_manage',
            'path' => 'recipe_manage.php',
            'heading' => 'Recipe Draft Management',
            'mode_off_message' => 'Recipe writes are disabled by the current feature flags. Mode: off.',
        ],
        [
            'name' => 'production',
            'path' => 'recipe_production.php',
            'heading' => 'Recipe Production Batches',
            'mode_off_message' => 'Production batch writes are disabled by the current recipe feature flags. Mode: off.',
        ],
        [
            'name' => 'waste',
            'path' => 'inventory_adjustments.php',
            'heading' => 'الهالك والتسويات',
            'mode_off_message' => 'هذه الشاشة جاهزة، لكن التسجيل يحتاج وضع bridge أو live للمخزون.',
        ],
        [
            'name' => 'audit',
            'path' => 'recipe_audit_report.php',
            'heading' => 'Recipe Audit Log',
            'mode_off_message' => '',
        ],
        [
            'name' => 'editor',
            'path' => 'recipe_editor.php',
            'heading' => 'Recipe Editor - Read Only',
            'mode_off_message' => '',
        ],
    ];
}

function recipeOperatorSurfaceSmokeFetch(string $url, string $cookie, int $timeout): array
{
    $headers = [
        'Accept: text/html,application/xhtml+xml',
        'User-Agent: POSMAIN recipe operator surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipeOperatorSurfaceSmokeFetchCurl($url, $headers, $timeout);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    return [
        'status' => recipeOperatorSurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'Unable to fetch URL.',
    ];
}

function recipeOperatorSurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
{
    $handle = curl_init($url);
    if (!$handle) {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'Unable to initialize HTTP client.',
        ];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

    if (!is_string($response)) {
        return [
            'status' => $status,
            'headers' => [],
            'body' => '',
            'error' => $error !== '' ? $error : 'Unable to fetch URL.',
        ];
    }

    $rawHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'status' => $status,
        'headers' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $rawHeaders) ?: []))),
        'body' => $body,
        'error' => '',
    ];
}

function recipeOperatorSurfaceSmokeStatus(array $headers): int
{
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}

function recipeOperatorSurfaceSmokeLoginDetected(string $body): bool
{
    return strpos($body, 'اسم المستخدم أو البريد أو الهاتف') !== false
        || strpos($body, 'name="uname"') !== false
        || strpos($body, 'login-card') !== false;
}

function recipeOperatorSurfaceSmokeAccessDenied(string $body): bool
{
    return (bool) preg_match('/Access denied|Forbidden|غير مصرح|لا تملك صلاحية/u', $body);
}

function recipeOperatorSurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipeOperatorSurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe operator surface smoke: ' . (!empty($result['ok']) ? 'OK' : 'FAILED') . PHP_EOL);
    fwrite(STDOUT, '- base URL: ' . (string) ($result['base_url'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- pages checked: ' . count($result['pages'] ?? []) . PHP_EOL);

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }

    if (!empty($result['warnings'])) {
        fwrite(STDOUT, "- warnings:\n");
        foreach ($result['warnings'] as $warning) {
            fwrite(STDOUT, '  - ' . (string) $warning . PHP_EOL);
        }
    }

    foreach ($result['pages'] ?? [] as $page) {
        $line = '  - ' . (string) ($page['name'] ?? 'page')
            . ': HTTP ' . (int) ($page['status'] ?? 0)
            . ', heading=' . (!empty($page['heading_found']) ? 'yes' : 'no')
            . ', blockers=' . count($page['blockers'] ?? []);
        fwrite(STDOUT, $line . PHP_EOL);
    }
}
