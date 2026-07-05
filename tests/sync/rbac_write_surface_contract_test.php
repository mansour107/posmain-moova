<?php

$manifestPath = __DIR__ . '/../../config/rbac_route_manifest.php';
$manifest = require $manifestPath;
rbacManifestAssert(is_array($manifest) && $manifest !== [], 'manifest must be non-empty array');

$requiredSnippets = [
    'require_login',
    'require_permission',
    'require_pos_authenticated',
    'require_csrf',
    'rbac_guard_route',
    'require_admin_or_permission',
    'pos_api_dispatch',
    'auth_guard_is_pos_barcode_unlocked',
    'auth_guard_has_permission',
    'production_guard_deny_route',
];

foreach ($manifest as $relativePath => $entry) {
    $fullPath = __DIR__ . '/../../' . $relativePath;
    rbacManifestAssert(is_file($fullPath), 'manifest path missing file: ' . $relativePath);
    $source = file_get_contents($fullPath);
    rbacManifestAssert(is_string($source), 'unable to read ' . $relativePath);

    $guarded = false;
    foreach ($requiredSnippets as $snippet) {
        if (strpos($source, $snippet) !== false) {
            $guarded = true;
            break;
        }
    }
    rbacManifestAssert($guarded, $relativePath . ' must include rbac_guard_route or explicit auth_guard enforcement');
}

echo "rbac-write-surface-contract-ok count=" . count($manifest) . "\n";

function rbacManifestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
