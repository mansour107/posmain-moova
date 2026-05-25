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
    'recipe-id::',
    'json',
    'timeout::',
    'help',
]);

if (isset($options['help'])) {
    recipeManagementSurfaceSmokeUsage();
    exit(0);
}

$baseUrl = rtrim((string) ($options['base-url'] ?? getenv('POSMAIN_LOCAL_POS_URL') ?: 'http://127.0.0.1:8010'), '/');
$cookie = recipeManagementSurfaceSmokeCookie($options);
$timeout = max(1, (int) ($options['timeout'] ?? 10));
$recipeId = max(0, (int) ($options['recipe-id'] ?? 0));

$result = recipeManagementSurfaceSmoke($baseUrl, $cookie, $timeout, $recipeId);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    recipeManagementSurfaceSmokePrintHuman($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function recipeManagementSurfaceSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_management_surface_smoke.php [--base-url=http://127.0.0.1:8010] --cookie='PHPSESSID=...' [--cookie-file=/tmp/cookies.txt] [--recipe-id=123] [--json] [--timeout=10]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Runs a read-only authenticated GET smoke for the recipe management and modifier-substitution surface.\n");
    fwrite(STDOUT, "Use an already-authenticated operator session cookie for the target runtime; --cookie-file accepts a raw cookie header or a curl/Netscape cookie jar. This tool does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, or enqueue sync.\n");
    fwrite(STDOUT, "It checks recipe_manage.php, optional selected-recipe substitution controls, and the recipe lookup JSON endpoint. If --recipe-id is omitted and no substitution controls are rendered, it records a fixture-selection warning instead of pretending selected-recipe UI was inspected.\n");
}

function recipeManagementSurfaceSmokeCookie(array $options): string
{
    if (!empty($options['cookie-file'])) {
        $cookieFile = (string) $options['cookie-file'];
        if (!is_file($cookieFile) || !is_readable($cookieFile)) {
            throw new RuntimeException('Cookie file is not readable: ' . $cookieFile);
        }

        return recipeManagementSurfaceSmokeNormalizeCookieSource((string) file_get_contents($cookieFile));
    }

    return trim((string) ($options['cookie'] ?? ''));
}

function recipeManagementSurfaceSmokeNormalizeCookieSource(string $source): string
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

function recipeManagementSurfaceSmoke(string $baseUrl, string $cookie, int $timeout, int $recipeId): array
{
    if (!preg_match('#^https?://#i', $baseUrl)) {
        return [
            'ok' => false,
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'base_url' => $baseUrl,
            'read_only' => true,
            'requires_authenticated_session_cookie' => true,
            'recipe_id' => $recipeId,
            'checks' => [],
            'blockers' => ['recipe_management_surface_smoke_invalid_base_url'],
            'warnings' => [],
        ];
    }

    $blockers = [];
    $warnings = [];
    if ($cookie === '') {
        $warnings[] = 'recipe_management_surface_smoke_cookie_missing';
    }

    $managementCheck = recipeManagementSurfaceSmokeManagementPage($baseUrl, $cookie, $timeout, $recipeId);
    $lookupCheck = recipeManagementSurfaceSmokeLookupEndpoint($baseUrl, $cookie, $timeout);
    $checks = [$managementCheck, $lookupCheck];

    foreach ($checks as $check) {
        foreach (($check['blockers'] ?? []) as $blocker) {
            $blockers[] = 'recipe_management_surface_' . $check['name'] . '_' . $blocker;
        }
        foreach (($check['warnings'] ?? []) as $warning) {
            $warnings[] = 'recipe_management_surface_' . $check['name'] . '_' . $warning;
        }
    }

    $blockers = array_values(array_unique($blockers));

    return [
        'ok' => $blockers === [],
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'base_url' => $baseUrl,
        'read_only' => true,
        'requires_authenticated_session_cookie' => true,
        'recipe_id' => $recipeId,
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => array_values(array_diff(array_unique($warnings), $blockers)),
    ];
}

function recipeManagementSurfaceSmokeManagementPage(string $baseUrl, string $cookie, int $timeout, int $recipeId): array
{
    $path = 'recipe_manage.php' . ($recipeId > 0 ? '?recipe_id=' . rawurlencode((string) $recipeId) : '');
    $url = $baseUrl . '/' . $path;
    $fetch = recipeManagementSurfaceSmokeFetch($url, $cookie, $timeout, 'text/html,application/xhtml+xml');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeManagementSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];

    $expectedPageSnippets = [
        'Recipe Draft Management',
        'ajax/recipe_editor_lookup.php',
        'name="action" value="create_draft"',
        'Version History',
        'Cost And Availability Preview',
    ];
    $draftOnlyPageSnippets = [
        'Save Draft Header',
    ];
    $substitutionEditSnippets = [
        'Modifier Behavior',
        'Substitution Group',
        'name="modifier_behavior"',
        'name="substitution_group"',
    ];
    $substitutionReadSnippets = [
        'substitution_remove',
        'substitution_add',
    ];
    $missingPageSnippets = [];
    $missingDraftOnlyPageSnippets = [];
    $missingSubstitutionEditSnippets = [];
    $missingSubstitutionReadSnippets = [];

    if ($blockers === []) {
        foreach ($expectedPageSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingPageSnippets[] = $snippet;
            }
        }
        foreach ($draftOnlyPageSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingDraftOnlyPageSnippets[] = $snippet;
            }
        }
        foreach ($substitutionEditSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingSubstitutionEditSnippets[] = $snippet;
            }
        }
        foreach ($substitutionReadSnippets as $snippet) {
            if (strpos($body, $snippet) === false) {
                $missingSubstitutionReadSnippets[] = $snippet;
            }
        }

        if ($missingPageSnippets !== []) {
            $blockers[] = 'expected_management_ui_missing';
        }
        if ($missingDraftOnlyPageSnippets !== []) {
            $warnings[] = 'selected_recipe_not_editable_draft_controls_not_rendered';
        }
        if ($missingSubstitutionReadSnippets !== []) {
            $blockers[] = 'expected_substitution_rows_missing';
        }
        if ($missingSubstitutionEditSnippets !== []) {
            if ($recipeId > 0) {
                $warnings[] = 'selected_recipe_not_editable_substitution_edit_controls_not_rendered';
            } else {
                $warnings[] = 'recipe_id_missing_substitution_controls_not_rendered';
            }
        }
    }

    return [
        'name' => 'management_page',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'recipe_id' => $recipeId,
        'expected_page_snippets' => $expectedPageSnippets,
        'draft_only_page_snippets' => $draftOnlyPageSnippets,
        'expected_substitution_edit_snippets' => $substitutionEditSnippets,
        'expected_substitution_read_snippets' => $substitutionReadSnippets,
        'missing_page_snippets' => $missingPageSnippets,
        'missing_draft_only_page_snippets' => $missingDraftOnlyPageSnippets,
        'missing_substitution_edit_snippets' => $missingSubstitutionEditSnippets,
        'missing_substitution_read_snippets' => $missingSubstitutionReadSnippets,
        'substitution_snippets_checked' => $missingSubstitutionReadSnippets === [],
        'login_detected' => recipeManagementSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeManagementSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeManagementSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeManagementSurfaceSmokeLookupEndpoint(string $baseUrl, string $cookie, int $timeout): array
{
    $url = $baseUrl . '/ajax/recipe_editor_lookup.php?type=modifier_options&q=' . rawurlencode('milk') . '&limit=5';
    $fetch = recipeManagementSurfaceSmokeFetch($url, $cookie, $timeout, 'application/json,text/plain');
    $body = (string) ($fetch['body'] ?? '');
    $blockers = recipeManagementSurfaceSmokeCommonBlockers($fetch, $body);
    $warnings = [];
    $payload = null;
    $itemsCount = null;
    $sensitiveKeys = [];

    if ($blockers === []) {
        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $blockers[] = 'invalid_json';
        } elseif (($payload['success'] ?? false) !== true || !array_key_exists('items', $payload) || !is_array($payload['items'])) {
            $blockers[] = 'unexpected_lookup_payload';
        } else {
            $itemsCount = count($payload['items']);
            if ($itemsCount === 0) {
                $warnings[] = 'modifier_lookup_empty';
            }
            $sensitiveKeys = recipeManagementSurfaceSmokeSensitivePayloadKeys($payload);
            if ($sensitiveKeys !== []) {
                $blockers[] = 'sensitive_cost_keys_leaked';
            }
        }
    }

    return [
        'name' => 'modifier_lookup',
        'url' => $url,
        'status' => (int) ($fetch['status'] ?? 0),
        'body_bytes' => strlen($body),
        'json_success' => is_array($payload) ? ($payload['success'] ?? null) : null,
        'payload_type' => is_array($payload) ? ($payload['type'] ?? null) : null,
        'items_count' => $itemsCount,
        'sensitive_keys' => $sensitiveKeys,
        'login_detected' => recipeManagementSurfaceSmokeLoginDetected($body),
        'access_denied' => recipeManagementSurfaceSmokeAccessDenied($body),
        'fatal_or_sql_text' => recipeManagementSurfaceSmokeFatalText($body),
        'error' => (string) ($fetch['error'] ?? ''),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function recipeManagementSurfaceSmokeSensitivePayloadKeys(array $payload): array
{
    $sensitive = [];
    $stack = [$payload];
    while ($stack !== []) {
        $value = array_pop($stack);
        if (!is_array($value)) {
            continue;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && recipeManagementSurfaceSmokeSensitiveKey($key)) {
                $sensitive[$key] = $key;
            }
            if (is_array($child)) {
                $stack[] = $child;
            }
        }
    }

    return array_values($sensitive);
}

function recipeManagementSurfaceSmokeSensitiveKey(string $key): bool
{
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);

    return in_array($normalized, [
        'cost',
        'costprice',
        'unitcost',
        'totalcost',
        'ingredientcost',
        'ingredientcostjson',
        'internalcostpersellunit',
        'margin',
        'profit',
    ], true);
}

