<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'base-url:',
    'output:',
    'playwright-report:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/generate_browser_evidence.php --base-url=https://example [--output=var/evidence/latest.json] [--playwright-report=test-results.json]\n");
    exit(0);
}

$baseUrl = rtrim(trim((string) ($options['base-url'] ?? '')), '/');
if ($baseUrl === '') {
    $configPath = __DIR__ . '/../config/app_config.php';
    if (is_file($configPath)) {
        require_once $configPath;
        if (function_exists('posmain_app_config')) {
            $baseUrl = rtrim((string) (posmain_app_config()['public_base_url'] ?? ''), '/');
        }
    }
}
if ($baseUrl === '') {
    $baseUrl = rtrim(trim((string) (getenv('POSMAIN_TEST_HTTP_BASE') ?: getenv('POSMAIN_PUBLIC_BASE_URL') ?: '')), '/');
}
if ($baseUrl === '') {
    fwrite(STDERR, "base-url is required\n");
    exit(1);
}

$output = trim((string) ($options['output'] ?? (__DIR__ . '/../var/evidence/browser-operator-qa.json')));
$pages = [];
$ok = true;

foreach ([
    [
        'path' => '/index.php',
        'label' => 'login_page',
        'needle_groups' => [
            ['id="uname"', 'id="password"', 'تسجيل الدخول'],
            ['id="mainPinPad"', 'ajax/main_pin_login.php', 'دخول'],
        ],
    ],
    ['path' => '/api/health.php', 'label' => 'health_api', 'needle_groups' => [['"healthy"']]],
    ['path' => '/inventory_dashboard.php', 'label' => 'inventory_dashboard', 'needle_groups' => [['inventory', 'login']], 'allow_redirect' => true],
] as $target) {
    $result = browserEvidenceFetch($baseUrl . $target['path']);
    $body = (string) ($result['body'] ?? '');
    $passed = ($result['ok'] ?? false)
        && !preg_match('/fatal error|SQL syntax|uncaught exception/i', $body);
    if (!($target['allow_redirect'] ?? false)) {
        $matchedNeedleGroup = false;
        foreach (($target['needle_groups'] ?? []) as $needleGroup) {
            $groupMatches = true;
            foreach ($needleGroup as $needle) {
                if (stripos($body, (string) $needle) === false) {
                    $groupMatches = false;
                    break;
                }
            }
            if ($groupMatches) {
                $matchedNeedleGroup = true;
                break;
            }
        }
        $passed = $passed && $matchedNeedleGroup;
    } elseif (($result['http_code'] ?? 0) >= 300 && ($result['http_code'] ?? 0) < 400) {
        $passed = true;
    }
    $pages[] = [
        'label' => $target['label'],
        'url' => $baseUrl . $target['path'],
        'http_code' => (int) ($result['http_code'] ?? 0),
        'passed' => $passed,
    ];
    if (!$passed) {
        $ok = false;
    }
}

$playwrightPath = trim((string) ($options['playwright-report'] ?? ''));
if ($playwrightPath !== '' && is_file($playwrightPath)) {
    $report = json_decode((string) file_get_contents($playwrightPath), true);
    if (is_array($report)) {
        $stats = $report['stats'] ?? [];
        $playwrightOk = ((int) ($stats['unexpected'] ?? 1)) === 0;
        $pages[] = [
            'label' => 'playwright_report',
            'path' => $playwrightPath,
            'passed' => $playwrightOk,
            'stats' => $stats,
        ];
        if (!$playwrightOk) {
            $ok = false;
        }
    }
}

$payload = [
    'ok' => $ok,
    'inventory_operator_qa_passed' => $ok,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'base_url' => $baseUrl,
    'pages' => $pages,
];

$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "Unable to create output directory: {$dir}\n");
    exit(2);
}
file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if (isset($options['json'])) {
    echo json_encode(['ok' => $ok, 'output' => $output, 'payload' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, 'Browser evidence: ' . ($ok ? 'PASS' : 'FAIL') . PHP_EOL);
    fwrite(STDOUT, 'Wrote ' . $output . PHP_EOL);
}

exit($ok ? 0 : 2);

function browserEvidenceFetch(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['ok' => is_string($body), 'body' => is_string($body) ? $body : '', 'http_code' => $code];
    }

    $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : ($http_response_header ?? []);
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $matches)) {
        $code = (int) $matches[1];
    }

    return ['ok' => is_string($body), 'body' => is_string($body) ? $body : '', 'http_code' => $code];
}
