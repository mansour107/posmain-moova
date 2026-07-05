<?php

$pageManifest = require __DIR__ . '/../../config/rbac_page_manifest.php';
rbacPageAssert(is_array($pageManifest) && $pageManifest !== [], 'page manifest required');

$guardSnippets = [
    'page_guard(',
    'require_admin_or_permission(',
    'require_permission(',
    'deny_json_or_redirect(\'PERMISSION_DENIED\'',
    'posmain_inventory_dashboard_can_view',
    'includes/kds_access.php',
];

foreach ($pageManifest as $page => $entry) {
    $fullPath = __DIR__ . '/../../' . $page;
    rbacPageAssert(is_file($fullPath), 'page manifest file missing: ' . $page);
    $source = file_get_contents($fullPath);
    rbacPageAssert(is_string($source), 'unable to read ' . $page);

    $guarded = false;
    foreach ($guardSnippets as $snippet) {
        if (strpos($source, $snippet) !== false) {
            $guarded = true;
            break;
        }
    }
    rbacPageAssert($guarded, $page . ' must use page_guard or explicit permission enforcement');
}

echo 'rbac-page-coverage-ok count=' . count($pageManifest) . "\n";

function rbacPageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