function recipeManagementSurfaceSmokeCommonBlockers(array $fetch, string $body): array
{
    $blockers = [];
    $status = (int) ($fetch['status'] ?? 0);
    if ((string) ($fetch['error'] ?? '') !== '') {
        $blockers[] = 'http_fetch_failed';
    }
    if ($status >= 400 || $status < 200) {
        $blockers[] = 'http_status_' . $status;
    }
    if (recipeManagementSurfaceSmokeLoginDetected($body)) {
        $blockers[] = 'login_required';
    }
    if (recipeManagementSurfaceSmokeAccessDenied($body)) {
        $blockers[] = 'access_denied';
    }
    if (recipeManagementSurfaceSmokeFatalText($body)) {
        $blockers[] = 'fatal_or_sql_text';
    }

    return array_values(array_unique($blockers));
}

function recipeManagementSurfaceSmokeFetch(string $url, string $cookie, int $timeout, string $accept): array
{
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: POSMAIN recipe management surface smoke',
    ];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    if (function_exists('curl_init')) {
        return recipeManagementSurfaceSmokeFetchCurl($url, $headers, $timeout);
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
        'status' => recipeManagementSurfaceSmokeStatus($responseHeaders),
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : 'file_get_contents_failed',
    ];
}

function recipeManagementSurfaceSmokeFetchCurl(string $url, array $headers, int $timeout): array
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

function recipeManagementSurfaceSmokeStatus(array $headers): int
{
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function recipeManagementSurfaceSmokeLoginDetected(string $body): bool
{
    $lower = strtolower($body);

    return strpos($lower, 'name="password"') !== false
        || strpos($lower, "name='password'") !== false
        || strpos($lower, 'type="password"') !== false
        || strpos($lower, "type='password'") !== false;
}

function recipeManagementSurfaceSmokeAccessDenied(string $body): bool
{
    $lower = strtolower($body);

    return strpos($lower, 'access denied') !== false
        || strpos($lower, 'permission denied') !== false
        || strpos($body, 'غير مصرح') !== false
        || strpos($body, 'ليس لديك صلاحية') !== false;
}

function recipeManagementSurfaceSmokeFatalText(string $body): bool
{
    return recipeSurfaceSmokeFatalText($body);
}

function recipeManagementSurfaceSmokePrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe management surface smoke: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
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
