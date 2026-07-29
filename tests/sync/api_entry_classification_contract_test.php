<?php

$root = realpath(__DIR__ . '/../..');
apiClassificationAssert($root !== false, 'repository root unavailable');
$manifest = require $root . '/config/rbac_route_manifest.php';
apiClassificationAssert(is_array($manifest), 'route manifest must be an array');

$internal = [
    'api/admin/updates/_bootstrap.php',
    'api/moova_menu_api_auth.php',
];
$public = [
    'api/health.php',
    'api/ready.php',
];
$session = [
    'api/pos/index.php',
];
$endpointMarkers = [
    'api/admin/updates/check.php' => ['_bootstrap.php', 'posmainUpdateRequireAdmin'],
    'api/admin/updates/start.php' => ['_bootstrap.php', 'posmainUpdateRequireAdmin'],
    'api/admin/updates/status.php' => ['_bootstrap.php', 'posmainUpdateRequireAdmin'],
    'api/categories.php' => ['moova_menu_api_auth.php', 'posmain_menu_api_require_access'],
    'api/items.php' => ['moova_menu_api_auth.php', 'posmain_menu_api_require_access'],
    'api/moova/ack_branch_events.php' => ['sync_route.php', 'CloudMoovaEventService'],
    'api/moova/branch_events.php' => ['sync_route.php', 'CloudMoovaEventService'],
    'api/sync/ack_branch_events.php' => ['sync_route.php', 'CloudBranchSyncEventService'],
    'api/sync/branch_events.php' => ['sync_route.php', 'CloudBranchSyncEventService'],
    'api/sync/export_branch_image.php' => ['sync_route.php', 'CloudBranchImageExportService'],
    'api/sync/export_branch_restore.php' => ['sync_route.php', 'CloudBranchRestoreEventService'],
    'api/sync/pairing_status.php' => ['sync_route.php', 'CloudAuthService'],
    'api/sync/receive_branch_events.php' => ['sync_route.php', 'CloudReceiveService'],
    'api/sync/receive_branch_image.php' => ['sync_route.php', 'CloudBranchImageReceiveService'],
    'api/sync/status.php' => ['api_entry_classification.php', 'status_token'],
];

$classified = array_merge($internal, $public, $session, array_keys($endpointMarkers));
$actual = apiClassificationPhpFiles($root . '/api', 'api');
sort($classified, SORT_STRING);
apiClassificationAssert(
    $classified === $actual,
    'API classification inventory mismatch: expected='
        . implode(',', $classified)
        . ' actual='
        . implode(',', $actual)
);

foreach ($internal as $path) {
    apiClassificationAssert(!empty($manifest[$path]['internal']), 'API helper must be internal: ' . $path);
    $source = (string) file_get_contents($root . '/' . $path);
    apiClassificationAssert(
        str_contains($source, 'api_entry_classification.php'),
        'internal API helper must deny direct HTTP execution: ' . $path
    );
}
foreach ($public as $path) {
    apiClassificationAssert(!empty($manifest[$path]['public']), 'public API missing declaration: ' . $path);
    $source = (string) file_get_contents($root . '/' . $path);
    apiClassificationAssert(
        str_contains($source, 'api_entry_classification.php'),
        'public API must invoke classification guard: ' . $path
    );
}
foreach ($session as $path) {
    apiClassificationAssert(
        ($manifest[$path]['permission'] ?? '') === 'pos.open',
        'session API must require pos.open: ' . $path
    );
    $source = (string) file_get_contents($root . '/' . $path);
    apiClassificationAssert(
        str_contains($source, 'session_bootstrap.php') && str_contains($source, 'connect.php'),
        'session API must execute central authentication and permission guards: ' . $path
    );
}
foreach ($endpointMarkers as $path => $markers) {
    apiClassificationAssert(
        !empty($manifest[$path]['endpoint_auth']),
        'endpoint-owned API auth declaration missing: ' . $path
    );
    $source = (string) file_get_contents($root . '/' . $path);
    foreach ($markers as $marker) {
        apiClassificationAssert(
            str_contains($source, $marker),
            'endpoint auth marker missing from ' . $path . ': ' . $marker
        );
    }
}

$guard = (string) file_get_contents($root . '/includes/entry_classification_guard.php');
foreach (["'api/'", "['endpoint_auth']", "['internal']"] as $marker) {
    apiClassificationAssert(str_contains($guard, $marker), 'early API guard marker missing: ' . $marker);
}
$syncRoute = (string) file_get_contents($root . '/includes/sync_route.php');
apiClassificationAssert(
    str_contains($syncRoute, 'api_entry_classification.php'),
    'shared sync API bootstrap must invoke entry classification'
);

echo "api-entry-classification-contract-ok\n";

/**
 * @return list<string>
 */
function apiClassificationPhpFiles(string $directory, string $prefix): array
{
    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($directory) + 1);
        $out[] = $prefix . '/' . str_replace('\\', '/', $relative);
    }
    sort($out, SORT_STRING);

    return $out;
}

function apiClassificationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
