<?php

$manifestPath = __DIR__ . '/../../config/rbac_route_manifest.php';
$manifest = require $manifestPath;
rbacManifestAssert(is_array($manifest) && $manifest !== [], 'manifest must be non-empty array');

$sessionBootstrap = file_get_contents(__DIR__ . '/../../includes/session_bootstrap.php');
$connectBootstrap = file_get_contents(__DIR__ . '/../../includes/connect.php');
rbacManifestAssert(
    is_string($sessionBootstrap)
        && strpos($sessionBootstrap, 'entry_classification_guard.php') !== false
        && strpos($sessionBootstrap, 'posmain_enforce_entry_classification') !== false,
    'session bootstrap must enforce fail-closed entry classification'
);
rbacManifestAssert(
    is_string($connectBootstrap)
        && strpos($connectBootstrap, 'entry_permission_guard.php') !== false
        && strpos($connectBootstrap, 'posmain_enforce_entry_permission') !== false,
    'database bootstrap must enforce manifest permission and CSRF policy'
);

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

$goneSnippets = [
    'http_response_code(410)',
    'LEGACY_WAITER_AUTH_DISABLED',
    'ENDPOINT_QUARANTINED',
    'InventoryRetiredLegacyEndpoint',
];

foreach ($manifest as $relativePath => $entry) {
    $fullPath = __DIR__ . '/../../' . $relativePath;
    rbacManifestAssert(is_file($fullPath), 'manifest path missing file: ' . $relativePath);
    $source = file_get_contents($fullPath);
    rbacManifestAssert(is_string($source), 'unable to read ' . $relativePath);
    $usesSharedGate = strpos($source, 'session_bootstrap.php') !== false
        || strpos($source, 'includes/connect.php') !== false
        || strpos($source, "../includes/connect.php") !== false
        || strpos($source, 'api_entry_classification.php') !== false
        || strpos($source, 'sync_route.php') !== false
        || strpos($source, 'moova_menu_api_auth.php') !== false
        || strpos($source, '/_bootstrap.php') !== false;

    // Quarantined / retired endpoints are intentionally gone (HTTP 410), not live writers.
    $isGone = !empty($entry['quarantined']);
    if (!$isGone) {
        foreach ($goneSnippets as $snippet) {
            if (strpos($source, $snippet) !== false) {
                $isGone = true;
                break;
            }
        }
    }
    if ($isGone) {
        $hasGoneSignal = false;
        foreach ($goneSnippets as $snippet) {
            if (strpos($source, $snippet) !== false) {
                $hasGoneSignal = true;
                break;
            }
        }
        rbacManifestAssert(
            $hasGoneSignal || $usesSharedGate,
            $relativePath . ' is quarantined/retired and must return HTTP 410 (not a live write handler)'
        );
        continue;
    }

    $guarded = false;
    foreach ($requiredSnippets as $snippet) {
        if (strpos($source, $snippet) !== false) {
            $guarded = true;
            break;
        }
    }
    rbacManifestAssert(
        $guarded || $usesSharedGate,
        $relativePath . ' must include the shared entry gate, rbac_guard_route, or explicit auth_guard enforcement'
    );
}

echo "rbac-write-surface-contract-ok count=" . count($manifest) . "\n";

function rbacManifestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
