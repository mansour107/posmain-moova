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
    'category-id::',
    'json',
    'timeout::',
    'help',
]);

if (isset($options['help'])) {
    recipeManagerOverrideSurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipeManagerOverrideSurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$categoryId = isset($options['category-id']) ? max(0, (int) $options['category-id']) : 0;

$result = recipeManagerOverrideSurfaceSmoke($baseUrl, $cookie, $timeout, $categoryId);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipeManagerOverrideSurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeManagerOverrideSurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_manager_override_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--category-id=7] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke for the manager recipe stock override POS surface.\n");
    fwrite(STDOUT, "Use an already-authenticated cashier/operator session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, request approvals, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "It checks the POS page override capability bootstrap when the cashier page is already unlocked, the POS JavaScript override flow, optional category availability payload shape, and the manager approval endpoint method guard. If the POS barcode gate is shown, it records that as an operator-unlock warning. It complements browser QA but does not click buttons, approve prompts, issue mutations, inspect JavaScript console logs, or capture screenshots.\n");
}

function recipeManagerOverrideSurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipeManagerOverrideSurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipeManagerOverrideSurfaceSmokeNormalizeCookieSource(string $source): string
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

function recipeManagerOverrideSurfaceSmoke(string $baseUrl, string $cookie, int $timeout, int $categoryId): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'checks' => [],
            'blockers' => ['recipe_manager_override_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_manager_override_surface_smoke_cookie_missing';
    }

    $checks = [
        recipeManagerOverrideSurfaceSmokePosPage($baseUrl, $cookie, $timeout),
        recipeManagerOverrideSurfaceSmokePosScript($baseUrl, $cookie, $timeout),
        recipeManagerOverrideSurfaceSmokeAvailabilityPayload($baseUrl, $cookie, $timeout, $categoryId),
        recipeManagerOverrideSurfaceSmokeMethodGuard($baseUrl, $cookie, $timeout),
    ];

    foreach ($checks as $check) {
        foreach (($check['blockers'] ?? []) as $blocker) {
            $blockers[] = 'recipe_manager_override_surface_' . $check['name'] . '_' . $blocker;
        }
        foreach (($check['warnings'] ?? []) as $warning) {
            $warnings[] = 'recipe_manager_override_surface_' . $check['name'] . '_' . $warning;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'category_id' => $categoryId,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'evidence_hint' => 'tools/recipe_manager_override_surface_smoke.php manager override ajax/manager_approval.php',
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipeManagerOverrideSurfaceSmokePosPage(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/pos_barcode.php';
    $fetch = recipeManagerOverrideSurfaceSmokeFetch($url, $cookie, $timeout, 'text/html,application/xhtml+xml');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeManagerOverrideSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $barcodeGateDetected = recipeManagerOverrideSurfaceSmokePosBarcodeGateDetected($body);
    $expectedSnippets = [
        'POSMAIN_CAN_RECIPE_STOCK_OVERRIDE',
        'posmain-csrf-token',
        'POSMAIN_ATTACH_CSRF_HEADER',
    ];
    $missing = [];
    if ($barcodeGateDetected && $blockers === []) {
        $warnings[] = 'pos_barcode_gate_requires_operator_unlock';
    } elseif ($blockers === []) {
        foreach ($expectedSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missing[] = $snippet;
            }
        }
        if ($missing !== []) {
            $blockers[] = 'expected_override_bootstrap_missing';
        }
    }

    return [
        'name' => 'pos_page',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'expected_snippets' => $expectedSnippets,
        'ui_snippets_checked' => !$barcodeGateDetected && $blockers === [],
        'pos_barcode_gate_detected' => $barcodeGateDetected,
        'missing_snippets' => $missing,
        'login_detected' => recipeManagerOverrideSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeManagerOverrideSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeManagerOverrideSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => $warnings,
    ];
}

function recipeManagerOverrideSurfaceSmokePosScript(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/js/pos_barcode.js';
    $fetch = recipeManagerOverrideSurfaceSmokeFetch($url, $cookie, $timeout, 'application/javascript,text/plain,*/*');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipeManagerOverrideSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    $expectedSnippets = [
        'requestRecipeStockOverride',
        'window.POSMAIN_CAN_RECIPE_STOCK_OVERRIDE',
        'ajax/manager_approval.php',
        'approve_recipe_stock_override',
        'data-requires-manager-override',
        'data-override-allowed',
        'data-override-permission',
        'itmmanagerapproval[]',
        'managerApprovalId',
        'POSMAIN_ATTACH_CSRF_HEADER',
    ];
    $missing = [];
    if ($blockers === []) {
        foreach ($expectedSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missing[] = $snippet;
            }
        }
        if ($missing !== []) {
            $blockers[] = 'expected_override_js_missing';
        }
    }

    return [
        'name' => 'pos_js',
        'url' => $url,
        'status' => $status,
        'body_bytes' => strlen($body),
        'expected_snippets' => $expectedSnippets,
        'missing_snippets' => $missing,
        'fatal_or_sql_text' => recipeManagerOverrideSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => [],
    ];
}

