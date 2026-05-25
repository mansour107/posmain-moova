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
    recipePaidReversalSurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipePaidReversalSurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));

$result = recipePaidReversalSurfaceSmoke($baseUrl, $cookie, $timeout);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipePaidReversalSurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipePaidReversalSurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_paid_reversal_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke for the paid refund/void POS surface.\n");
    fwrite(STDOUT, "Use an already-authenticated cashier/operator session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "It checks the POS page reversal controls when the cashier page is already unlocked, the recent-orders capability payload, and the refund endpoint method guard. If the POS barcode gate is shown, it records that as an operator-unlock warning. It complements browser QA but does not click buttons, confirm dialogs, issue mutations, inspect JavaScript console logs, or capture screenshots.\n");
}

function recipePaidReversalSurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipePaidReversalSurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipePaidReversalSurfaceSmokeNormalizeCookieSource(string $source): string
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

function recipePaidReversalSurfaceSmoke(string $baseUrl, string $cookie, int $timeout): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'checks' => [],
            'blockers' => ['recipe_paid_reversal_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_paid_reversal_surface_smoke_cookie_missing';
    }

    $posCheck = recipePaidReversalSurfaceSmokePosPage($baseUrl, $cookie, $timeout);
    $recentOrdersCheck = recipePaidReversalSurfaceSmokeRecentOrders($baseUrl, $cookie, $timeout);
    $methodGuardCheck = recipePaidReversalSurfaceSmokeMethodGuard($baseUrl, $cookie, $timeout);
    $checks = [$posCheck, $recentOrdersCheck, $methodGuardCheck];

    foreach ($checks as $check) {
        foreach (($check['blockers'] ?? []) as $blocker) {
            $blockers[] = 'recipe_paid_reversal_surface_' . $check['name'] . '_' . $blocker;
        }
        foreach (($check['warnings'] ?? []) as $warning) {
            $warnings[] = 'recipe_paid_reversal_surface_' . $check['name'] . '_' . $warning;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipePaidReversalSurfaceSmokePosPage(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/pos_barcode.php';
    $fetch = recipePaidReversalSurfaceSmokeFetch($url, $cookie, $timeout, 'text/html,application/xhtml+xml');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipePaidReversalSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $barcodeGateDetected = recipePaidReversalSurfaceSmokePosBarcodeGateDetected($body);
    $expectedSnippets = [
        'ajax/get_recent_orders.php',
        'ajax/refund_order.php',
        'reversePaidOrder',
        'refund_stock_policy',
        'paid-reversal-policy',
        'createPOSIdempotencyKey',
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
            $blockers[] = 'expected_reversal_ui_missing';
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
        'login_detected' => recipePaidReversalSurfaceSmokeLoginDetected($body),
        'access_denied' => recipePaidReversalSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipePaidReversalSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => $blockers,
        'warnings' => $warnings,
    ];
}

function recipePaidReversalSurfaceSmokeRecentOrders(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/ajax/get_recent_orders.php?_' . rawurlencode((string) time());
    $fetch = recipePaidReversalSurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipePaidReversalSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $payload = null;
    $ordersCount = null;
    $capabilityFieldsSeen = false;
    $paidReversibleOrderSeen = false;
    $missingCapabilityFields = [];

    if ($blockers === []) {
        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $blockers[] = 'invalid_json';
        } elseif (($payload['success'] ?? false) !== true || !array_key_exists('orders', $payload) || !is_array($payload['orders'])) {
            $blockers[] = 'unexpected_recent_orders_payload';
        } else {
            $ordersCount = count($payload['orders']);
            if ($ordersCount === 0) {
                $warnings[] = 'recent_orders_empty_capability_shape_not_observed';
            }

            foreach ($payload['orders'] as $index => $order) {
                if (!is_array($order)) {
                    $blockers[] = 'recent_order_not_object';
                    continue;
                }

                foreach (['can_refund', 'can_void', 'payment_status', 'order_status'] as $field) {
                    if (!array_key_exists($field, $order)) {
                        $missingCapabilityFields[] = 'orders[' . $index . '].' . $field;
                    }
                }

                if (array_key_exists('can_refund', $order) && array_key_exists('can_void', $order)) {
                    $capabilityFieldsSeen = true;
                }
                if (!empty($order['can_refund']) || !empty($order['can_void'])) {
                    $paidReversibleOrderSeen = true;
                }
            }

            if ($missingCapabilityFields !== []) {
                $blockers[] = 'capability_fields_missing';
            }
            if (!$paidReversibleOrderSeen) {
                $warnings[] = 'no_paid_reversible_order_in_recent_orders';
            }
        }
    }

    return [
        'name' => 'recent_orders_payload',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'json_success' => is_array($payload) ? ($payload['success'] ?? null) : null,
        'orders_count' => $ordersCount,
        'capability_fields_seen' => $capabilityFieldsSeen,
        'paid_reversible_order_seen' => $paidReversibleOrderSeen,
        'missing_capability_fields' => $missingCapabilityFields,
        'login_detected' => recipePaidReversalSurfaceSmokeLoginDetected($body),
        'access_denied' => recipePaidReversalSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipePaidReversalSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipePaidReversalSurfaceSmokeMethodGuard(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/ajax/refund_order.php';
    $fetch = recipePaidReversalSurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
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
    if (recipePaidReversalSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return [
        'name' => 'refund_endpoint_method_guard',
        'url' => $url,
        'status' => $status,
        'body_bytes' => strlen($body),
        'method_guarded' => $methodGuarded,
        'payload_code' => is_array($payload) ? ($payload['code'] ?? null) : null,
        'fatal_or_sql_text' => recipePaidReversalSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => [],
    ];
}

function recipePaidReversalSurfaceSmokeCommonBlockers(array $fetch, string $body): array
{
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipePaidReversalSurfaceSmokeLoginDetected($body)) {
        $blockers[] = 'login_required';
    }
    if (recipePaidReversalSurfaceSmokeAccessDenied($body)) {
        $blockers[] = 'access_denied';
    }
    if (recipePaidReversalSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return array_values(array_unique($blockers));
}

function recipePaidReversalSurfaceSmokeFetch(string $url, string $cookie, int $timeout, string $accept): array
{
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: POSMAIN recipe paid reversal surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipePaidReversalSurfaceSmokeFetchCurl($url, $headers, $timeout);
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
        'status' => recipePaidReversalSurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'file_get_contents_failed',
    ];
}

function recipePaidReversalSurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
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

function recipePaidReversalSurfaceSmokeStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function recipePaidReversalSurfaceSmokeLoginDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="password"') !== false
        || strpos($lower, "name='password'") !== false
        || strpos($lower, 'type="password"') !== false
        || strpos($lower, "type='password'") !== false;
}

function recipePaidReversalSurfaceSmokePosBarcodeGateDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="pos_barcode"') !== false
        || strpos($lower, "name='pos_barcode'") !== false
        || strpos($body, 'نظام POS محمي') !== false;
}

function recipePaidReversalSurfaceSmokeAccessDenied(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'access denied') !== false
        || strpos($lower, 'permission denied') !== false
        || strpos($body, 'غير مصرح') !== false
        || strpos($body, 'ليس لديك صلاحية') !== false;
}

function recipePaidReversalSurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipePaidReversalSurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe paid reversal surface smoke: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
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
