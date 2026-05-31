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
    'batch-id::',
    'json',
    'timeout::',
    'help',
]);

if (isset($options['help'])) {
    recipeStockOperationsSurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipeStockOperationsSurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$batchId = max(0, (int) ($options['batch-id'] ?? 0));

$result = recipeStockOperationsSurfaceSmoke($baseUrl, $cookie, $timeout, $batchId);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipeStockOperationsSurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeStockOperationsSurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_stock_operations_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--batch-id=123] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke for recipe production and waste/stock-adjustment operator surfaces.\n");
    fwrite(STDOUT, "Use an already-authenticated operator session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "It checks production and waste page controls, mode-off messaging when present, and selected draft-batch controls when --batch-id is supplied. If --batch-id is omitted and commit controls are not rendered, it records a fixture-selection warning instead of pretending production commit UI was inspected.\n");
}

function recipeStockOperationsSurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipeStockOperationsSurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipeStockOperationsSurfaceSmokeNormalizeCookieSource(string $source): string
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

function recipeStockOperationsSurfaceSmoke(string $baseUrl, string $cookie, int $timeout, int $batchId): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'batch_id' => $batchId,
            'checks' => [],
            'blockers' => ['recipe_stock_operations_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_stock_operations_surface_smoke_cookie_missing';
    }

    $checks = [
        recipeStockOperationsSurfaceSmokeProductionPage($baseUrl, $cookie, $timeout, $batchId),
        recipeStockOperationsSurfaceSmokeWastePage($baseUrl, $cookie, $timeout),
    ];

    foreach ($checks as $check) {
        foreach (($check['blockers'] ?? []) as $blocker) {
            $blockers[] = 'recipe_stock_operations_surface_' . $check['name'] . '_' . $blocker;
        }
        foreach (($check['warnings'] ?? []) as $warning) {
            $warnings[] = 'recipe_stock_operations_surface_' . $check['name'] . '_' . $warning;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'batch_id' => $batchId,
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipeStockOperationsSurfaceSmokeProductionPage(string $baseUrl, string $cookie, int $timeout, int $batchId): array
{
    $path = 'recipe_production.php' . ($batchId > 0 ? '?batch_id=' . rawurlencode((string) $batchId) : '');
    $url = $baseUrl . '/' . $path;
    $fetch = recipeStockOperationsSurfaceSmokeFetch($url, $cookie, $timeout);
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeStockOperationsSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];

    $baseSnippets = [
        'تشغيل الإنتاج',
        'مسودة إنتاج جديدة',
        'name="action" value="create_draft"',
        'name="planned_output_qty"',
    ];
    $modeOffSnippet = 'أوامر الإنتاج متوقفة من إعدادات الوصفات الحالية';
    $selectedBatchSnippets = [
        'تأكيد الإنتاج',
        'إلغاء المسودة',
        'name="action" value="commit"',
        'name="actual_output_qty"',
        'name="variance_reason"',
        'name="action" value="cancel"',
        'name="cancel_reason"',
        'معاينة المدخلات',
        'حركات الإنتاج المثبتة',
        'المتاح',
        'العجز',
    ];

    $missingBaseSnippets = [];
    $missingSelectedBatchSnippets = [];

    if ($blockers === []) {
        foreach ($baseSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingBaseSnippets[] = $snippet;
            }
        }
        foreach ($selectedBatchSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingSelectedBatchSnippets[] = $snippet;
            }
        }

        if ($missingBaseSnippets !== []) {
            $blockers[] = 'expected_production_shell_missing';
        }
        if ($missingSelectedBatchSnippets !== []) {
            if ($batchId > 0) {
                $blockers[] = 'expected_selected_batch_controls_missing';
            } else {
                $warnings[] = 'batch_id_missing_selected_batch_controls_not_rendered';
            }
        }
    }

    return [
        'name' => 'production_page',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'batch_id' => $batchId,
        'expected_shell_snippets' => $baseSnippets,
        'mode_off_snippet' => $modeOffSnippet,
        'mode_off_found' => strpos($body, $modeOffSnippet) !== false,
        'expected_selected_batch_snippets' => $selectedBatchSnippets,
        'missing_shell_snippets' => $missingBaseSnippets,
        'missing_selected_batch_snippets' => $missingSelectedBatchSnippets,
        'selected_batch_controls_checked' => $missingSelectedBatchSnippets === [],
        'login_detected' => recipeStockOperationsSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeStockOperationsSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeStockOperationsSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeStockOperationsSurfaceSmokeWastePage(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/inventory_adjustments.php?from=recipe_stock_smoke';
    $fetch = recipeStockOperationsSurfaceSmokeFetch($url, $cookie, $timeout);
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeStockOperationsSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $expectedSnippets = [
        'الهالك والتسويات',
        'data-adjustment-action="waste"',
        'data-adjustment-action="increase"',
        'data-adjustment-action="decrease"',
        'inventory-adjustment-csrf',
        'inventoryAdjustmentReasonCode',
        'inventoryAdjustmentPhoto',
        'ajax/inventory_adjustment.php',
        'آخر العمليات',
        'المتوفر',
        'على اليد',
    ];
    $modeOffSnippet = 'هذه الشاشة جاهزة، لكن التسجيل يحتاج وضع bridge أو live للمخزون';
    $missingSnippets = [];

    if ($blockers === []) {
        foreach ($expectedSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingSnippets[] = $snippet;
            }
        }
        if ($missingSnippets !== []) {
            $blockers[] = 'expected_waste_adjustment_ui_missing';
        }
    }

    return [
        'name' => 'waste_adjustment_page',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'expected_snippets' => $expectedSnippets,
        'mode_off_snippet' => $modeOffSnippet,
        'mode_off_found' => strpos($body, $modeOffSnippet) !== false,
        'missing_snippets' => $missingSnippets,
        'login_detected' => recipeStockOperationsSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeStockOperationsSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeStockOperationsSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeStockOperationsSurfaceSmokeCommonBlockers(array $fetch, string $body): array
{
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipeStockOperationsSurfaceSmokeLoginDetected($body)) {
        $blockers[] = 'login_required';
    }
    if (recipeStockOperationsSurfaceSmokeAccessDenied($body)) {
        $blockers[] = 'access_denied';
    }
    if (recipeStockOperationsSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return array_values(array_unique($blockers));
}

function recipeStockOperationsSurfaceSmokeFetch(string $url, string $cookie, int $timeout): array
{
    $headers = [
        'Accept: text/html,application/xhtml+xml',
        'User-Agent: POSMAIN recipe stock operations surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipeStockOperationsSurfaceSmokeFetchCurl($url, $headers, $timeout);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    return [
        'status' => recipeStockOperationsSurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'file_get_contents_failed',
    ];
}

function recipeStockOperationsSurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    if (!is_string($raw)) {
        return [
            'status' => $status,
            'headers' => [],
            'body' => '',
            'error' => $error !== '' ? $error : 'curl_exec_failed',
        ];
    }

    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    return [
        'status' => $status,
        'headers' => preg_split('/\r\n|\n|\r/', trim($headerText)) ?: [],
        'body' => $body,
        'error' => $error,
    ];
}

function recipeStockOperationsSurfaceSmokeStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function recipeStockOperationsSurfaceSmokeLoginDetected(string $body): bool
{
    $lower = strtolower($body);

    return strpos($lower, 'name="password"') !== false
        || strpos($lower, "name='password'") !== false
        || strpos($lower, 'type="password"') !== false
        || strpos($lower, "type='password'") !== false;
}

function recipeStockOperationsSurfaceSmokeAccessDenied(string $body): bool
{
    $lower = strtolower($body);

    return strpos($lower, 'access denied') !== false
        || strpos($lower, 'permission denied') !== false
        || strpos($body, 'غير مصرح') !== false
        || strpos($body, 'ليس لديك صلاحية') !== false;
}

function recipeStockOperationsSurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipeStockOperationsSurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe stock operations surface smoke: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
    fwrite(STDOUT, 'Base URL: ' . ($result['base_url'] ?? '') . PHP_EOL);
    foreach (($result['checks'] ?? []) as $check) {
        $blockers = $check['blockers'] ?? [];
        $warnings = $check['warnings'] ?? [];
        fwrite(STDOUT, sprintf(
            '- %s: HTTP %s, blockers=%d, warnings=%d',
            (string) ($check['name'] ?? 'unknown'),
            (string) ($check['status'] ?? '0'),
            count($blockers),
            count($warnings)
        ) . PHP_EOL);
    }
    foreach (($result['blockers'] ?? []) as $blocker) {
        fwrite(STDOUT, 'BLOCKER: ' . $blocker . PHP_EOL);
    }
    foreach (($result['warnings'] ?? []) as $warning) {
        fwrite(STDOUT, 'WARNING: ' . $warning . PHP_EOL);
    }
}
