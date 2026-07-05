<?php

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
$auditJson = __DIR__ . '/../../docs/production/write_surface_audit_latest.json';
rbacCoverageAssert(is_file($auditJson), 'write surface audit json missing');

$payload = json_decode(file_get_contents($auditJson), true);
rbacCoverageAssert(is_array($payload['surfaces'] ?? null), 'audit surfaces missing');

$allPaths = [];
foreach ($payload['surfaces'] as $surface) {
    $path = (string) ($surface['path'] ?? '');
    if ($path === '' || strpos($path, 'classes/') === 0) {
        continue;
    }
    if (!preg_match('#^(do/|ajax/)[^/]+\.php$#', $path)) {
        continue;
    }
    $allPaths[$path] = true;
}

$missing = [];
foreach (array_keys($allPaths) as $path) {
    if (!isset($manifest[$path])) {
        $missing[] = $path;
    }
}

$covered = count($allPaths) - count($missing);
$total = max(1, count($allPaths));
$ratio = $covered / $total;
rbacCoverageAssert($ratio >= 1.0, 'manifest must cover all do/ajax audit paths: ' . round($ratio * 100, 1) . '% missing=' . implode(',', array_slice($missing, 0, 8)));

echo "rbac-manifest-coverage-ok ratio=" . round($ratio * 100, 1) . "% total={$total}\n";

function rbacCoverageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
