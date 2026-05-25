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
    recipePosGridAvailabilitySurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipePosGridAvailabilitySurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$categoryId = isset($options['category-id']) ? max(0, (int) $options['category-id']) : 0;

$result = recipePosGridAvailabilitySurfaceSmoke($baseUrl, $cookie, $timeout, $categoryId);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipePosGridAvailabilitySurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipePosGridAvailabilitySurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_pos_grid_availability_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--category-id=7] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke for the POS grid recipe availability surface.\n");
    fwrite(STDOUT, "Use an already-authenticated cashier/operator session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, add items, apply migrations, change feature flags, write recipe rows, write stock, post accounting, request manager approvals, or enqueue sync.\n");
    fwrite(STDOUT, "It checks the unlocked POS page item-card availability data attributes, the POS JavaScript availability gates, and optional category JSON payload shape including cost masking. If the POS barcode gate is shown, it records that as an operator-unlock warning. It complements browser QA but does not click items, create orders, inspect JavaScript console logs, or capture screenshots.\n");
}

function recipePosGridAvailabilitySurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipePosGridAvailabilitySurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipePosGridAvailabilitySurfaceSmokeNormalizeCookieSource(string $source): string
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

function recipePosGridAvailabilitySurfaceSmoke(string $baseUrl, string $cookie, int $timeout, int $categoryId): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'checks' => [],
            'blockers' => ['recipe_pos_grid_availability_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_pos_grid_availability_surface_smoke_cookie_missing';
    }

    $checks = [
        recipePosGridAvailabilitySurfaceSmokePosPage($baseUrl, $cookie, $timeout),
        recipePosGridAvailabilitySurfaceSmokePosScript($baseUrl, $cookie, $timeout),
        recipePosGridAvailabilitySurfaceSmokeCategoryPayload($baseUrl, $cookie, $timeout, $categoryId),
    ];

    foreach ($checks as $check) {
        foreach (($check['blockers'] ?? []) as $blocker) {
            $blockers[] = 'recipe_pos_grid_availability_surface_' . $check['name'] . '_' . $blocker;
        }
        foreach (($check['warnings'] ?? []) as $warning) {
            $warnings[] = 'recipe_pos_grid_availability_surface_' . $check['name'] . '_' . $warning;
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
        'evidence_hint' => 'tools/recipe_pos_grid_availability_surface_smoke.php POS grid availability',
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipePosGridAvailabilitySurfaceSmokePosPage(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/pos_barcode.php';
    $fetch = recipePosGridAvailabilitySurfaceSmokeFetch($url, $cookie, $timeout, 'text/html,application/xhtml+xml');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipePosGridAvailabilitySurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $barcodeGateDetected = recipePosGridAvailabilitySurfaceSmokePosBarcodeGateDetected($body);
    $expectedSnippets = [
        'data-is-available',
        'data-availability-can-add',
        'data-availability-status',
        'data-unavailable-reason',
        'data-recipe-enabled',
        'data-recipe-effective-available-qty',
        'data-recipe-availability-revision',
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
            $blockers[] = 'expected_availability_card_attrs_missing';
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
        'login_detected' => recipePosGridAvailabilitySurfaceSmokeLoginDetected($body),
        'access_denied' => recipePosGridAvailabilitySurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipePosGridAvailabilitySurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => $warnings,
    ];
}

function recipePosGridAvailabilitySurfaceSmokePosScript(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/js/pos_barcode.js';
    $fetch = recipePosGridAvailabilitySurfaceSmokeFetch($url, $cookie, $timeout, 'application/javascript,text/plain,*/*');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipePosGridAvailabilitySurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    $expectedSnippets = [
        'itemAvailabilityContext',
        'showUnavailableItemMessage',
        'itemUnavailableMessage',
        'availability.canAdd',
        'data-recipe-effective-available-qty',
        'recipeQty',
        'recipeEnabled',
        'availabilityStatus',
        'unavailableReason',
    ];
    $missing = [];
    if ($blockers === []) {
        foreach ($expectedSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missing[] = $snippet;
            }
        }
        if ($missing !== []) {
            $blockers[] = 'expected_availability_js_missing';
        }
    }

    return [
        'name' => 'pos_js',
        'url' => $url,
        'status' => $status,
        'body_bytes' => strlen($body),
        'expected_snippets' => $expectedSnippets,
        'missing_snippets' => $missing,
        'fatal_or_sql_text' => recipePosGridAvailabilitySurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => [],
    ];
}

function recipePosGridAvailabilitySurfaceSmokeCategoryPayload(string $baseUrl, string $cookie, int $timeout, int $categoryId): array
{
    if ($categoryId < 1) {
        return [
            'name' => 'category_availability_payload',
            'url' => '',
            'status' => 0,
            'category_id' => 0,
            'json_success' => null,
            'items_count' => null,
            'availability_fields_seen' => false,
            'recipe_item_seen' => false,
            'low_or_unavailable_recipe_item_seen' => false,
            'missing_fields' => [],
            'sensitive_cost_keys_seen' => [],
            'login_detected' => false,
            'access_denied' => false,
            'fatal_or_sql_text' => false,
            'error' => '',
            'blockers' => [],
            'warnings' => ['category_id_not_provided_payload_shape_not_observed'],
        ];
    }

    $url = $baseUrl . '/ajax/get_category_items.php?category_id=' . rawurlencode((string) $categoryId);
    $fetch = recipePosGridAvailabilitySurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipePosGridAvailabilitySurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $payload = null;
    $itemsCount = null;
    $availabilityFieldsSeen = false;
    $recipeItemSeen = false;
    $lowOrUnavailableRecipeItemSeen = false;
    $missingFields = [];
    $sensitiveCostKeysSeen = [];
    $expectedFields = [
        'is_available',
        'availability_can_add',
        'availability_status',
        'unavailable_reason',
        'recipe_enabled',
        'recipe_effective_available_qty',
    ];
    $sensitiveCostKeys = [
        'cost',
        'cost_price',
        'costPrice',
        'unit_cost',
        'unitCost',
        'total_cost',
        'totalCost',
        'ingredient_cost_json',
        'ingredientCostJson',
        'internal_cost_per_sell_unit',
        'internalCostPerSellUnit',
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
                $warnings[] = 'category_items_empty_availability_shape_not_observed';
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
                foreach ($sensitiveCostKeys as $field) {
                    if (array_key_exists($field, $item)) {
                        $sensitiveCostKeysSeen[] = 'items[' . $index . '].' . $field;
                    }
                }
                if (array_key_exists('availability_status', $item) && array_key_exists('availability_can_add', $item)) {
                    $availabilityFieldsSeen = true;
                }
                if (!empty($item['recipe_enabled'])) {
                    $recipeItemSeen = true;
                }
                $status = (string) ($item['availability_status'] ?? '');
                if (in_array($status, ['recipe_low', 'recipe_unavailable'], true)) {
                    $lowOrUnavailableRecipeItemSeen = true;
                }
            }

            if ($missingFields !== []) {
                $blockers[] = 'availability_fields_missing';
            }
            if ($sensitiveCostKeysSeen !== []) {
                $blockers[] = 'sensitive_cost_keys_exposed';
            }
            if (!$recipeItemSeen) {
                $warnings[] = 'no_recipe_item_in_category';
            }
            if (!$lowOrUnavailableRecipeItemSeen) {
                $warnings[] = 'no_low_or_unavailable_recipe_item_in_category';
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
        'availability_fields_seen' => $availabilityFieldsSeen,
        'recipe_item_seen' => $recipeItemSeen,
        'low_or_unavailable_recipe_item_seen' => $lowOrUnavailableRecipeItemSeen,
        'expected_fields' => $expectedFields,
        'missing_fields' => $missingFields,
        'sensitive_cost_keys_seen' => $sensitiveCostKeysSeen,
        'login_detected' => recipePosGridAvailabilitySurfaceSmokeLoginDetected($body),
        'access_denied' => recipePosGridAvailabilitySurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipePosGridAvailabilitySurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipePosGridAvailabilitySurfaceSmokeCommonBlockers(array $fetch, string $body): array
{
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipePosGridAvailabilitySurfaceSmokeLoginDetected($body)) {
        $blockers[] = 'login_required';
    }
    if (recipePosGridAvailabilitySurfaceSmokeAccessDenied($body)) {
        $blockers[] = 'access_denied';
    }
    if (recipePosGridAvailabilitySurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return array_values(array_unique($blockers));
}

function recipePosGridAvailabilitySurfaceSmokeFetch(string $url, string $cookie, int $timeout, string $accept): array
{
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: POSMAIN recipe POS grid availability surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipePosGridAvailabilitySurfaceSmokeFetchCurl($url, $headers, $timeout);
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
        'status' => recipePosGridAvailabilitySurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'file_get_contents_failed',
    ];
}

function recipePosGridAvailabilitySurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
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

function recipePosGridAvailabilitySurfaceSmokeStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function recipePosGridAvailabilitySurfaceSmokeLoginDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="password"') !== false
        || strpos($lower, "name='password'") !== false
        || strpos($lower, 'type="password"') !== false
        || strpos($lower, "type='password'") !== false;
}

function recipePosGridAvailabilitySurfaceSmokePosBarcodeGateDetected(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'name="pos_barcode"') !== false
        || strpos($lower, "name='pos_barcode'") !== false
        || strpos($body, 'نظام POS محمي') !== false;
}

function recipePosGridAvailabilitySurfaceSmokeAccessDenied(string $body): bool
{
    $lower = strtolower($body);
    return strpos($lower, 'access denied') !== false
        || strpos($lower, 'permission denied') !== false
        || strpos($body, 'غير مصرح') !== false
        || strpos($body, 'ليس لديك صلاحية') !== false;
}

function recipePosGridAvailabilitySurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipePosGridAvailabilitySurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe POS grid availability surface smoke: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
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
