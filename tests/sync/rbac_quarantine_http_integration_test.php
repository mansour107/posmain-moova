<?php

$baseUrl = rtrim(
    (string) (getenv('POSMAIN_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8010'),
    '/'
);
$pageManifest = require __DIR__ . '/../../config/rbac_page_manifest.php';
$routeManifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
$targets = [];

foreach ($pageManifest as $path => $entry) {
    if (!empty($entry['quarantined'])) {
        $targets[] = $path;
    }
}
foreach ($routeManifest as $path => $entry) {
    if (!empty($entry['quarantined'])) {
        $targets[] = $path;
    }
}

if ($targets === []) {
    throw new RuntimeException('quarantine inventory must not be empty');
}

$failures = [];
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'timeout' => 5,
        'header' => "Accept: application/json, text/plain\r\n",
    ],
]);

foreach ($targets as $path) {
    $headers = [];
    $http_response_header = [];
    @file_get_contents($baseUrl . '/' . ltrim($path, '/'), false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $matches) === 1) {
        $status = (int) $matches[1];
    }
    if (!in_array($status, [404, 410], true)) {
        $failures[] = $path . '=' . $status;
    }
}

if ($failures !== []) {
    throw new RuntimeException(
        'quarantined HTTP entries must return 410: ' . implode(', ', $failures)
    );
}

echo 'rbac-quarantine-http-integration-ok count=' . count($targets) . "\n";

