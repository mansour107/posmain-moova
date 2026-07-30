<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DeliveryWorkerService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DeliveryCompensationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DeliverySettlementService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DeliveryZoneService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderFulfillmentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$database = 'posmain_delivery_operations_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($database);
    $manager = new SyncSchemaManager();
    $planned = $manager->plannedStatements();
    foreach ($manager->deliveryTableKeys() as $table) {
        if (isset($planned[$table])) $conn->query($planned[$table]);
    }
    $conn->query($planned['drawer_sessions']);
    $conn->query($planned['document_counters']);
    $conn->query("CREATE TABLE acc_head (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, aname VARCHAR(160) NOT NULL, parent_id INT NOT NULL DEFAULT 0, is_basic TINYINT NOT NULL DEFAULT 0, is_stock TINYINT NOT NULL DEFAULT 0, is_fund TINYINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0, tenant INT NOT NULL DEFAULT 0, branch INT NOT NULL DEFAULT 0, UNIQUE KEY uq_acc_head_code (code)) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head (code, aname, is_fund) VALUES ('121001', 'الصندوق', 1)");
    $fundId = (int) $conn->insert_id;
    $conn->query("INSERT INTO drawer_sessions (uuid, user_id, tenant, branch, fund_account_id, opened_at, business_day, opened_by, opening_cash, status) VALUES ('52a21934-2404-47af-8ab2-352b05d451f6', 1, 0, 0, {$fundId}, NOW(), CURRENT_DATE, 1, 100.000, 'open')");
    $drawerSessionId = (int) $conn->insert_id;
    $conn->query("CREATE TABLE journal_heads (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, total DECIMAL(19,2) NOT NULL, jdate DATE NOT NULL, details VARCHAR(255) NULL, user INT NULL, op_id INT NULL, op2 INT NULL, source_type VARCHAR(60) NULL, source_id BIGINT NULL, posting_kind VARCHAR(80) NULL, idempotency_key VARCHAR(191) NULL, reversal_of_journal_id BIGINT NULL, tenant INT NULL, branch INT NULL, pro_tybe INT NULL, UNIQUE KEY uq_journal_idempotency (idempotency_key)) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, account_id INT NOT NULL, debit DECIMAL(19,2) NOT NULL DEFAULT 0, credit DECIMAL(19,2) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 INT NULL) ENGINE=InnoDB");
    $conn->query("CREATE TABLE ot_head (id BIGINT NOT NULL PRIMARY KEY, pro_id BIGINT NULL, fat_net DECIMAL(19,2) NOT NULL DEFAULT 0, paid_amount DECIMAL(19,2) NOT NULL DEFAULT 0, remaining_amount DECIMAL(19,2) NOT NULL DEFAULT 0, acc2 INT NULL, payment_status VARCHAR(20) NULL, pro_date DATE NULL, invoice_status VARCHAR(20) NULL, order_status VARCHAR(20) NULL, payment_date DATETIME NULL, completed_at DATETIME NULL, mdtime DATETIME NULL, tenant INT NOT NULL DEFAULT 0, branch INT NOT NULL DEFAULT 0, mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $conn->query("INSERT INTO ot_head (id, pro_id, fat_net, remaining_amount, payment_status, pro_date, invoice_status, order_status) VALUES (7001, 7001, 0, 0, 'paid', CURRENT_DATE, 'posted', 'active'), (7002, 7002, 0, 0, 'paid', CURRENT_DATE, 'posted', 'active'), (7004, 7004, 275, 275, 'unpaid', CURRENT_DATE, 'draft', 'active')");
    $conn->query("INSERT INTO delivery_zones (name, fee, tenant, branch) VALUES ('وسط البلد', 25.000, 0, 0)");
    $zoneId = (int) $conn->insert_id;

    $zoneTamperingBlocked = false;
    try {
        (new DeliveryZoneService())->resolvePostedZone($conn, ['delivery_zone_name' => 'منطقة غير معتمدة', 'delivery_fee' => 1]);
    } catch (Throwable $exception) {
        $zoneTamperingBlocked = $exception->getMessage() === 'DELIVERY_ZONE_INVALID';
    }
    deliveryOperationsAssert($zoneTamperingBlocked, 'configured branches must reject untrusted delivery zones and fees');

    $syncOff = ['config' => ['role' => 'branch', 'sync' => ['outbox_enabled' => false]]];
    $plans = new DeliveryCompensationService();
    $plan = $plans->savePlan($conn, [
        'name' => 'أسبوعي + ثابت',
        'base_period' => 'weekly',
        'base_amount' => '100',
        'per_delivery_method' => 'fixed',
        'per_delivery_value' => '12.345',
        'tips_mode' => 'pass_through',
        'effective_from' => date('Y-m-d', strtotime('-30 days')),
    ], ['user_id' => 1] + $syncOff);
    deliveryOperationsAssert((int) $plan['id'] > 0, 'plan should be created');
    $invalidEnumBlocked = false;
    try {
        $plans->savePlan($conn, [
            'name' => 'خطة غير صالحة',
            'base_period' => 'fortnightly',
            'effective_from' => date('Y-m-d'),
        ], ['user_id' => 1] + $syncOff);
    } catch (Throwable $exception) {
        $invalidEnumBlocked = $exception->getMessage() === 'DELIVERY_PLAN_BASE_PERIOD_INVALID';
    }
    deliveryOperationsAssert($invalidEnumBlocked, 'invalid plan enums must be rejected instead of silently defaulted');

    $noBasePlan = $plans->savePlan($conn, [
        'name' => 'بدون أساسي',
        'base_period' => 'none',
        'base_amount' => '999',
        'effective_from' => date('Y-m-d'),
    ], ['user_id' => 1] + $syncOff);
    deliveryOperationsAssert($noBasePlan['base_period'] === 'none' && $noBasePlan['base_amount'] === '0.000', 'plans without a base period must discard a posted base amount');

    $workers = new DeliveryWorkerService();
    $worker = $workers->saveWorker($conn, [
        'name' => 'عامل اختبار',
        'phone' => '01000000000',
        'compensation_plan_id' => $plan['id'],
    ], ['user_id' => 1] + $syncOff);
    deliveryOperationsAssert((int) $worker['id'] > 0, 'worker should be created without a login account');

    $fulfillment = new OrderFulfillmentService();
    $fulfillment->upsertForOrder($conn, 7001, [
        'fulfillment_type' => 'delivery',
        'customer_name' => 'عميل',
        'customer_phone' => '01111111111',
        'customer_address' => 'عنوان',
        'delivery_zone' => 'وسط البلد',
        'delivery_fee' => 25,
        'delivery_status' => 'pending',
    ], ['require_table' => true]);
    $conn->query("UPDATE order_fulfillment SET delivery_zone_id = {$zoneId} WHERE order_id = 7001");
    $workers->assignOrder($conn, 7001, (int) $worker['id'], ['user_id' => 1] + $syncOff);
    $assigned = $fulfillment->fulfillmentForOrder($conn, 7001);
    deliveryOperationsAssert((int) $assigned['delivery_worker_id'] === (int) $worker['id'], 'assignment should update the authoritative fulfillment row');
    deliveryOperationsAssert((int) $conn->query('SELECT COUNT(*) AS c FROM delivery_assignments WHERE order_id = 7001 AND ended_at IS NULL')->fetch_assoc()['c'] === 1, 'one current assignment should be retained');

    $conn->query("INSERT INTO ot_head (id, pro_id, payment_status, pro_date, invoice_status, order_status, tenant, branch) VALUES (7999, 7999, 'paid', CURRENT_DATE, 'posted', 'active', 7, 9)");
    $fulfillment->upsertForOrder($conn, 7999, ['fulfillment_type' => 'delivery', 'delivery_status' => 'pending'], ['require_table' => true]);
    $crossBranchBlocked = false;
    try {
        $workers->assignOrder($conn, 7999, (int) $worker['id'], ['user_id' => 1, 'tenant' => 0, 'branch' => 0] + $syncOff);
    } catch (Throwable $exception) {
        $crossBranchBlocked = $exception->getMessage() === 'DELIVERY_ORDER_NOT_FOUND';
    }
    deliveryOperationsAssert($crossBranchBlocked, 'assignment must not attach a local worker to another tenant or branch order');

    $fulfillment->upsertForOrder($conn, 7004, [
        'fulfillment_type' => 'delivery',
        'delivery_zone' => 'وسط البلد',
        'delivery_fee' => 25,
        'delivery_status' => 'pending',
    ], ['require_table' => true]);
    $conn->query("UPDATE order_fulfillment SET collection_mode = 'cod', cod_amount = 275.000 WHERE order_id = 7004");
    $workers->assignOrder($conn, 7004, (int) $worker['id'], ['user_id' => 1] + $syncOff);
    $fulfillment->transitionDeliveryStatus($conn, 7004, 'picked_up', ['cashier_dispatch' => true, 'actor_user_id' => 1] + $syncOff);
    $failedOrder = $fulfillment->transitionDeliveryStatus($conn, 7004, 'failed', ['failure_reason' => 'تعذر الوصول للعميل', 'actor_user_id' => 1] + $syncOff);
    deliveryOperationsAssert(($failedOrder['metadata']['failure_reason'] ?? '') === 'تعذر الوصول للعميل', 'failed delivery should retain its operational reason');
    $workerWithFailure = $workers->listWorkers($conn, [], true, true)[0];
    deliveryOperationsAssert((int) $workerWithFailure['failed_order_count'] === 1, 'worker profile should count failed deliveries');
    deliveryOperationsAssert($workerWithFailure['failed_order_value'] === '275.000', 'worker profile should expose failed order value for admin review');
    deliveryOperationsAssert($workerWithFailure['failed_cod_exposure'] === '275.000', 'worker profile should expose failed COD risk without auto-posting it');
    deliveryOperationsAssert((int) $conn->query('SELECT COUNT(*) AS c FROM delivery_order_financials WHERE order_id = 7004')->fetch_assoc()['c'] === 0, 'failed delivery must not auto-accrue worker compensation');

    $transitionOptions = ['actor_user_id' => 1] + $syncOff;
    foreach (['accepted', 'preparing', 'ready', 'picked_up'] as $status) {
        $fulfillment->transitionDeliveryStatus($conn, 7001, $status, $transitionOptions);
    }
    deliveryOperationsAssert($fulfillment->fulfillmentForOrder($conn, 7001)['picked_up_at'] !== null, 'pickup timestamp should be captured');

    $fulfillment->upsertForOrder($conn, 7002, ['fulfillment_type' => 'delivery', 'delivery_status' => 'pending'], ['require_table' => true]);
    foreach (['accepted', 'preparing', 'ready'] as $status) $fulfillment->transitionDeliveryStatus($conn, 7002, $status, $transitionOptions);
    $blocked = false;
    try { $fulfillment->transitionDeliveryStatus($conn, 7002, 'picked_up', $transitionOptions); } catch (Throwable $exception) { $blocked = $exception->getMessage() === 'DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP'; }
    deliveryOperationsAssert($blocked, 'in-house pickup should require a worker');
    deliveryOperationsAssert(
        $fulfillment->countPendingDeliveryOrders($conn, ['tenant' => 0, 'branch' => 0]) === 2,
        'cashier badge should count every local non-terminal delivery while excluding failed and cross-branch orders'
    );

    $workerId = (int) $worker['id'];
    $conn->query("UPDATE order_fulfillment SET delivery_status = 'delivered', delivered_at = NOW(), driver_tip = 5.000, delivery_worker_id = {$workerId} WHERE order_id = 7001");
    $financial = $plans->accrueDeliveredOrder($conn, 7001, $syncOff);
    deliveryOperationsAssert($financial['compensation_amount'] === '12.350', 'delivered order should snapshot fixed compensation at journal currency precision');
    deliveryOperationsAssert($financial['tip_amount'] === '5.000', 'pass-through tip should be snapshotted');
    $replay = $plans->accrueDeliveredOrder($conn, 7001, $syncOff);
    deliveryOperationsAssert((int) $replay['id'] === (int) $financial['id'], 'order accrual should be idempotent');

    $end = date('Y-m-d');
    $start = date('Y-m-d', strtotime('-6 days'));
    $preview = (new DeliverySettlementService())->preview($conn, $workerId, $start, $end);
    deliveryOperationsAssert($preview['delivery_earnings'] === '12.350', 'preview should include open order earnings');
    deliveryOperationsAssert($preview['base_pay'] === '100.000', 'complete weekly period should accrue base pay once');
    deliveryOperationsAssert($preview['net_amount'] === '117.350', 'preview should calculate base plus delivery plus tip');
    $partialPreview = (new DeliverySettlementService())->preview($conn, $workerId, date('Y-m-d', strtotime('-2 days')), $end);
    deliveryOperationsAssert($partialPreview['base_pay'] === '42.860', 'partial weekly periods should prorate base pay instead of dropping it to zero');

    $accrualJournal = (new DeliveryAccountingService())->postDeliveredAccrual($conn, $financial, 1);
    deliveryOperationsAssert((int) $accrualJournal > 0, 'delivered earnings should post a balanced accrual journal');
    $settlementService = new DeliverySettlementService();
    $settlementOptions = [
        'user_id' => 1,
        'payment_method' => 'bank',
        'fund_account_id' => $fundId,
        'idempotency_key' => 'delivery-test-settlement-7001',
    ] + $syncOff;
    $settlement = $settlementService->finalize($conn, $workerId, $start, $end, $settlementOptions);
    deliveryOperationsAssert($settlement['status'] === 'finalized', 'settlement should finalize');
    deliveryOperationsAssert((int) $settlement['journal_head_id'] > 0, 'settlement should retain journal traceability');
    deliveryOperationsAssert((string) $conn->query('SELECT status FROM delivery_order_financials WHERE order_id = 7001')->fetch_assoc()['status'] === 'settled', 'settlement should atomically close included orders');
    $balance = $conn->query('SELECT ROUND(SUM(debit), 2) AS debit, ROUND(SUM(credit), 2) AS credit FROM journal_entries')->fetch_assoc();
    deliveryOperationsAssert((string) $balance['debit'] === (string) $balance['credit'], 'delivery journals should remain balanced');
    $replayedSettlement = $settlementService->finalize($conn, $workerId, $start, $end, $settlementOptions);
    deliveryOperationsAssert(!empty($replayedSettlement['replayed']) && (int) $replayedSettlement['id'] === (int) $settlement['id'], 'settlement idempotency key should replay safely');
    $balanceWorkers = $workers->listWorkers($conn, [], true, true);
    deliveryOperationsAssert($balanceWorkers[0]['open_net_amount'] === '0.000', 'settled orders should leave no open worker balance');

    $reversed = $settlementService->reverse($conn, (int) $settlement['id'], 'اختبار عكس التسوية', ['user_id' => 1] + $syncOff);
    deliveryOperationsAssert($reversed['status'] === 'reversed' && (int) $reversed['reversal_journal_head_id'] > 0, 'owner reversal should append a linked accounting correction');
    deliveryOperationsAssert((string) $conn->query('SELECT status FROM delivery_order_financials WHERE order_id = 7001')->fetch_assoc()['status'] === 'open', 'reversal should reopen the underlying order financials');
    $replayedReversal = $settlementService->reverse($conn, (int) $settlement['id'], 'اختبار عكس التسوية', ['user_id' => 1] + $syncOff);
    deliveryOperationsAssert(!empty($replayedReversal['replayed']), 'reversal should replay safely');

    $cashSettlement = $settlementService->finalize($conn, $workerId, $start, $end, [
        'user_id' => 1,
        'payment_method' => 'cash',
        'fund_account_id' => $fundId,
        'drawer_session_id' => $drawerSessionId,
        'idempotency_key' => 'delivery-test-cash-settlement-7001',
    ] + $syncOff);
    $cashMovement = $conn->query('SELECT movement_type, amount FROM drawer_movements WHERE id = ' . (int) $cashSettlement['drawer_movement_id'])->fetch_assoc();
    deliveryOperationsAssert($cashMovement['movement_type'] === 'paid_out' && $cashMovement['amount'] === '117.350', 'cash payout should be linked to the open drawer as paid out');
    $cashReversal = $settlementService->reverse($conn, (int) $cashSettlement['id'], 'اختبار عكس الصرف النقدي', [
        'user_id' => 1,
        'drawer_session_id' => $drawerSessionId,
    ] + $syncOff);
    $cashReversalMovement = $conn->query('SELECT movement_type, amount FROM drawer_movements WHERE id = ' . (int) $cashReversal['reversal_drawer_movement_id'])->fetch_assoc();
    deliveryOperationsAssert($cashReversalMovement['movement_type'] === 'paid_in' && $cashReversalMovement['amount'] === '117.350', 'cash reversal should append the inverse paid-in movement');
    deliveryOperationsAssert((int) $conn->query('SELECT COUNT(*) AS c FROM drawer_movements WHERE delivery_settlement_id = ' . (int) $cashSettlement['id'])->fetch_assoc()['c'] === 2, 'cash settlement reversal should preserve both drawer audit movements');

    $conn->query("INSERT INTO acc_head (code, aname) VALUES ('110003', 'عميل تحصيل')");
    $customerId = (int) $conn->insert_id;
    $salesId = posmain_ensure_sales_account($conn, 91);
    $conn->query("INSERT INTO ot_head (id, pro_id, fat_net, remaining_amount, acc2, payment_status, pro_date, invoice_status, order_status) VALUES (7003, 7003, 500.00, 500.00, {$customerId}, 'unpaid', CURRENT_DATE, 'draft', 'active')");
    (new FinancialInvoicePostingService())->postInvoiceFinalization($conn, 7003, ['net' => '500.00'], $customerId, $salesId, 1, ['idempotency_key' => 'takeaway-invoice:7003']);
    $fulfillment->upsertForOrder($conn, 7003, ['fulfillment_type' => 'delivery', 'delivery_zone' => 'وسط البلد', 'delivery_fee' => 25, 'delivery_status' => 'pending'], ['require_table' => true]);
    $conn->query("UPDATE order_fulfillment SET delivery_zone_id = {$zoneId}, collection_mode = 'cod', cod_amount = 500.000 WHERE order_id = 7003");
    $workers->assignOrder($conn, 7003, $workerId, ['user_id' => 1] + $syncOff);
    foreach (['accepted', 'preparing', 'ready', 'picked_up', 'delivered'] as $status) {
        $options = $transitionOptions;
        if ($status === 'delivered') $options['cod_amount'] = '500.000';
        $fulfillment->transitionDeliveryStatus($conn, 7003, $status, $options);
    }
    $codOrder = $conn->query('SELECT payment_status, remaining_amount FROM ot_head WHERE id = 7003')->fetch_assoc();
    deliveryOperationsAssert($codOrder['payment_status'] === 'paid' && (float) $codOrder['remaining_amount'] === 0.0, 'COD delivery should close the receivable only when delivered');
    $invoiceJournals = (int) $conn->query("SELECT COUNT(*) AS c FROM journal_heads WHERE source_type = 'invoice' AND source_id = 7003 AND posting_kind = 'invoice_finalization'")->fetch_assoc()['c'];
    deliveryOperationsAssert($invoiceJournals === 1, 'COD completion must not recognize invoice revenue twice');
    $codFinancial = $conn->query('SELECT cod_amount, compensation_amount FROM delivery_order_financials WHERE order_id = 7003')->fetch_assoc();
    deliveryOperationsAssert($codFinancial['cod_amount'] === '500.000' && $codFinancial['compensation_amount'] === '12.350', 'COD cash and worker earnings should share one immutable delivered snapshot');

    $codSettlement = $settlementService->finalize($conn, $workerId, $start, $end, [
        'user_id' => 1,
        'payment_method' => 'cash',
        'fund_account_id' => $fundId,
        'drawer_session_id' => $drawerSessionId,
        'idempotency_key' => 'delivery-test-cod-remittance',
    ] + $syncOff);
    deliveryOperationsAssert($codSettlement['settlement_direction'] === 'worker_pays' && $codSettlement['net_amount'] === '-370.300', 'large COD held should produce a worker remittance settlement without float drift');
    $codMovement = $conn->query('SELECT movement_type, amount FROM drawer_movements WHERE id = ' . (int) $codSettlement['drawer_movement_id'])->fetch_assoc();
    deliveryOperationsAssert($codMovement['movement_type'] === 'paid_in' && $codMovement['amount'] === '370.300', 'worker COD remittance should enter the linked drawer as paid in');
    $codJournalBalance = $conn->query('SELECT ROUND(SUM(debit), 2) AS debit, ROUND(SUM(credit), 2) AS credit FROM journal_entries WHERE journal_id = ' . (int) $conn->query('SELECT journal_id FROM journal_heads WHERE id = ' . (int) $codSettlement['journal_head_id'])->fetch_assoc()['journal_id'])->fetch_assoc();
    deliveryOperationsAssert((string) $codJournalBalance['debit'] === (string) $codJournalBalance['credit'], 'worker-pays COD settlement journal must remain balanced');

    echo "delivery_operations_service_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$database}`");
    $conn->close();
}

function deliveryOperationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_operations_service_test FAILED: {$message}\n");
        exit(1);
    }
}
