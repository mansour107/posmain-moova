<?php

/**
 * Filesystem-derived RBAC manifest completeness.
 *
 * Scans production PHP entry points under project root, ajax/, do/, and print/.
 * Every file must appear in page manifest, route manifest, or a tiny explicit
 * allowlist of true non-entry internals. Gaps are reported in full — do not
 * expand the allowlist to hide unclassified surfaces.
 */

$root = realpath(__DIR__ . '/../..');
rbacCompletenessAssert($root !== false, 'unable to resolve repository root');

$pageManifest = require $root . '/config/rbac_page_manifest.php';
$routeManifest = require $root . '/config/rbac_route_manifest.php';
rbacCompletenessAssert(is_array($pageManifest), 'page manifest must be array');
rbacCompletenessAssert(is_array($routeManifest), 'route manifest must be array');

// True bootstraps / shared internals that are not HTTP entry points.
// Keep this list tiny; prefer classifying real entry points in manifests.
$allowlist = [
    // Shared helpers required by inventory ajax endpoints; not standalone routes.
    'ajax/inventory_count_common.php' => 'internal include helper for inventory count endpoints',
    'ajax/inventory_transfer_common.php' => 'internal include helper for inventory transfer endpoints',
    // Static analysis bootstrap; never served as an HTTP entry point.
    'phpstan-bootstrap.php' => 'PHPStan bootstrap include, not an HTTP entry point',
];

$classified = [];
foreach (array_keys($pageManifest) as $path) {
    $classified[$path] = 'page';
}
foreach (array_keys($routeManifest) as $path) {
    $classified[$path] = isset($classified[$path]) ? 'page+route' : 'route';
}

// Root-level route entries are enforced by entry_classification_guard via the
// *page* manifest (basename), not the route manifest. Keep both in sync.
$rootRouteOnly = [];
foreach (array_keys($routeManifest) as $path) {
    if (str_starts_with($path, 'ajax/')
        || str_starts_with($path, 'do/')
        || str_starts_with($path, 'print/')
    ) {
        continue;
    }
    if (!isset($pageManifest[$path])) {
        $rootRouteOnly[] = $path;
    }
}
rbacCompletenessAssert(
    $rootRouteOnly === [],
    'root route entries missing from page manifest (RBAC_PAGE_UNCLASSIFIED at runtime): '
        . implode(', ', $rootRouteOnly)
);

$scanned = [];
foreach (rbacCompletenessListPhpFiles($root) as $relative) {
    $scanned[$relative] = true;
}
foreach (['ajax', 'do', 'print'] as $dir) {
    foreach (rbacCompletenessListPhpFiles($root . '/' . $dir, $dir) as $relative) {
        $scanned[$relative] = true;
    }
}
ksort($scanned);

$missing = [];
$allowlisted = [];
foreach (array_keys($scanned) as $relative) {
    if (isset($classified[$relative])) {
        continue;
    }
    if (isset($allowlist[$relative])) {
        $allowlisted[] = $relative;
        continue;
    }
    $missing[] = $relative;
}

foreach (array_keys($allowlist) as $allowedPath) {
    rbacCompletenessAssert(
        isset($scanned[$allowedPath]),
        'allowlist entry missing on filesystem (remove stale allowlist): ' . $allowedPath
    );
    rbacCompletenessAssert(
        !isset($classified[$allowedPath]),
        'allowlist entry is already classified (remove from allowlist): ' . $allowedPath
    );
}

if ($missing !== []) {
    $lines = [
        'rbac-manifest-completeness-FAIL unclassified_count=' . count($missing),
        'scanned=' . count($scanned),
        'page_manifest=' . count($pageManifest),
        'route_manifest=' . count($routeManifest),
        'allowlisted=' . count($allowlisted),
        'unclassified_files:',
    ];
    foreach ($missing as $path) {
        $lines[] = '  - ' . $path;
    }
    fwrite(STDERR, implode("\n", $lines) . "\n");
    throw new RuntimeException(
        'RBAC manifests incomplete: ' . count($missing) . ' unclassified entry points (see stderr for full gap list)'
    );
}

echo 'rbac-manifest-completeness-ok'
    . ' scanned=' . count($scanned)
    . ' page=' . count($pageManifest)
    . ' route=' . count($routeManifest)
    . ' allowlisted=' . count($allowlisted)
    . "\n";

/**
 * @return list<string>
 */
function rbacCompletenessListPhpFiles(string $dir, string $prefix = ''): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }

    $entries = scandir($dir);
    if ($entries === false) {
        return $out;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (substr($entry, -4) !== '.php') {
            continue;
        }
        $full = $dir . '/' . $entry;
        if (!is_file($full)) {
            continue;
        }
        $out[] = $prefix === '' ? $entry : $prefix . '/' . $entry;
    }

    sort($out);

    return $out;
}

function rbacCompletenessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
