<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/includes/auth_guard.php';

$manager = new SyncSchemaManager();
$planned = $manager->plannedStatements();
$tables = ['delivery_compensation_plans', 'delivery_compensation_zone_rates', 'delivery_workers', 'delivery_assignments', 'delivery_order_financials', 'delivery_settlements', 'delivery_settlement_lines'];
foreach ($tables as $table) {
    deliveryContractAssert(isset($planned[$table]), "missing planned table {$table}");
    deliveryContractAssert(strpos($planned[$table], 'CREATE TABLE IF NOT EXISTS ' . $table) !== false, "{$table} should be additive");
}
deliveryContractAssert(strpos($planned['delivery_order_financials'], 'UNIQUE KEY uq_delivery_order_financial_order (order_id)') !== false, 'one immutable financial snapshot should exist per order');
deliveryContractAssert(strpos($planned['delivery_settlements'], 'UNIQUE KEY uq_delivery_settlement_idempotency (idempotency_key)') !== false, 'settlement finalization should be idempotent');
deliveryContractAssert(strpos($planned['delivery_assignments'], 'ended_at DATETIME NULL') !== false, 'assignment history should be append-only');
deliveryContractAssert(strpos($planned['order_fulfillment'], "collection_mode ENUM('prepaid','cod')") !== false, 'fulfillment should model prepaid and COD');

$permissions = auth_guard_permission_map();
foreach (['delivery.assign', 'delivery.workers.manage', 'delivery.compensation.manage', 'delivery.settlements.manage', 'delivery.settlements.reverse', 'delivery.reports.view'] as $permission) {
    deliveryContractAssert(isset($permissions[$permission]), "missing permission {$permission}");
}
deliveryContractAssert($permissions['delivery.settlements.reverse'] === ['__admin_only'], 'settlement reversal should remain owner-only');

foreach (['delivery_management.php', 'ajax/delivery_workers.php', 'ajax/delivery_assign.php', 'ajax/delivery_plans.php', 'ajax/delivery_settlements.php', 'js/delivery_management.js', 'css/delivery-operations.css'] as $path) {
    deliveryContractAssert(is_file($root . '/' . $path), "missing delivery surface {$path}");
}
foreach (['ajax/delivery_workers.php', 'ajax/delivery_assign.php', 'ajax/delivery_plans.php', 'ajax/delivery_settlements.php'] as $path) {
    $endpoint = file_get_contents($root . '/' . $path);
    deliveryContractAssert(strpos($endpoint, 'posmain_require_delivery_schema_ready') !== false, "{$path} must fail clearly while delivery migrations are pending");
}
$pageManifest = require $root . '/config/rbac_page_manifest.php';
deliveryContractAssert(isset($pageManifest['delivery_management.php']), 'delivery management must be classified in the fail-closed page manifest');
$accounting = file_get_contents($root . '/classes/Pos/Service/DeliveryAccountingService.php');
deliveryContractAssert(strpos($accounting, "takeaway-invoice:' . \$orderId") !== false, 'COD completion should reuse the original invoice posting');
$settlements = file_get_contents($root . '/classes/Pos/Service/DeliverySettlementService.php');
deliveryContractAssert(strpos($settlements, 'public function reverse(') !== false, 'settlements should support owner-only append-only reversal');
deliveryContractAssert(strpos($settlements, 'Money::from(') !== false && strpos($settlements, 'private function sum(array $rows, string $column): float') === false, 'settlement aggregation must use fixed-point Money');
deliveryContractAssert(strpos($settlements, "case 'weekly': return \$this->prorate") !== false, 'weekly base pay should prorate partial periods');
$workers = file_get_contents($root . '/classes/Pos/Service/DeliveryWorkerService.php');
deliveryContractAssert(strpos($workers, 'INNER JOIN ot_head o ON o.id = f.order_id') !== false, 'assignment should enforce order tenant/branch when the shared schema exposes scope columns');
deliveryContractAssert(substr_count($workers, 'FROM delivery_order_financials df') === 0, 'worker balances should use one grouped financial join instead of correlated subqueries');
$compensation = file_get_contents($root . '/classes/Pos/Service/DeliveryCompensationService.php');
deliveryContractAssert(strpos($compensation, 'DELIVERY_PLAN_METHOD_INVALID') !== false, 'invalid plan methods should be rejected explicitly');
foreach ([$workers, $compensation, $settlements] as $deliveryService) {
    deliveryContractAssert(strpos($deliveryService, 'Delivery sync skipped:') === false, 'delivery sync failures must roll back their owning transaction');
}
$dispatch = file_get_contents($root . '/classes/Pos/Service/OrderFulfillmentService.php');
deliveryContractAssert(strpos($dispatch, 'DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP') !== false, 'pickup must enforce worker assignment');
deliveryContractAssert(strpos($dispatch, 'finalizeCodOrder') !== false, 'delivered COD should finalize accounting');
$cashier = file_get_contents($root . '/js/pos_delivery.js');
$cashierQueue = file_get_contents($root . '/js/pos_delivery_queue.js');
deliveryContractAssert(strpos($cashier, 'id="delivery_worker_id"') === false, 'delivery creation should not assign a worker before dispatch');
deliveryContractAssert(strpos($cashierQueue, "data.action = 'dispatch'") !== false && strpos($cashierQueue, "action: 'delivered'") !== false, 'cashier queue should own dispatch and delivery confirmation');
deliveryContractAssert(strpos($cashierQueue, "value=\"external\"") === false || strpos(file_get_contents($root . '/includes/pos_content.php'), 'value="external"') !== false, 'cashier dispatch should allow an external courier');

echo "delivery_operations_contract_test: OK\n";

function deliveryContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_operations_contract_test FAILED: {$message}\n");
        exit(1);
    }
}
