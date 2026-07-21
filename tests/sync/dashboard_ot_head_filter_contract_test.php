<?php

$root = dirname(__DIR__, 2);

$paths = [
    $root . '/elements/main',
    $root . '/classes/Dashboard',
    $root . '/dashboard.php',
];

$unsafe = [];
$pattern = '/pro_tybe\s*=\s*3\s+OR\s+pro_tybe\s*=\s*9\s+AND\s+isdeleted/i';

foreach ($paths as $path) {
    if (is_file($path)) {
        $files = [$path];
    } elseif (is_dir($path)) {
        $files = glob($path . '/*.php') ?: [];
    } else {
        continue;
    }
    foreach ($files as $file) {
        $src = file_get_contents($file);
        if ($src !== false && preg_match($pattern, $src)) {
            $unsafe[] = str_replace($root . '/', '', $file);
        }
    }
}

// Also scan common report surfaces that historically shared the bug.
$extra = [
    'operations_summary.php',
    'sales-reports.php',
];
foreach ($extra as $rel) {
    $file = $root . '/' . $rel;
    if (!is_file($file)) {
        continue;
    }
    $src = file_get_contents($file);
    if ($src !== false && preg_match($pattern, $src)) {
        $unsafe[] = $rel;
    }
}

otHeadFilterAssert($unsafe === [], 'unsafe ot_head OR/isdeleted pattern found in: ' . implode(', ', $unsafe));

$serviceSrc = file_get_contents($root . '/classes/Dashboard/DashboardOverviewService.php');
otHeadFilterAssert(
    strpos($serviceSrc, 'OperationsReportService') !== false
        && strpos($serviceSrc, 'pro_tybe = 9') !== false,
    'DashboardOverviewService must consume the canonical POS report service'
);

echo "dashboard-ot-head-filter-contract-ok\n";

function otHeadFilterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "dashboard-ot-head-filter-contract-fail: {$message}\n");
        exit(1);
    }
}
