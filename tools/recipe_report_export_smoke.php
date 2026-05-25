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
    'json',
    'timeout::',
    'help',
]);

if (isset($options['help'])) {
    recipeReportExportSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipeReportExportSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));

$result = recipeReportExportSmoke($baseUrl, $cookie, $timeout);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipeReportExportSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeReportExportSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_report_export_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke against recipe CSV exports.\n");
    fwrite(STDOUT, "Use an already-authenticated browser/session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "The smoke checks CSV response headers, expected CSV columns, login redirects, access-denied/fatal/SQL text, and spreadsheet-formula-safe exported cells. It complements browser QA but does not inspect JavaScript console logs or screenshots.\n");
}

function recipeReportExportSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipeReportExportSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipeReportExportSmokeNormalizeCookieSource(string $source): string
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

function recipeReportExportSmoke(string $baseUrl, string $cookie, int $timeout): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'exports' => [],
            'blockers' => ['recipe_report_export_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    $exports = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_report_export_smoke_cookie_missing';
    }

    foreach (recipeReportExportSmokeTargets() as $target) {
        $url = $baseUrl . '/' . $target['path'] . '?' . http_build_query($target['query']);
        $fetch = recipeReportExportSmokeFetch($url, $cookie, $timeout);
        $body = (string) ($fetch['body'] ?? '');
        $status = (int) ($fetch['status'] ?? 0);
        $fetchError = (string) ($fetch['error'] ?? '');
        $headers = $fetch['headers'] ?? [];
        $exportBlockers = [];
        $loginDetected = recipeReportExportSmokeLoginDetected($body);
        $accessDenied = recipeReportExportSmokeAccessDenied($body);
        $fatalOrSqlText = recipeReportExportSmokeFatalText($body);

        if ($fetchError !== '') {
            $exportBlockers[] = 'http_fetch_failed';
        }
        if ($status >= 400 || $status < 200) {
            $exportBlockers[] = 'http_status_' . $status;
        }
        if ($loginDetected) {
            $exportBlockers[] = 'login_required';
        }
        if ($accessDenied) {
            $exportBlockers[] = 'access_denied';
        }
        if ($fatalOrSqlText) {
            $exportBlockers[] = 'fatal_or_sql_text';
        }

        $exportSpecificChecksAllowed = $exportBlockers === [];
        $isCsv = $exportSpecificChecksAllowed
            ? recipeReportExportSmokeHasHeader($headers, 'content-type', 'text/csv')
            : false;
        $hasAttachment = $exportSpecificChecksAllowed
            ? recipeReportExportSmokeHasHeader($headers, 'content-disposition', '.csv')
            : false;
        $csvRows = $exportSpecificChecksAllowed ? recipeReportExportSmokeRows($body) : [];
        $header = $csvRows[0] ?? [];
        $expectedColumnsPresent = $exportSpecificChecksAllowed
            ? recipeReportExportSmokeExpectedColumnsPresent($header, $target['expected_columns'])
            : false;
        $unsafeCells = $exportSpecificChecksAllowed ? recipeReportExportSmokeUnsafeCells($csvRows) : [];

        if ($exportSpecificChecksAllowed && !$isCsv) {
            $exportBlockers[] = 'csv_content_type_missing';
        }
        if ($exportSpecificChecksAllowed && !$hasAttachment) {
            $exportBlockers[] = 'csv_attachment_header_missing';
        }
        if ($exportSpecificChecksAllowed && !$expectedColumnsPresent) {
            $exportBlockers[] = 'expected_csv_columns_missing';
        }
        if ($exportSpecificChecksAllowed && $unsafeCells !== []) {
            $exportBlockers[] = 'unsafe_csv_formula_cells';
        }

        $exports[] = [
            'name' => $target['name'],
            'url' => $url,
            'status' => $status,
            'content_type_csv' => $isCsv,
            'attachment_csv' => $hasAttachment,
            'expected_columns' => $target['expected_columns'],
            'header' => $header,
            'expected_columns_present' => $expectedColumnsPresent,
            'login_detected' => $loginDetected,
            'access_denied' => $accessDenied,
            'fatal_or_sql_text' => $fatalOrSqlText,
            'row_count' => max(0, count($csvRows) - 1),
            'unsafe_cell_count' => count($unsafeCells),
            'unsafe_cells' => array_slice($unsafeCells, 0, 10),
            'body_bytes' => strlen($body),
            'error' => $fetchError,
            'blockers' => $exportBlockers,
        ];

        foreach ($exportBlockers as $blocker) {
            $blockers[] = 'recipe_report_export_' . $target['name'] . '_' . $blocker;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'exports' => $exports,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipeReportExportSmokeTargets(): array
{
    $operationsReports = [
        'cost_history' => ['Calculated', 'Recipe', 'Item', 'Version'],
        'ingredient_consumption' => ['Ingredient', 'Qty Consumed', 'Rows'],
        'recipe_cogs' => ['Item', 'Recipe', 'Recipe COGS'],
        'production_variance' => ['Recipe', 'Planned', 'Actual', 'Variance Qty'],
        'low_stock_impact' => ['Menu Item', 'Available Qty', 'Reason'],
        'cogs_reconciliation' => ['Movement Cost', 'Journal Debit', 'Journal Credit'],
        'expected_vs_actual_usage' => ['Ingredient', 'Expected Qty', 'Actual Qty'],
        'modifier_revenue_cost' => ['Modifier', 'Revenue', 'Ingredient Cost'],
    ];

    $targets = [
        [
            'name' => 'stock_reconciliation',
            'path' => 'recipe_stock_reconciliation.php',
            'query' => ['export' => 'csv', 'limit' => '10'],
            'expected_columns' => ['item_id', 'legacy_qty', 'ledger_qty', 'legacy_vs_ledger_difference'],
        ],
        [
            'name' => 'audit',
            'path' => 'recipe_audit_report.php',
            'query' => ['export' => 'csv', 'limit' => '10'],
            'expected_columns' => ['created_at', 'action', 'entity_type', 'actor_user_id'],
        ],
    ];

    foreach ($operationsReports as $report => $expectedColumns) {
        $targets[] = [
            'name' => 'operations_' . $report,
            'path' => 'recipe_operations_report.php',
            'query' => ['report' => $report, 'export' => 'csv', 'limit' => '10'],
            'expected_columns' => $expectedColumns,
        ];
    }

    return $targets;
}

function recipeReportExportSmokeFetch(string $url, string $cookie, int $timeout): array
{
    $headers = [
        'Accept: text/csv, text/plain;q=0.9, */*;q=0.1',
        'User-Agent: POSMAIN recipe report export smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipeReportExportSmokeFetchCurl($url, $headers, $timeout);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    return [
        'status' => recipeReportExportSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'Unable to fetch URL.',
    ];
}

function recipeReportExportSmokeFetchCurl(string $url, array $headers, int $timeout): array
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

function recipeReportExportSmokeStatus(array $headers): int
{
    $status = 0;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}

function recipeReportExportSmokeHasHeader(array $headers, string $headerName, string $contains): bool
{
    $headerName = strtolower($headerName);
    $contains = strtolower($contains);
    foreach ($headers as $header) {
        $parts = explode(':', (string) $header, 2);
        if (count($parts) !== 2) {
            continue;
        }
        if (strtolower(trim($parts[0])) !== $headerName) {
            continue;
        }
        if (strpos(strtolower(trim($parts[1])), $contains) !== false) {
            return true;
        }
    }

    return false;
}

function recipeReportExportSmokeRows(string $body): array
{
    $rows = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($body)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $rows[] = str_getcsv($line, ',', '"', '\\');
    }

    return $rows;
}

function recipeReportExportSmokeExpectedColumnsPresent(array $header, array $expectedColumns): bool
{
    $normalizedHeader = array_map('recipeReportExportSmokeNormalizeHeader', $header);
    foreach ($expectedColumns as $expectedColumn) {
        if (!in_array(recipeReportExportSmokeNormalizeHeader($expectedColumn), $normalizedHeader, true)) {
            return false;
        }
    }

    return true;
}

function recipeReportExportSmokeNormalizeHeader($value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string) $value))) ?: '';
}

