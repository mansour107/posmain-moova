<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryOperationalHardeningService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_operational_health_check.php [--json]\n");
    fwrite(STDOUT, "Read-only Phase 17 inventory hardening check for indexes, bounded pagination helpers, and live-mode safeguards.\n");
    exit(0);
}

$service = new InventoryOperationalHardeningService();
$required = $service->requiredIndexes();
$requiredIndexCount = 0;
foreach ($required as $indexes) {
    $requiredIndexCount += count($indexes);
}
$result = [
    'ok' => false,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'phase' => '17',
    'summary' => [
        'required_index_count' => $requiredIndexCount,
        'missing_index_count' => 0,
        'index_check_status' => 'not_checked',
        'inventory_endpoint_count' => 0,
        'endpoint_security_issue_count' => 0,
        'skipped_endpoint_helper_count' => 0,
        'hardening_controls' => [],
    ],
    'missing_indexes' => [],
    'endpoint_security_issues' => [],
    'skipped_endpoint_helpers' => [],
    'blockers' => [],
];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $result['summary']['index_check_status'] = 'checked';
    foreach ($required as $table => $indexes) {
        foreach ($indexes as $index) {
            if (!inventoryOperationalHealthIndexExists($conn, $table, $index)) {
                $result['missing_indexes'][] = $table . '.' . $index;
            }
        }
    }
    $conn->close();

    $result['summary']['missing_index_count'] = count($result['missing_indexes']);
} catch (Throwable $exception) {
    $result['summary']['index_check_status'] = 'database_unreachable';
    $result['blockers'][] = 'inventory_operational_health_database_unreachable';
    $result['error'] = $exception->getMessage();
}

$stockReadSource = inventoryOperationalHealthSource(__DIR__ . '/../classes/Inventory/InventoryStockReadService.php');
$hardeningSource = inventoryOperationalHealthSource(__DIR__ . '/../classes/Inventory/InventoryOperationalHardeningService.php');
$adjustmentPageSource = inventoryOperationalHealthSource(__DIR__ . '/../inventory_adjustments.php');
$adjustmentEndpointSource = inventoryOperationalHealthSource(__DIR__ . '/../ajax/inventory_adjustment.php');
foreach ([
    'movement_history_pagination' => 'LIMIT {$limit} OFFSET {$offset}',
    'retryable_deadlock_detection' => 'RETRYABLE_MYSQL_CODES',
    'operator_safe_stock_message' => 'stock_unavailable',
] as $control => $needle) {
    if (strpos($stockReadSource . $hardeningSource, $needle) !== false) {
        $result['summary']['hardening_controls'][] = $control;
    } else {
        $result['blockers'][] = 'missing_hardening_control_' . $control;
    }
}
foreach ([
    'adjustment_cost_payload_guard' => [
        $adjustmentPageSource,
        'inventoryAdjustmentCanViewCost',
        'payload.unit_cost',
    ],
    'adjustment_endpoint_cost_guard' => [
        $adjustmentEndpointSource,
        'unset($payload[\'unit_cost\'], $payload[\'total_cost\'])',
        'auth_guard_has_permission(\'accounting.view\'',
    ],
] as $control => $needles) {
    $source = array_shift($needles);
    $hasControl = true;
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            $hasControl = false;
            break;
        }
    }
    if ($hasControl) {
        $result['summary']['hardening_controls'][] = $control;
    } else {
        $result['blockers'][] = 'missing_hardening_control_' . $control;
    }
}

$endpointSecurity = inventoryOperationalHealthEndpointSecurity(__DIR__ . '/../ajax');
$result['summary']['inventory_endpoint_count'] = $endpointSecurity['checked_count'];
$result['endpoint_security_issues'] = $endpointSecurity['issues'];
$result['summary']['endpoint_security_issue_count'] = count($endpointSecurity['issues']);
$result['skipped_endpoint_helpers'] = $endpointSecurity['skipped_helpers'];
$result['summary']['skipped_endpoint_helper_count'] = count($endpointSecurity['skipped_helpers']);
if ($endpointSecurity['issues']) {
    $result['blockers'][] = 'inventory_endpoint_security_missing_controls';
}

if ($result['missing_indexes']) {
    $result['blockers'][] = 'missing_required_inventory_indexes';
}
$result['ok'] = empty($result['blockers']);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryOperationalHealthPrint($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function inventoryOperationalHealthIndexExists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare("
SELECT COUNT(*) AS index_count
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND INDEX_NAME = ?");
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['index_count'] ?? 0) > 0;
}

function inventoryOperationalHealthSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryOperationalHealthEndpointSecurity(string $ajaxDir): array
{
    $files = glob(rtrim($ajaxDir, '/') . '/inventory_*.php') ?: [];
    sort($files);

    $helperFiles = [
        'inventory_count_common.php' => true,
        'inventory_transfer_common.php' => true,
    ];
    $checkedCount = 0;
    $issues = [];
    $skippedHelpers = [];

    foreach ($files as $file) {
        $basename = basename($file);
        if (isset($helperFiles[$basename])) {
            $skippedHelpers[] = 'ajax/' . $basename;
            continue;
        }

        $checkedCount++;
        $source = inventoryOperationalHealthSource($file);
        $missing = [];
        if (strpos($source, 'require_csrf(') === false) {
            $missing[] = 'csrf';
        }
        if (strpos($source, 'require_permission(') === false) {
            $missing[] = 'permission';
        }

        if ($missing) {
            $issues[] = [
                'file' => 'ajax/' . $basename,
                'missing' => $missing,
            ];
        }
    }

    return [
        'checked_count' => $checkedCount,
        'issues' => $issues,
        'skipped_helpers' => $skippedHelpers,
    ];
}

function inventoryOperationalHealthPrint(array $result): void
{
    fwrite(STDOUT, 'Inventory operational health: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    fwrite(STDOUT, '- required indexes: ' . (int) ($result['summary']['required_index_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- index check status: ' . (string) ($result['summary']['index_check_status'] ?? 'unknown') . PHP_EOL);
    fwrite(STDOUT, '- missing indexes: ' . (int) ($result['summary']['missing_index_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- inventory endpoints: ' . (int) ($result['summary']['inventory_endpoint_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- endpoint security issues: ' . (int) ($result['summary']['endpoint_security_issue_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- skipped endpoint helpers: ' . (int) ($result['summary']['skipped_endpoint_helper_count'] ?? 0) . PHP_EOL);
    if (!empty($result['summary']['hardening_controls'])) {
        fwrite(STDOUT, "- controls:\n");
        foreach ($result['summary']['hardening_controls'] as $control) {
            fwrite(STDOUT, '  - ' . $control . PHP_EOL);
        }
    }
    if (!empty($result['skipped_endpoint_helpers'])) {
        fwrite(STDOUT, "- skipped endpoint helpers:\n");
        foreach ($result['skipped_endpoint_helpers'] as $helper) {
            fwrite(STDOUT, '  - ' . $helper . PHP_EOL);
        }
    }
    if (!empty($result['endpoint_security_issues'])) {
        fwrite(STDOUT, "- endpoint security issues:\n");
        foreach ($result['endpoint_security_issues'] as $issue) {
            fwrite(STDOUT, '  - ' . ($issue['file'] ?? 'unknown') . ': ' . implode(', ', $issue['missing'] ?? []) . PHP_EOL);
        }
    }
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . $blocker . PHP_EOL);
        }
    }
}