function recipeManagerOverrideSurfaceSmokeAvailabilityPayload(string $baseUrl, string $cookie, int $timeout, int $categoryId): array
{
    if ($categoryId < 1) {
        return [
            'name' => 'category_availability_payload',
            'url' => '',
            'status' => 0,
            'category_id' => 0,
            'json_success' => null,
            'items_count' => null,
            'override_fields_seen' => false,
            'manager_override_item_seen' => false,
            'missing_fields' => [],
            'login_detected' => false,
            'access_denied' => false,
            'fatal_or_sql_text' => false,
            'error' => '',
            'blockers' => [],
            'warnings' => ['category_id_not_provided_payload_shape_not_observed'],
        ];
    }

    $url = $baseUrl . '/ajax/get_category_items.php?category_id=' . rawurlencode((string) $categoryId);
    $fetch = recipeManagerOverrideSurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeManagerOverrideSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $payload = null;
    $itemsCount = null;
    $overrideFieldsSeen = false;
    $managerOverrideItemSeen = false;
    $missingFields = [];
    $expectedFields = [
        'availability_can_add',
        'availability_requires_manager_override',
        'availability_override_permission',
        'unavailable_reason',
    ];

    if ($blockers === []) {
        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $blockers[] = 'invalid_json';
        } elseif (($payload['success'] ?? false) !== true || !array_key_exists('items', $payload) || !is_array($payload['items'])) {
            $warnings[] = 'category_has_no_items_or_unexpected_payload';
        } else {
            $itemsCount = count($payload['items']);
            if ($itemsCount === 0) {
                $warnings[] = 'category_items_empty_override_shape_not_observed';
            }

            foreach ($payload['items'] as $index => $item) {
                if (!is_array($item)) {
                    $blockers[] = 'category_item_not_object';
                    continue;
                }

                foreach ($expectedFields as $field) {
                    if (!array_key_exists($field, $item)) {
                        $missingFields[] = 'items[' . $index . '].' . $field;
                    }
                }
                if (array_key_exists('availability_requires_manager_override', $item)
                    && array_key_exists('availability_override_permission', $item)
                ) {
                    $overrideFieldsSeen = true;
                }
                if (!empty($item['availability_requires_manager_override'])) {
                    $managerOverrideItemSeen = true;
                }
            }

            if ($missingFields !== []) {
                $blockers[] = 'override_availability_fields_missing';
            }
            if (!$managerOverrideItemSeen) {
                $warnings[] = 'no_manager_override_item_in_category';
            }
        }
    }

    return [
        'name' => 'category_availability_payload',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'category_id' => $categoryId,
        'body_bytes' => strlen($body),
        'json_success' => is_array($payload) ? ($payload['success'] ?? null) : null,
        'items_count' => $itemsCount,
        'override_fields_seen' => $overrideFieldsSeen,
        'manager_override_item_seen' => $managerOverrideItemSeen,
        'expected_fields' => $expectedFields,
        'missing_fields' => $missingFields,
        'login_detected' => recipeManagerOverrideSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeManagerOverrideSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeManagerOverrideSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeManagerOverrideSurfaceSmokeMethodGuard(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/ajax/manager_approval.php';
    $fetch = recipeManagerOverrideSurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    $payload = json_decode($body, true);
    $methodGuarded = is_array($payload)
        && ($payload['success'] ?? true) === false
        && (string) ($payload['code'] ?? '') === 'METHOD_NOT_ALLOWED';

    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status !== 405) {
        $blockers[] = 'method_guard_status_missing';
    }
    if (!$methodGuarded) {
        $blockers[] = 'method_guard_payload_missing';
    }
    if (recipeManagerOverrideSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return [
        'name' => 'manager_approval_method_guard',
        'url' => $url,
        'status' => $status,
        'body_bytes' => strlen($body),
        'method_guarded' => $methodGuarded,
        'payload_code' => is_array($payload) ? ($payload['code'] ?? null) : null,
        'fatal_or_sql_text' => recipeManagerOverrideSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => [],
    ];
}

function recipeManagerOverrideSurfaceSmokeCommonBlockers(array $fetch, string $body): array
{
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipeManagerOverrideSurfaceSmokeLoginDetected($body)) {
        $blockers[] = 'login_required';
    }
    if (recipeManagerOverrideSurfaceSmokeAccessDenied($body)) {
        $blockers[] = 'access_denied';
    }
    if (recipeManagerOverrideSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return array_values(array_unique($blockers));
}

function recipeManagerOverrideSurfaceSmokeFetch(string $url, string $cookie, int $timeout, string $accept): array
{
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: POSMAIN recipe manager override surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipeManagerOverrideSurfaceSmokeFetchCurl($url, $headers, $timeout);
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
        'status' => recipeManagerOverrideSurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'file_get_contents_failed',
    ];
}

function recipeManagerOverrideSurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
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

function recipeManagerOverrideSurfaceSmokeStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function recipeManagerOverrideSurfaceSmokeLoginDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="password"') !== false
        || strpos($lower, "name='password'") !== false
        || strpos($lower, 'type="password"') !== false
        || strpos($lower, "type='password'") !== false;
}

function recipeManagerOverrideSurfaceSmokePosBarcodeGateDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="pos_barcode"') !== false
        || strpos($lower, "name='pos_barcode'") !== false
        || strpos($body, 'نظام POS محمي') !== false;
}

function recipeManagerOverrideSurfaceSmokeAccessDenied(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'access denied') !== false
        || strpos($lower, 'permission denied') !== false
        || strpos($body, 'غير مصرح') !== false
        || strpos($body, 'ليس لديك صلاحية') !== false;
}

function recipeManagerOverrideSurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipeManagerOverrideSurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe manager override surface smoke: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
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
