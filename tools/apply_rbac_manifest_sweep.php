#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applies rbac_route_manifest entries and rbac_guard_route() to all do/ajax write surfaces
 * discovered in write_surface_audit_latest.json that are not yet in the manifest.
 */
$root = dirname(__DIR__);
$auditPath = $root . '/docs/production/write_surface_audit_latest.json';
$manifestPath = $root . '/config/rbac_route_manifest.php';

$audit = json_decode((string) file_get_contents($auditPath), true);
$manifest = require $manifestPath;

$categoryDefaults = [
    'user_admin' => ['permission' => 'users.manage', 'csrf' => 'users_write', 'lane' => 'erp', 'admin_or' => true],
    'menu_catalog' => ['permission' => 'menu.edit', 'csrf' => 'menu_write', 'lane' => 'erp'],
    'pos_order' => ['permission' => 'pos.sell.takeaway', 'csrf' => 'pos_browser', 'lane' => 'pos'],
    'shift_session' => ['permission' => 'pos.shift.close', 'csrf' => 'shift_close', 'lane' => 'pos'],
    'table_state' => ['permission' => 'pos.table.open', 'csrf' => 'pos_browser', 'lane' => 'pos'],
    'inventory_stock' => ['permission' => 'inventory.edit', 'csrf' => 'inventory_adjustment', 'lane' => 'erp'],
    'payments/accounting' => ['permission' => 'accounting.view', 'csrf' => 'accounting_write', 'lane' => 'erp'],
    'moova_bridge' => ['permission' => 'moova.manage', 'csrf' => 'moova_order', 'lane' => 'erp'],
    'other_business_write' => ['permission' => 'reports.view', 'csrf' => 'hr_write', 'lane' => 'erp'],
];

$pathOverrides = [
    'do/do_logout.php' => ['permission' => '', 'csrf' => '', 'lane' => 'erp'],
    'do/dotest.php' => ['permission' => 'system.tools.run', 'csrf' => 'default', 'lane' => 'erp', 'admin_or' => true],
    'ajax/pulse_ajax.php' => ['permission' => 'reports.view', 'csrf' => 'hr_write', 'lane' => 'erp'],
    'ajax/update_customer_visit_end_time.php' => ['permission' => 'customers.manage', 'csrf' => 'customers_manage', 'lane' => 'erp', 'admin_or' => true],
    'do/doadd_client.php' => ['permission' => 'accounting.view', 'csrf' => 'accounting_write', 'lane' => 'erp'],
    'do/doedit_client.php' => ['permission' => 'accounting.view', 'csrf' => 'accounting_write', 'lane' => 'erp'],
    'do/dodel_client.php' => ['permission' => 'accounting.view', 'csrf' => 'accounting_write', 'lane' => 'erp'],
    'do/doadd_printer.php' => ['permission' => 'system.tools.run', 'csrf' => 'settings_write', 'lane' => 'erp', 'admin_or' => true],
    'do/doedit_printer.php' => ['permission' => 'system.tools.run', 'csrf' => 'settings_write', 'lane' => 'erp', 'admin_or' => true],
    'do/importdata.php' => ['permission' => 'system.tools.run', 'csrf' => 'default', 'lane' => 'erp', 'admin_or' => true],
    'do/doimportfp.php' => ['permission' => 'reports.view', 'csrf' => 'hr_write', 'lane' => 'erp'],
];

$guardSnippets = [
    'rbac_guard_route',
    'require_permission',
    'require_admin_or_permission',
    'require_pos_authenticated',
    'pos_api_dispatch',
];

function resolveEntry(array $surface, array $categoryDefaults, array $pathOverrides): ?array
{
    $path = (string) ($surface['path'] ?? '');
    if (isset($pathOverrides[$path])) {
        return $pathOverrides[$path];
    }

    $categories = $surface['categories'] ?? [];
    foreach ($categoryDefaults as $category => $entry) {
        if (in_array($category, $categories, true)) {
            return $entry;
        }
    }

    return $categoryDefaults['other_business_write'];
}

function isGuardedSource(string $source): bool
{
    global $guardSnippets;
    foreach ($guardSnippets as $snippet) {
        if (strpos($source, $snippet) !== false) {
            return true;
        }
    }

    return false;
}

function patchHandler(string $fullPath, string $relativePath): bool
{
    if (!is_file($fullPath)) {
        echo "MISSING FILE {$relativePath}\n";
        return false;
    }

    $source = (string) file_get_contents($fullPath);
    if (isGuardedSource($source)) {
        return false;
    }

    if ($relativePath === 'do/dotest.php') {
        $guard = "<?php\nrequire_once __DIR__ . '/../includes/production_guard.php';\nproduction_guard_deny_route('do/dotest.php');\n";
    } else {
        $guard = "<?php\nrequire_once __DIR__ . '/../includes/rbac_route_guard.php';\nrbac_guard_route('{$relativePath}');\n\n";
    }

    $body = preg_replace('/^<\?php\s*/', '', $source, 1);
    $body = preg_replace('/^require_once\s+__DIR__\s*\.\s*[\'"]\/\.\.\/includes\/session_bootstrap\.php[\'"];\s*/m', '', $body, 1);
    $body = preg_replace('/^include(?:_once)?\s*\(?[\'"]\.\.\/includes\/connect\.php[\'"]\)?;\s*/m', '', $body, 1);
    $body = preg_replace('/^include(?:_once)?\s*[\'"]\.\.\/includes\/connect\.php[\'"];\s*/m', '', $body, 1);
    $body = preg_replace('/^include(?:_once)?\s*[\'"]\.\.\/\.\.\/includes\/connect\.php[\'"];\s*/m', '', $body, 1);

    file_put_contents($fullPath, $guard . ltrim($body));
    return true;
}

$added = 0;
$patched = 0;

foreach ($audit['surfaces'] as $surface) {
    $path = (string) ($surface['path'] ?? '');
    if ($path === '' || strpos($path, 'classes/') === 0) {
        continue;
    }
    if (!preg_match('#^(do/|ajax/)[^/]+\.php$#', $path)) {
        continue;
    }
    if (isset($manifest[$path])) {
        continue;
    }

    $entry = resolveEntry($surface, $categoryDefaults, $pathOverrides);
    if ($entry === null) {
        continue;
    }

    $manifest[$path] = $entry;
    $added++;

    if (patchHandler($root . '/' . $path, $path)) {
        $patched++;
        echo "PATCHED {$path}\n";
    }
}

ksort($manifest);

$lines = ["<?php\n", "\nreturn [\n"];
$currentSection = '';
foreach ($manifest as $path => $entry) {
    $section = explode('/', $path)[0] . '/' . (explode('/', $path)[1] ?? '');
    $parts = explode('_', basename($path, '.php'));
    $label = $parts[0] ?? 'misc';

    $entryParts = [];
    foreach ($entry as $key => $value) {
        if (is_bool($value)) {
            $entryParts[] = "'{$key}' => " . ($value ? 'true' : 'false');
        } else {
            $entryParts[] = "'{$key}' => '" . addslashes((string) $value) . "'";
        }
    }
    $lines[] = "    '{$path}' => [" . implode(', ', $entryParts) . "],\n";
}
$lines[] = "];\n";

file_put_contents($manifestPath, implode('', $lines));

echo "\nManifest entries added: {$added}\n";
echo "Files patched: {$patched}\n";
echo "Total manifest entries: " . count($manifest) . "\n";
