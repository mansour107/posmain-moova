<?php

$root = realpath(__DIR__ . '/../..');
getRouteAssert($root !== false, 'repository root unavailable');
$manifest = require $root . '/config/rbac_route_manifest.php';
getRouteAssert(is_array($manifest), 'route manifest must be an array');

$paths = [];
foreach (new DirectoryIterator($root . '/get') as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $paths[] = 'get/' . $file->getFilename();
    }
}
sort($paths, SORT_STRING);

foreach ($paths as $path) {
    getRouteAssert(isset($manifest[$path]), 'unclassified get route: ' . $path);
}

foreach (['get/reduce_remain.php', 'get/export_summery_excel.php'] as $quarantined) {
    getRouteAssert(!empty($manifest[$quarantined]['quarantined']), 'unsafe get route must be quarantined: ' . $quarantined);
}

$reduce = (string) file_get_contents($root . '/get/reduce_remain.php');
$connectPosition = strpos($reduce, "include('../includes/connect.php')");
$selectPosition = strpos($reduce, 'SELECT * FROM booking_cards');
getRouteAssert($connectPosition !== false, 'reduce_remain must enter central guard through connect');
getRouteAssert(
    $selectPosition !== false && $connectPosition < $selectPosition,
    'reduce_remain quarantine guard must execute before database access'
);

$iname = (string) file_get_contents($root . '/get/iname.php');
getRouteAssert(str_contains($iname, 'api_entry_classification.php'), 'iname must use early route classification');
getRouteAssert(str_contains($iname, 'posmain_enforce_entry_permission'), 'iname must enforce menu permission');

$classificationGuard = (string) file_get_contents($root . '/includes/entry_classification_guard.php');
$permissionGuard = (string) file_get_contents($root . '/includes/entry_permission_guard.php');
getRouteAssert(str_contains($classificationGuard, "'get/'"), 'early guard must classify get routes');
getRouteAssert(str_contains($permissionGuard, "'get/'"), 'permission guard must classify get routes');

echo "get-route-classification-contract-ok\n";

function getRouteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