function recipeReportExportSmokeUnsafeCells(array $rows): array
{
    $unsafe = [];
    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $columnIndex => $value) {
            $text = (string) $value;
            if ($text === '' || $text[0] === "'") {
                continue;
            }

            $trimmed = ltrim($text, " \t\r\n");
            if ($trimmed === '') {
                continue;
            }

            $first = $trimmed[0];
            $isUnsafe = in_array($first, ['=', '+', '@'], true)
                || ($first === '-' && !is_numeric($trimmed))
                || in_array($text[0], ["\t", "\r", "\n"], true);
            if ($isUnsafe) {
                $unsafe[] = [
                    'row' => $rowIndex + 1,
                    'column' => $columnIndex + 1,
                    'prefix' => substr($text, 0, 12),
                ];
            }
        }
    }

    return $unsafe;
}

function recipeReportExportSmokeLoginDetected(string $body): bool
{
    return strpos($body, 'اسم المستخدم أو البريد أو الهاتف') !== false
        || strpos($body, 'name="uname"') !== false
        || strpos($body, 'login-card') !== false;
}

function recipeReportExportSmokeAccessDenied(string $body): bool
{
    return (bool) preg_match('/Access denied|Forbidden|غير مصرح|لا تملك صلاحية/u', $body);
}

function recipeReportExportSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipeReportExportSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe report export smoke: ' . (!empty($result['ok']) ? 'OK' : 'FAILED') . PHP_EOL);
    fwrite(STDOUT, '- base URL: ' . (string) ($result['base_url'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- exports checked: ' . count($result['exports'] ?? []) . PHP_EOL);

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
}
