<?php

$pageManifest = require __DIR__ . '/../../config/rbac_page_manifest.php';
rbacPageAssert(is_array($pageManifest) && $pageManifest !== [], 'page manifest required');

$sessionBootstrap = file_get_contents(__DIR__ . '/../../includes/session_bootstrap.php');
$connectBootstrap = file_get_contents(__DIR__ . '/../../includes/connect.php');
rbacPageAssert(
    is_string($sessionBootstrap)
        && strpos($sessionBootstrap, 'posmain_enforce_entry_classification') !== false,
    'session bootstrap must enforce entry classification'
);
rbacPageAssert(
    is_string($connectBootstrap)
        && strpos($connectBootstrap, 'posmain_enforce_entry_permission') !== false,
    'connect bootstrap must enforce page permissions'
);

$guardSnippets = [
    'page_guard(',
    'page_guard_from_manifest(',
    'require_admin_or_permission(',
    'require_permission(',
    "require_permission('pos.open')",
    'deny_json_or_redirect(\'PERMISSION_DENIED\'',
    'posmain_inventory_dashboard_can_view',
    'includes/kds_access.php',
    // Pages that intentionally delegate enforcement to a shared bootstrap include.
    "include('includes/pos_simple_header.php')",
    'include("includes/pos_simple_header.php")',
    'includes/header.php',
    'includes/connect.php',
    'includes/session_bootstrap.php',
    'http_response_code(410)',
    'ENDPOINT_QUARANTINED',
];

foreach ($pageManifest as $page => $entry) {
    $fullPath = __DIR__ . '/../../' . $page;
    rbacPageAssert(is_file($fullPath), 'page manifest file missing: ' . $page);
    $source = file_get_contents($fullPath);
    rbacPageAssert(is_string($source), 'unable to read ' . $page);
    if (trim($source) === '') {
        continue;
    }

    $guardPosition = null;
    foreach ($guardSnippets as $snippet) {
        $position = strpos($source, $snippet);
        if ($position !== false && ($guardPosition === null || $position < $guardPosition)) {
            $guardPosition = $position;
        }
    }
    $firstOutputPosition = rbacPageFirstOutputPosition($source);
    $guardedBeforeOutput = $guardPosition !== null
        && ($firstOutputPosition === null || $guardPosition < $firstOutputPosition);
    rbacPageAssert(
        $guardedBeforeOutput,
        $page . ' must enforce its shared/explicit guard before producing output'
    );
}

echo 'rbac-page-coverage-ok count=' . count($pageManifest) . "\n";

function rbacPageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rbacPageFirstOutputPosition(string $source): ?int
{
    $offset = 0;
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            [$type, $text] = $token;
            if ($type === T_ECHO || $type === T_PRINT || ($type === T_INLINE_HTML && $text !== '')) {
                return $offset;
            }
            $offset += strlen($text);
            continue;
        }
        $offset += strlen($token);
    }

    return null;
}
