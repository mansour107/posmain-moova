#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auditPath = $root . '/docs/production/write_surface_audit_latest.json';
$manifestPath = $root . '/config/rbac_route_manifest.php';

if (!is_file($auditPath)) {
    fwrite(STDERR, "Missing audit JSON: {$auditPath}\n");
    exit(1);
}

$payload = json_decode((string) file_get_contents($auditPath), true);
$surfaces = $payload['surfaces'] ?? [];
$manifest = is_file($manifestPath) ? require $manifestPath : [];

$missing = [];
foreach ($surfaces as $surface) {
    $path = (string) ($surface['path'] ?? '');
    if ($path === '' || strpos($path, 'classes/') === 0) {
        continue;
    }
    if (!preg_match('#^(do/|ajax/)[^/]+\.php$#', $path)) {
        continue;
    }
    if (!isset($manifest[$path])) {
        $missing[] = [
            'path' => $path,
            'categories' => $surface['categories'] ?? [],
            'class' => $surface['class'] ?? '',
        ];
    }
}

echo "Manifest entries: " . count($manifest) . "\n";
echo "Unguarded do/ajax surfaces: " . count($missing) . "\n\n";

foreach ($missing as $entry) {
    $categories = implode(',', $entry['categories']);
    echo $entry['path'] . "\tclass=" . $entry['class'] . "\tcategories=" . $categories . "\n";
}

exit(0);
