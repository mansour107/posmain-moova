<?php

$root = dirname(__DIR__, 2);

// This isolated service contract intentionally exercises the dormant multi-store
// transfer implementation. Commercial V1 keeps single-store mode enabled and
// blocks these endpoints at the runtime boundary.
if (!function_exists('posmain_single_store_mode_enabled')) {
    function posmain_single_store_mode_enabled(): bool
    {
        return false;
    }
}

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryTransferService.php';

inventoryPhase8AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase8-transfer-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase8_transfer_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase8CreateLegacyItemTable($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES
            (6501, 'Transfer item', 'T-6501', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6502, 'Transfer service item', 'T-6502', 0.000000, 0.000000, 0.000000, 'service', 0)
    ");
    $conn->query("INSERT INTO myunits (id, uname) VALUES (901, 'Case')");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, cost_price)
        VALUES (6501, 901, 12.000000, 30.000000)
    ");
    $conn->query("
        INSERT INTO inventory_reason_codes (id, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active)
        VALUES (8801, 'transfer_missing', 'Missing during transfer', 'transfer_variance', 'out', 0, 1, 1)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $ledger = new InventoryLedgerService($flags);
    $service = new InventoryTransferService($flags, $ledger);
    inventoryPhase8SeedStock($conn, $ledger, 6501, 3, '10.000000', '2.500000', 'seed-6501');

    $draft = $service->createDraft($conn, [
        'transfer_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'source_store_id' => 3,
        'destination_store_id' => 4,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase8Assert($draft['status'] === 'draft', 'transfer should start as draft');
    $line = inventoryPhase8One($conn, 'SELECT * FROM inventory_transfer_lines WHERE transfer_id = ' . (int) $draft['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($line['requested_qty'], '6.000000'), 'transfer line should keep requested qty');

    $replay = $service->createDraft($conn, [
        'transfer_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'source_store_id' => 3,
        'destination_store_id' => 4,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase8Assert(!empty($replay['idempotent_replay']), 'same transfer uuid should replay');
    inventoryPhase8Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_transfers')->fetch_assoc()['c'] === 1, 'transfer replay should not duplicate header');
    try {
        $service->createDraft($conn, [
            'transfer_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'source_store_id' => 3,
            'destination_store_id' => 4,
            'lines' => [
                ['item_id' => 6501, 'requested_qty' => '7.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase8Assert(false, 'changed transfer uuid retry should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'TRANSFER_IDEMPOTENCY_CONFLICT', 'changed transfer uuid retry should return expected conflict');
    }

    try {
        $service->createDraft($conn, [
            'transfer_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'source_store_id' => 3,
            'destination_store_id' => 3,
            'lines' => [
                ['item_id' => 6501, 'requested_qty' => '1.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase8Assert(false, 'same source/destination should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'TRANSFER_STORES_MUST_DIFFER', 'same store transfer should return expected code');
    }

    inventoryPhase8SeedStock($conn, $ledger, 6501, 11, '8.000000', '2.500000', 'seed-6501-cross-branch-source', 0);
    $crossBranchDraft = $service->createDraft($conn, [
        'transfer_uuid' => '12121212-1212-4212-8212-121212121212',
        'source_store_id' => 11,
        'destination_store_id' => 11,
        'destination_pos_branch' => 2,
        'destination_branch_uuid' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '3.000000'],
        ],
    ], ['user_id' => 7, 'pos_branch' => 0, 'branch_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa']);
    $crossBranchHeader = inventoryPhase8One($conn, 'SELECT pos_branch, branch_uuid, destination_pos_branch, destination_branch_uuid FROM inventory_transfers WHERE id = ' . (int) $crossBranchDraft['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert((int) $crossBranchHeader['pos_branch'] === 0 && (int) $crossBranchHeader['destination_pos_branch'] === 2, 'cross-branch transfer should store source and destination branches separately');
    inventoryPhase8Assert($crossBranchHeader['destination_branch_uuid'] === 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'cross-branch transfer should keep destination branch uuid');
    $service->send($conn, (int) $crossBranchDraft['transfer_id'], ['user_id' => 9]);
    $crossBranchLine = inventoryPhase8One($conn, 'SELECT * FROM inventory_transfer_lines WHERE transfer_id = ' . (int) $crossBranchDraft['transfer_id'] . ' LIMIT 1');
    $service->receive($conn, (int) $crossBranchDraft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $crossBranchLine['id'], 'received_qty' => '3.000000'],
        ],
    ], ['user_id' => 10]);
    $crossSourceBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND pos_branch = 0 AND store_id = 11 LIMIT 1');
    $crossDestinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND pos_branch = 2 AND store_id = 11 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($crossSourceBalance['qty_on_hand'], '5.000000'), 'cross-branch send should decrease only source branch stock');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($crossDestinationBalance['qty_on_hand'], '3.000000'), 'cross-branch receive should increase destination branch stock');

    $draftCancel = $service->createDraft($conn, [
        'transfer_uuid' => '33333333-3333-4333-8333-333333333333',
        'source_store_id' => 3,
        'destination_store_id' => 4,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '1.000000'],
        ],
    ], ['user_id' => 7]);
    $draftCancelResult = $service->cancel($conn, (int) $draftCancel['transfer_id'], ['reason' => 'created by mistake'], ['user_id' => 8]);
    inventoryPhase8Assert($draftCancelResult['status'] === 'cancelled' && $draftCancelResult['movement_ids'] === [], 'draft transfer cancel should not write stock movements');
    $draftCancelRow = inventoryPhase8One($conn, 'SELECT status, cancelled_at, notes FROM inventory_transfers WHERE id = ' . (int) $draftCancel['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert($draftCancelRow['status'] === 'cancelled' && $draftCancelRow['cancelled_at'] !== null, 'draft transfer cancel should stamp cancelled status');
    inventoryPhase8Assert(strpos((string) $draftCancelRow['notes'], 'created by mistake') !== false, 'draft transfer cancel should keep reason');

    inventoryPhase8SeedStock($conn, $ledger, 6501, 9, '5.000000', '2.500000', 'seed-6501-cancel');
    $sentCancel = $service->createDraft($conn, [
        'transfer_uuid' => '44444444-4444-4444-8444-444444444444',
        'source_store_id' => 9,
        'destination_store_id' => 10,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '3.000000'],
        ],
    ], ['user_id' => 7]);
    $sentCancelLine = inventoryPhase8One($conn, 'SELECT * FROM inventory_transfer_lines WHERE transfer_id = ' . (int) $sentCancel['transfer_id'] . ' LIMIT 1');
    $service->send($conn, (int) $sentCancel['transfer_id'], ['user_id' => 9]);
    $sentCancelSource = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 9 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($sentCancelSource['qty_on_hand'], '2.000000'), 'sent transfer should decrease source before cancel');
    $sentCancelResult = $service->cancel($conn, (int) $sentCancel['transfer_id'], ['reason' => 'truck cancelled'], ['user_id' => 8]);
    inventoryPhase8Assert($sentCancelResult['status'] === 'cancelled' && count($sentCancelResult['movement_ids']) === 1, 'sent transfer cancel should write one inverse movement');
    $sentCancelSource = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 9 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($sentCancelSource['qty_on_hand'], '5.000000'), 'sent transfer cancel should restore source stock');
    $cancelMovement = inventoryPhase8One($conn, "SELECT movement_type, source_type, qty_in, metadata_json FROM inventory_movements WHERE idempotency_key LIKE 'inventory-transfer:v1:cancel:%' AND source_id = " . (int) $sentCancelLine['id'] . ' LIMIT 1');
    inventoryPhase8Assert($cancelMovement['movement_type'] === 'transfer_in' && inventoryPhase8DecimalEquals($cancelMovement['qty_in'], '3.000000'), 'sent transfer cancel should reverse by transfer_in to source');
    inventoryPhase8Assert(strpos((string) $cancelMovement['metadata_json'], 'truck cancelled') !== false, 'sent transfer cancel movement should keep reason');
    $sentCancelReplay = $service->cancel($conn, (int) $sentCancel['transfer_id'], ['reason' => 'replay'], ['user_id' => 8]);
    inventoryPhase8Assert(!empty($sentCancelReplay['idempotent_replay']), 'cancelled transfer should replay safely');

    $service->submit($conn, (int) $draft['transfer_id'], ['user_id' => 8]);
    $send = $service->send($conn, (int) $draft['transfer_id'], ['user_id' => 9]);
    inventoryPhase8Assert($send['status'] === 'sent' && count($send['movement_ids']) === 1, 'send should create transfer_out movement');
    $sourceBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 3 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($sourceBalance['qty_on_hand'], '4.000000'), 'send should decrease source store');
    $sentLine = inventoryPhase8One($conn, 'SELECT sent_qty, received_qty, unit_cost, transfer_out_movement_id FROM inventory_transfer_lines WHERE id = ' . (int) $line['id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($sentLine['sent_qty'], '6.000000'), 'send should mark line sent');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($sentLine['unit_cost'], '2.500000'), 'send should carry source moving average cost');
    inventoryPhase8Assert((int) $sentLine['transfer_out_movement_id'] > 0, 'send should attach out movement');
    $sendReplay = $service->send($conn, (int) $draft['transfer_id'], ['user_id' => 9]);
    inventoryPhase8Assert(!empty($sendReplay['idempotent_replay']), 'sent transfer should replay send safely');

    $partialReceive = $service->receive($conn, (int) $draft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $line['id'], 'received_qty' => '4.000000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase8Assert($partialReceive['status'] === 'partially_received' && count($partialReceive['movement_ids']) === 1, 'partial receive should create transfer_in movement');
    $destinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand, moving_average_cost FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 4 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($destinationBalance['qty_on_hand'], '4.000000'), 'partial receive should increase destination');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($destinationBalance['moving_average_cost'], '2.500000'), 'transfer in should carry source cost');

    $partialReplay = $service->receive($conn, (int) $draft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $line['id'], 'received_qty' => '4.000000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase8Assert($partialReplay['movement_ids'] === [], 'same received target should not duplicate transfer_in movement');
    $destinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 4 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($destinationBalance['qty_on_hand'], '4.000000'), 'same received target should not duplicate destination stock');

    inventoryPhase8SeedStock($conn, $ledger, 6501, 7, '9.000000', '2.500000', 'seed-6501-variance');
    $varianceDraft = $service->createDraft($conn, [
        'transfer_uuid' => '77777777-7777-4777-8777-777777777777',
        'source_store_id' => 7,
        'destination_store_id' => 8,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '5.000000'],
        ],
    ], ['user_id' => 7]);
    $varianceLine = inventoryPhase8One($conn, 'SELECT * FROM inventory_transfer_lines WHERE transfer_id = ' . (int) $varianceDraft['transfer_id'] . ' LIMIT 1');
    $service->send($conn, (int) $varianceDraft['transfer_id'], ['user_id' => 9]);
    $service->receive($conn, (int) $varianceDraft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $varianceLine['id'], 'received_qty' => '3.000000'],
        ],
    ], ['user_id' => 10]);
    try {
        $service->cancel($conn, (int) $varianceDraft['transfer_id'], ['reason' => 'cannot cancel partial'], ['user_id' => 8]);
        inventoryPhase8Assert(false, 'partially received transfer should not cancel directly');
    } catch (RuntimeException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'TRANSFER_NOT_CANCELLABLE', 'partially received cancel should return expected code');
    }
    $varianceClose = $service->closeVariance($conn, (int) $varianceDraft['transfer_id'], [
        'reason_code_id' => 8801,
        'reason' => 'box damaged in transit',
    ], ['user_id' => 11]);
    inventoryPhase8Assert($varianceClose['status'] === 'variance_closed' && (int) $varianceClose['variance_lines'] === 1, 'variance close should close a partially received transfer');
    $varianceTransfer = inventoryPhase8One($conn, 'SELECT status, closed_at, notes FROM inventory_transfers WHERE id = ' . (int) $varianceDraft['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert($varianceTransfer['status'] === 'variance_closed' && $varianceTransfer['closed_at'] !== null, 'variance close should stamp closed status and time');
    inventoryPhase8Assert(strpos((string) $varianceTransfer['notes'], 'box damaged in transit') !== false, 'variance close should keep header reason evidence');
    $varianceLineAfterClose = inventoryPhase8One($conn, 'SELECT received_qty, variance_qty, reason_code_id, notes FROM inventory_transfer_lines WHERE id = ' . (int) $varianceLine['id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($varianceLineAfterClose['received_qty'], '3.000000'), 'variance close should not change received quantity');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($varianceLineAfterClose['variance_qty'], '2.000000'), 'variance close should preserve missing transfer variance');
    inventoryPhase8Assert((int) $varianceLineAfterClose['reason_code_id'] === 8801, 'variance close should attach reason code');
    $varianceDestinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 8 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($varianceDestinationBalance['qty_on_hand'], '3.000000'), 'variance close should not add missing quantity to destination');
    $varianceMovementCount = (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE source_type = 'inventory_transfer' AND source_id = " . (int) $varianceLine['id'])->fetch_assoc()['c'];
    inventoryPhase8Assert($varianceMovementCount === 2, 'variance close should not create extra stock movements');
    $varianceReplay = $service->closeVariance($conn, (int) $varianceDraft['transfer_id'], ['reason' => 'replay'], ['user_id' => 11]);
    inventoryPhase8Assert(!empty($varianceReplay['idempotent_replay']), 'variance closed transfer should replay safely');

    try {
        $service->receive($conn, (int) $draft['transfer_id'], [
            'lines' => [
                ['transfer_line_id' => (int) $line['id'], 'received_qty' => '7.000000'],
            ],
        ], ['user_id' => 10]);
        inventoryPhase8Assert(false, 'over receive should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'TRANSFER_OVER_RECEIVE', 'over receive should return expected code');
    }

    $finalReceive = $service->receive($conn, (int) $draft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $line['id'], 'received_qty' => '6.000000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase8Assert($finalReceive['status'] === 'received', 'full receive should mark transfer received');
    $destinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 4 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($destinationBalance['qty_on_hand'], '6.000000'), 'full receive should land destination on sent qty');
    $receivedLine = inventoryPhase8One($conn, 'SELECT received_qty, variance_qty, transfer_in_movement_id FROM inventory_transfer_lines WHERE id = ' . (int) $line['id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($receivedLine['received_qty'], '6.000000'), 'full receive should update received qty');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($receivedLine['variance_qty'], '0.000000'), 'full receive should clear variance qty');
    inventoryPhase8Assert((int) $receivedLine['transfer_in_movement_id'] > 0, 'receive should attach in movement');

    inventoryPhase8SeedStock($conn, $ledger, 6501, 5, '24.000000', '2.500000', 'seed-6501-case');
    $unitDraft = $service->createDraft($conn, [
        'transfer_uuid' => '11111111-1111-4111-8111-111111111111',
        'source_store_id' => 5,
        'destination_store_id' => 6,
        'lines' => [
            ['item_id' => 6501, 'unit_id' => 901, 'requested_qty' => '2.000000'],
        ],
    ], ['user_id' => 7]);
    $unitLine = inventoryPhase8One($conn, 'SELECT * FROM inventory_transfer_lines WHERE transfer_id = ' . (int) $unitDraft['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert((int) $unitLine['unit_id'] === 901, 'unit transfer should preserve selected unit');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitLine['requested_qty'], '2.000000'), 'unit transfer line should keep entered quantity');

    $service->send($conn, (int) $unitDraft['transfer_id'], ['user_id' => 9]);
    $unitSourceBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 5 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitSourceBalance['qty_on_hand'], '0.000000'), 'unit transfer send should decrease source by base quantity');
    $unitSentLine = inventoryPhase8One($conn, 'SELECT sent_qty, unit_cost, transfer_out_movement_id FROM inventory_transfer_lines WHERE id = ' . (int) $unitLine['id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitSentLine['sent_qty'], '2.000000'), 'unit transfer send should keep sent qty in entered unit');
    $unitOutMovement = inventoryPhase8One($conn, 'SELECT qty_out, unit_id, unit_conversion_to_base, unit_cost, total_cost, metadata_json FROM inventory_movements WHERE id = ' . (int) $unitSentLine['transfer_out_movement_id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitOutMovement['qty_out'], '24.000000'), 'unit transfer out should post base qty');
    inventoryPhase8Assert((int) $unitOutMovement['unit_id'] === 901, 'unit transfer out should store selected unit');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitOutMovement['unit_conversion_to_base'], '12.000000'), 'unit transfer out should store conversion');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitOutMovement['unit_cost'], '2.500000'), 'unit transfer out should use base moving average cost');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitOutMovement['total_cost'], '60.000000'), 'unit transfer out should value base quantity');
    inventoryPhase8Assert(strpos((string) $unitOutMovement['metadata_json'], '"entered_qty":"2.000000"') !== false, 'unit transfer out metadata should keep entered qty');

    $unitPartialReceive = $service->receive($conn, (int) $unitDraft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $unitLine['id'], 'received_qty' => '1.000000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase8Assert($unitPartialReceive['status'] === 'partially_received', 'unit partial receive should stay partial');
    $unitDestinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand, moving_average_cost FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 6 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitDestinationBalance['qty_on_hand'], '12.000000'), 'unit partial receive should increase destination by base delta');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitDestinationBalance['moving_average_cost'], '2.500000'), 'unit transfer in should carry base unit cost');
    $unitReceivedLine = inventoryPhase8One($conn, 'SELECT received_qty, variance_qty, transfer_in_movement_id FROM inventory_transfer_lines WHERE id = ' . (int) $unitLine['id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitReceivedLine['received_qty'], '1.000000'), 'unit receive should keep received qty in entered unit');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitReceivedLine['variance_qty'], '1.000000'), 'unit receive variance should stay in entered unit');
    $unitInMovement = inventoryPhase8One($conn, 'SELECT qty_in, unit_id, unit_conversion_to_base, unit_cost, total_cost, metadata_json FROM inventory_movements WHERE id = ' . (int) $unitReceivedLine['transfer_in_movement_id'] . ' LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitInMovement['qty_in'], '12.000000'), 'unit transfer in should post base qty');
    inventoryPhase8Assert((int) $unitInMovement['unit_id'] === 901, 'unit transfer in should store selected unit');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitInMovement['unit_conversion_to_base'], '12.000000'), 'unit transfer in should store conversion');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitInMovement['total_cost'], '30.000000'), 'unit transfer in should value base delta');
    inventoryPhase8Assert(strpos((string) $unitInMovement['metadata_json'], '"target_received_qty":"1.000000"') !== false, 'unit transfer in metadata should keep target received qty');

    $unitFinalReceive = $service->receive($conn, (int) $unitDraft['transfer_id'], [
        'lines' => [
            ['transfer_line_id' => (int) $unitLine['id'], 'received_qty' => '2.000000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase8Assert($unitFinalReceive['status'] === 'received', 'unit full receive should close transfer');
    $unitDestinationBalance = inventoryPhase8One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6501 AND store_id = 6 LIMIT 1');
    inventoryPhase8Assert(inventoryPhase8DecimalEquals($unitDestinationBalance['qty_on_hand'], '24.000000'), 'unit full receive should land destination on base sent qty');

    try {
        $service->createDraft($conn, [
            'transfer_uuid' => '22222222-2222-4222-8222-222222222222',
            'source_store_id' => 5,
            'destination_store_id' => 6,
            'lines' => [
                ['item_id' => 6501, 'unit_id' => 999999, 'requested_qty' => '1.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase8Assert(false, 'unknown transfer unit should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'ITEM_UNIT_NOT_FOUND', 'unknown transfer unit should return expected code');
    }
    inventoryPhase8Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_transfers WHERE transfer_uuid = '22222222-2222-4222-8222-222222222222'")->fetch_assoc()['c'] === 0, 'unknown transfer unit should roll back header');

    try {
        $service->createDraft($conn, [
            'transfer_uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'source_store_id' => 3,
            'destination_store_id' => 4,
            'lines' => [
                ['item_id' => 6502, 'requested_qty' => '1.000000'],
            ],
        ], ['user_id' => 7]);
        $badTransferId = (int) $conn->query("SELECT id FROM inventory_transfers WHERE transfer_uuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff'")->fetch_assoc()['id'];
        $service->send($conn, $badTransferId, ['user_id' => 9]);
        inventoryPhase8Assert(false, 'service item should not transfer');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'NON_STOCK_ITEM_CANNOT_BE_TRANSFERRED', 'service item transfer should return expected code');
    }

    $conn->query('ALTER TABLE inventory_transfers DROP COLUMN destination_branch_uuid');
    $conn->query('ALTER TABLE inventory_transfers DROP COLUMN destination_pos_branch');
    $legacySchemaDraft = $service->createDraft($conn, [
        'transfer_uuid' => 'abababab-abab-4bab-8bab-abababababab',
        'source_store_id' => 12,
        'destination_store_id' => 13,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '1.000000'],
        ],
    ], ['user_id' => 7, 'pos_branch' => 0, 'branch_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa']);
    inventoryPhase8Assert($legacySchemaDraft['status'] === 'draft', 'same-branch transfer should save on legacy transfer schema');
    $legacySchemaHeader = inventoryPhase8One($conn, 'SELECT pos_branch, branch_uuid, source_store_id, destination_store_id FROM inventory_transfers WHERE id = ' . (int) $legacySchemaDraft['transfer_id'] . ' LIMIT 1');
    inventoryPhase8Assert((int) $legacySchemaHeader['pos_branch'] === 0 && $legacySchemaHeader['branch_uuid'] === 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'legacy transfer schema should keep source branch as the transfer branch');
    inventoryPhase8Assert((int) $legacySchemaHeader['source_store_id'] === 12 && (int) $legacySchemaHeader['destination_store_id'] === 13, 'legacy transfer schema should keep source and destination stores');
    $legacySchemaReplay = $service->createDraft($conn, [
        'transfer_uuid' => 'abababab-abab-4bab-8bab-abababababab',
        'source_store_id' => 12,
        'destination_store_id' => 13,
        'lines' => [
            ['item_id' => 6501, 'requested_qty' => '1.000000'],
        ],
    ], ['user_id' => 7, 'pos_branch' => 0, 'branch_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa']);
    inventoryPhase8Assert(!empty($legacySchemaReplay['idempotent_replay']), 'legacy transfer schema should still replay duplicate transfer uuid');

    try {
        $service->createDraft($conn, [
            'transfer_uuid' => 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
            'source_store_id' => 14,
            'destination_store_id' => 14,
            'destination_pos_branch' => 2,
            'destination_branch_uuid' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
            'lines' => [
                ['item_id' => 6501, 'requested_qty' => '1.000000'],
            ],
        ], ['user_id' => 7, 'pos_branch' => 0, 'branch_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa']);
        inventoryPhase8Assert(false, 'cross-branch transfer should fail on legacy transfer schema');
    } catch (RuntimeException $exception) {
        inventoryPhase8Assert($exception->getMessage() === 'TRANSFER_DESTINATION_BRANCH_SCHEMA_MISSING', 'legacy transfer schema should require migration before cross-branch transfer');
    }

    echo "inventory-phase8-transfer-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase8SeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, int $storeId, string $qty, string $unitCost, string $key, int $posBranch = 0): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => [
            'pos_tenant' => 0,
            'pos_branch' => $posBranch,
            'store_id' => $storeId,
        ],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'phase8:' . $key,
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'phase8-transfer:' . $key,
        'metadata' => ['source' => 'phase8_transfer_test'],
        'created_by' => 7,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase8CreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  barcode VARCHAR(100) NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE myunits (
  id INT NOT NULL PRIMARY KEY,
  uname VARCHAR(80) NOT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_units (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  unit_id INT NOT NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase8AssertSourceContracts(string $root): void
{
    $page = inventoryPhase8Source($root . '/inventory_transfers.php');
    foreach (['تحويلات المخزون', 'ajax/inventory_transfer_save.php', 'inventory-transfer-csrf', 'payload.source_store_id === payload.destination_store_id', 'inventoryTransferDestinationBranch', 'destination_pos_branch', 'destination_branch_uuid', 'فرع الوجهة', 'مخزن المصدر والوجهة يجب أن يكونا مختلفين', 'inventoryTransferUnitOptions', 'inventoryTransferPreferredUnit', 'preferred_count_unit_id', 'الوحدة', 'inventoryTransferStatusLabel', "'partially_received' => 'استلام جزئي'", "'variance_closed' => 'مغلق بفرق'", 'inventoryTransferLineTotal', 'applyInventoryTransferItemSearch', 'ابحث باسم الصنف أو الباركود', 'نتائج مطابقة', 'مخزن غير مسمى', 'فرع غير مسمى'] as $needle) {
        inventoryPhase8Assert(strpos($page, $needle) !== false, 'transfer list UI should include: ' . $needle);
    }
    inventoryPhase8Assert(strpos($page, "فرع ' .") === false, 'transfer list UI should avoid raw branch id fallbacks');

    $detail = inventoryPhase8Source($root . '/inventory_transfer_detail.php');
    foreach (['ajax/inventory_transfer_submit.php', 'ajax/inventory_transfer_send.php', 'ajax/inventory_transfer_receive.php', 'ajax/inventory_transfer_close_variance.php', 'ajax/inventory_transfer_cancel.php', 'resolved_destination_pos_branch', 'unit_conversion', 'closeInventoryTransferVariance', 'cancelInventoryTransfer', 'inventoryTransferBarcodeScan', 'applyInventoryTransferScan', 'مسح باركود الاستلام', 'كمية كل مسحة', 'إغلاق الفرق', 'إلغاء التحويل', 'inventoryTransferDetailStatusLabel', "'sent' => 'مرسل من المصدر'", "'received' => 'تم الاستلام'", 'مخزن غير مسمى', 'فرع غير مسمى', 'صنف غير مسمى'] as $needle) {
        inventoryPhase8Assert(strpos($detail, $needle) !== false, 'transfer detail UI should include: ' . $needle);
    }
    inventoryPhase8Assert(strpos($detail, "\$line['iname'] ?? \$line['item_id']") === false, 'transfer detail UI should avoid raw item id fallbacks');
    inventoryPhase8Assert(strpos($detail, "فرع ' .") === false, 'transfer detail UI should avoid raw branch id fallbacks');

    $commonEndpoint = inventoryPhase8Source($root . '/ajax/inventory_transfer_common.php');
    foreach (['ITEM_UNIT_NOT_FOUND', 'INVALID_UNIT_CONVERSION', 'TRANSFER_VARIANCE_REASON_REQUIRED', 'TRANSFER_NOT_CANCELLABLE'] as $needle) {
        inventoryPhase8Assert(strpos($commonEndpoint, $needle) !== false, 'transfer common endpoint should include: ' . $needle);
    }
    $varianceEndpoint = inventoryPhase8Source($root . '/ajax/inventory_transfer_close_variance.php');
    foreach (["auth_guard_has_permission('inventory.approve'", "auth_guard_has_permission('accounting.view'", 'allow_reason_code_approval'] as $needle) {
        inventoryPhase8Assert(strpos($varianceEndpoint, $needle) !== false, 'transfer variance endpoint should require approval context: ' . $needle);
    }

    $endpoint = inventoryPhase8Source($root . '/ajax/inventory_transfer_send.php');
    foreach (['InventoryTransferService.php', "require_permission('inventory.edit'", "require_csrf('inventory_transfer'"] as $needle) {
        inventoryPhase8Assert(strpos($endpoint, $needle) !== false, 'transfer endpoint should include: ' . $needle);
    }

    $sidebar = inventoryPhase8Source($root . '/includes/sidebar.php');
    inventoryPhase8Assert(strpos($sidebar, 'inventory_transfers.php') !== false && strpos($sidebar, 'تحويلات المخزون') !== false, 'sidebar should link Arabic transfer page');

    $docs = inventoryPhase8Source($root . '/docs/inventory/phase8_transfer_contracts.md');
    foreach (['create-transfer UI blocks same-store', 'Cross-branch destination branch selection', 'same-branch transfers can still be created', 'TRANSFER_DESTINATION_BRANCH_SCHEMA_MISSING', 'TRANSFER_IDEMPOTENCY_CONFLICT', 'In-app browser smoke verified', 'full create/submit/send/receive transfer workflow', 'selected transfer units', 'Explicit variance-close workflow', '`inventory.approve` or `accounting.view`', 'Sent-but-not-received transfers can be cancelled', 'Default transfer unit selection uses stock-level preferred units', 'barcode receive-entry mode', 'UI-only', 'Arabic status labels', 'per-line item search by name/barcode', 'selected-line counter', 'generic unnamed labels'] as $needle) {
        inventoryPhase8Assert(strpos($docs, $needle) !== false, 'phase8 docs should include: ' . $needle);
    }
}

function inventoryPhase8One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase8Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase8DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase8Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase8Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
