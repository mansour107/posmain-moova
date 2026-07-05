#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = require $root . '/config/rbac_page_manifest.php';

$guardSnippets = [
    'page_guard(',
    'require_admin_or_permission(',
    'require_permission(',
];

function isPageGuarded(string $source): bool
{
    global $guardSnippets;
    foreach ($guardSnippets as $snippet) {
        if (strpos($source, $snippet) !== false) {
            return true;
        }
    }

    return false;
}

function buildGuardBlock(?string $permission, bool $adminOr): string
{
    $permArg = $permission === null ? 'null' : "'" . addslashes($permission) . "'";
    $adminArg = $adminOr ? ', $conn, true' : ', $conn';

    return "<?php\n"
        . "require_once __DIR__ . '/includes/auth_guard.php';\n"
        . "include __DIR__ . '/includes/connect.php';\n"
        . "require_once __DIR__ . '/includes/page_guard.php';\n"
        . "page_guard({$permArg}{$adminArg});\n"
        . "?>\n";
}

function injectPageGuard(string $source, ?string $permission, bool $adminOr): string
{
    if (preg_match('/include\s*\(?[\'"]includes\/connect\.php[\'"]\)?;/', $source, $match, PREG_OFFSET_CAPTURE)) {
        $pos = $match[0][1] + strlen($match[0][0]);
        $guardBlock = "require_once __DIR__ . '/includes/page_guard.php';\n";
        $permArg = $permission === null ? 'null' : "'" . addslashes($permission) . "'";
        $adminArg = $adminOr ? ', $conn, true' : ', $conn';
        $guardBlock .= "page_guard({$permArg}{$adminArg});\n";
        return substr($source, 0, $pos) . "\n" . $guardBlock . substr($source, $pos);
    }

    if (preg_match('/include\s*\(?[\'"]includes\/header\.php[\'"]\)?/', $source, $match, PREG_OFFSET_CAPTURE)) {
        $pos = $match[0][1];
        $prefix = substr($source, 0, $pos);
        $suffix = substr($source, $pos);
        $prefix = preg_replace('/^<\?php\s*/', '', $prefix, 1);
        return buildGuardBlock($permission, $adminOr) . ltrim($prefix) . $suffix;
    }

    return $source;
}

$patched = 0;
$skipped = 0;

foreach ($manifest as $page => $entry) {
    $path = $root . '/' . $page;
    if (!is_file($path)) {
        continue;
    }

    $source = (string) file_get_contents($path);
    if (isPageGuarded($source)) {
        $skipped++;
        continue;
    }

    $permission = $entry['permission'] ?? null;
    $adminOr = !empty($entry['admin_or']);
    $newSource = injectPageGuard($source, $permission, $adminOr);
    if ($newSource === $source) {
        continue;
    }

    file_put_contents($path, $newSource);
    $patched++;
    echo "PATCHED {$page}\n";
}

echo "\nPatched: {$patched}, skipped (already guarded): {$skipped}\n";
