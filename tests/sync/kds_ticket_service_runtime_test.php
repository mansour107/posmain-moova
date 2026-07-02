<?php

/**
 * Runtime behavioural test for the KDS ticket pipeline. Exercises routing,
 * idempotent upsert, order edits, per-station completion and the order
 * kitchen_status rollup against a real database. Skips cleanly when no test
 * database is configured.
 *
 * Connects directly (not via connect.php) so the tenant/branch scope stays
 * at 0/0 and posmain_config is not loaded.
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/KdsStationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/KdsTicketService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "kds-ticket-service-runtime-skipped-no-db\n";
    exit(0);
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function kdsRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERT FAILED: ' . $message);
    }
}

$schema = new SyncSchemaManager();
$schema->applyKdsSchema($conn);

$stationService = new KdsStationService();
$ticketService = new KdsTicketService();

// Track created rows for cleanup.
$created = ['stations' => [], 'groups' => [], 'items' => [], 'orders' => []];

try {
    // --- Fixtures --------------------------------------------------------
    $stationA = $stationService->saveStation($conn, ['name' => 'TEST-KDS-A ' . uniqid(), 'is_active' => 1, 'is_default' => 0]);
    $stationB = $stationService->saveStation($conn, ['name' => 'TEST-KDS-B ' . uniqid(), 'is_active' => 1, 'is_default' => 1]);
    $created['stations'] = [$stationA, $stationB];

    $conn->query("INSERT INTO item_group (gname, isdeleted) VALUES ('TEST-CAT-A', 0)");
    $catA = (int) $conn->insert_id;
    $conn->query("INSERT INTO item_group (gname, isdeleted) VALUES ('TEST-CAT-B', 0)");
    $catB = (int) $conn->insert_id;
    $created['groups'] = [$catA, $catB];

    // Route category A explicitly to station A; B is unmapped -> default (station B).
    $stationService->setCategoryStation($conn, $catA, $stationA);

    $conn->query("INSERT INTO myitems (iname, group1, price3, isdeleted) VALUES ('TEST-ITEM-A1', {$catA}, 0, 0)");
    $itemA1 = (int) $conn->insert_id;
    $conn->query("INSERT INTO myitems (iname, group1, price3, isdeleted) VALUES ('TEST-ITEM-A2', {$catA}, 0, 0)");
    $itemA2 = (int) $conn->insert_id;
    $conn->query("INSERT INTO myitems (iname, group1, price3, isdeleted) VALUES ('TEST-ITEM-B1', {$catB}, 0, 0)");
    $itemB1 = (int) $conn->insert_id;
    $created['items'] = [$itemA1, $itemA2, $itemB1];

    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net)
        VALUES (999001, 9, 'takeaway', 'active', 'unpaid', 0, 0)
    ");
    $orderId = (int) $conn->insert_id;
    $created['orders'] = [$orderId];

    $insLine = function (int $itemId, float $qty) use ($conn, $orderId) {
        $stmt = $conn->prepare("
            INSERT INTO fat_details (pro_tybe, item_id, qty_in, qty_out, price, det_value, fatid, fat_tybe, isdeleted)
            VALUES (9, ?, 0, ?, 10, ?, ?, 9, 0)
        ");
        $val = 10 * $qty;
        $stmt->bind_param('iddi', $itemId, $qty, $val, $orderId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    };

    $insLineFor = function (int $itemId, float $qty, int $oid) use ($conn) {
        $stmt = $conn->prepare("
            INSERT INTO fat_details (pro_tybe, item_id, qty_in, qty_out, price, det_value, fatid, fat_tybe, isdeleted)
            VALUES (9, ?, 0, ?, 10, ?, ?, 9, 0)
        ");
        $val = 10 * $qty;
        $stmt->bind_param('iddi', $itemId, $qty, $val, $oid);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    };

    $lineA1 = $insLine($itemA1, 2);
    $lineB1 = $insLine($itemB1, 1);

    // --- 1. Routing ------------------------------------------------------
    $ticketService->syncForOrder($conn, $orderId, 'new', 1);

    $tickets = [];
    $res = $conn->query("SELECT id, station_id, status, item_count FROM kds_tickets WHERE order_id = {$orderId} AND status <> 'cancelled'");
    while ($row = $res->fetch_assoc()) {
        $tickets[(int) $row['station_id']] = $row;
    }
    kdsRuntimeAssert(count($tickets) === 2, 'two station tickets expected, got ' . count($tickets));
    kdsRuntimeAssert(isset($tickets[$stationA]), 'station A ticket missing (category routing)');
    kdsRuntimeAssert(isset($tickets[$stationB]), 'station B ticket missing (default fallback)');
    kdsRuntimeAssert((int) $tickets[$stationA]['item_count'] === 1, 'station A should hold 1 line');
    kdsRuntimeAssert((int) $tickets[$stationB]['item_count'] === 1, 'station B should hold 1 line');

    $ticketA = (int) $tickets[$stationA]['id'];
    $ticketB = (int) $tickets[$stationB]['id'];

    // --- 2. Idempotency --------------------------------------------------
    $changesBefore = (int) $conn->query("SELECT COUNT(*) c FROM kds_changes WHERE station_id = {$stationA}")->fetch_assoc()['c'];
    $revBefore = (int) $conn->query("SELECT revision FROM kds_tickets WHERE id = {$ticketA}")->fetch_assoc()['revision'];
    $ticketService->syncForOrder($conn, $orderId, 'updated', 1);
    $changesAfter = (int) $conn->query("SELECT COUNT(*) c FROM kds_changes WHERE station_id = {$stationA}")->fetch_assoc()['c'];
    $revAfter = (int) $conn->query("SELECT revision FROM kds_tickets WHERE id = {$ticketA}")->fetch_assoc()['revision'];
    kdsRuntimeAssert($changesBefore === $changesAfter, 'idempotent re-sync must not append change rows');
    kdsRuntimeAssert($revBefore === $revAfter, 'idempotent re-sync must not bump revision');

    // --- 3. Order edit (add line to station A) ---------------------------
    $lineA2 = $insLine($itemA2, 3);
    $ticketService->syncForOrder($conn, $orderId, 'updated', 1);
    $itemCountA = (int) $conn->query("SELECT item_count FROM kds_tickets WHERE id = {$ticketA}")->fetch_assoc()['item_count'];
    $revAfterEdit = (int) $conn->query("SELECT revision FROM kds_tickets WHERE id = {$ticketA}")->fetch_assoc()['revision'];
    kdsRuntimeAssert($itemCountA === 2, 'station A should now hold 2 lines after edit');
    kdsRuntimeAssert($revAfterEdit > $revAfter, 'edit must bump revision');

    // --- 4. Cursor feed --------------------------------------------------
    $feedFull = $ticketService->changesSince($conn, $stationA, 0);
    kdsRuntimeAssert($feedFull['full'] === true, 'since=0 must return a full snapshot');
    kdsRuntimeAssert(count($feedFull['changes']) >= 1, 'full snapshot should include the active ticket');
    $cursor = (int) $feedFull['cursor'];
    $feedEmpty = $ticketService->changesSince($conn, $stationA, $cursor);
    kdsRuntimeAssert(count($feedEmpty['changes']) === 0, 'no changes expected past the latest cursor');

    // --- 5. Per-station completion + order rollup ------------------------
    kdsRuntimeAssert($ticketService->completeTicket($conn, $ticketA, 1) === true, 'completing station A should succeed');
    $kitchenStatus = (string) $conn->query("SELECT kitchen_status FROM ot_head WHERE id = {$orderId}")->fetch_assoc()['kitchen_status'];
    kdsRuntimeAssert($kitchenStatus === 'in_progress', "order kitchen_status should be in_progress while B pending, got {$kitchenStatus}");

    // Concurrency guard: completing an already-completed ticket is a no-op.
    kdsRuntimeAssert($ticketService->completeTicket($conn, $ticketA, 1) === false, 'double-complete must be rejected');

    kdsRuntimeAssert($ticketService->completeTicket($conn, $ticketB, 1) === true, 'completing station B should succeed');
    $kitchenStatus = (string) $conn->query("SELECT kitchen_status FROM ot_head WHERE id = {$orderId}")->fetch_assoc()['kitchen_status'];
    kdsRuntimeAssert($kitchenStatus === 'completed', "order kitchen_status should be completed once all stations done, got {$kitchenStatus}");

    // --- 6. Void cancels tickets ----------------------------------------
    $conn->query("UPDATE ot_head SET order_status = 'cancelled' WHERE id = {$orderId}");
    $ticketService->syncForOrder($conn, $orderId, 'updated', 1);
    $activeAfterVoid = (int) $conn->query("SELECT COUNT(*) c FROM kds_tickets WHERE order_id = {$orderId} AND status <> 'cancelled'")->fetch_assoc()['c'];
    kdsRuntimeAssert($activeAfterVoid === 0, 'voided order must have no active tickets');

    // --- 7. Reconcile must not backfill ancient open orders --------------
    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net, pro_date, mdtime)
        VALUES (999002, 9, 'takeaway', 'active', 'unpaid', 0, 0, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY))
    ");
    $staleOrderId = (int) $conn->insert_id;
    $created['orders'][] = $staleOrderId;
    $conn->query("
        INSERT INTO fat_details (pro_tybe, item_id, qty_in, qty_out, price, det_value, fatid, fat_tybe, isdeleted)
        VALUES (9, {$itemA1}, 0, 1, 10, 10, {$staleOrderId}, 9, 0)
    ");
    $ticketService->reconcile($conn, 50);
    $staleTickets = (int) $conn->query("SELECT COUNT(*) c FROM kds_tickets WHERE order_id = {$staleOrderId}")->fetch_assoc()['c'];
    kdsRuntimeAssert($staleTickets === 0, 'reconcile must not import orders older than the lookback window');

    // --- 8. Supplement ticket edit model --------------------------------
    // Post-completion adds -> fresh supplement card with delta only.
    // Post-completion removals -> silent ledger update, no board card.
    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net)
        VALUES (999003, 9, 'takeaway', 'active', 'unpaid', 0, 0)
    ");
    $editOrderId = (int) $conn->insert_id;
    $created['orders'][] = $editOrderId;
    $insLineFor($itemA1, 2, $editOrderId);
    $editLineA2 = $insLineFor($itemA2, 1, $editOrderId);
    $ticketService->syncForOrder($conn, $editOrderId, 'new', 1);
    $editTicket = (int) $conn->query("SELECT id FROM kds_tickets WHERE order_id = {$editOrderId} AND station_id = {$stationA} AND parent_ticket_id IS NULL")->fetch_assoc()['id'];

    kdsRuntimeAssert($ticketService->completeTicket($conn, $editTicket, 1) === true, 'edit-model: complete should succeed');

    $conn->query("DELETE FROM fat_details WHERE id = {$editLineA2}");
    $ticketService->syncForOrder($conn, $editOrderId, 'updated', 1);
    $activeAfterRemove = (int) $conn->query("SELECT COUNT(*) c FROM kds_tickets WHERE order_id = {$editOrderId} AND station_id = {$stationA} AND status IN ('new','in_progress')")->fetch_assoc()['c'];
    $rootStatus = (string) $conn->query("SELECT status FROM kds_tickets WHERE id = {$editTicket}")->fetch_assoc()['status'];
    kdsRuntimeAssert($activeAfterRemove === 0, 'edit-model: removal after complete must not create a board card');
    kdsRuntimeAssert($rootStatus === 'completed', 'edit-model: root ticket must stay completed after removal');

    $insLineFor($itemA2, 1, $editOrderId);
    $ticketService->syncForOrder($conn, $editOrderId, 'updated', 1);
    $supplement = $conn->query("SELECT id, parent_ticket_id FROM kds_tickets WHERE order_id = {$editOrderId} AND station_id = {$stationA} AND status IN ('new','in_progress')")->fetch_assoc();
    kdsRuntimeAssert($supplement !== null, 'edit-model: add after complete must create a supplement');
    kdsRuntimeAssert((int) $supplement['parent_ticket_id'] === $editTicket, 'edit-model: supplement must link to root');
    $supplementId = (int) $supplement['id'];
    $supplementLines = (int) $conn->query("SELECT COUNT(*) c FROM kds_ticket_lines WHERE ticket_id = {$supplementId} AND line_status IN ('new','cooking')")->fetch_assoc()['c'];
    kdsRuntimeAssert($supplementLines === 1, 'edit-model: supplement must only show the delta line');
    $rootStatus = (string) $conn->query("SELECT status FROM kds_tickets WHERE id = {$editTicket}")->fetch_assoc()['status'];
    kdsRuntimeAssert($rootStatus === 'completed', 'edit-model: root must stay completed while supplement is open');

    // --- 9. POS detail_id churn (delete + reinsert fat_details) ------------
    // After KDS completion, a normal POS save recreates every fat_details row.
    // Matching must use stable line keys, not ephemeral detail_id values.
    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net)
        VALUES (999004, 9, 'takeaway', 'active', 'unpaid', 0, 0)
    ");
    $churnOrderId = (int) $conn->insert_id;
    $created['orders'][] = $churnOrderId;
    $insLineFor($itemA1, 1, $churnOrderId);
    $ticketService->syncForOrder($conn, $churnOrderId, 'new', 1);
    $churnTicket = (int) $conn->query("SELECT id FROM kds_tickets WHERE order_id = {$churnOrderId} AND station_id = {$stationA} AND parent_ticket_id IS NULL")->fetch_assoc()['id'];
    kdsRuntimeAssert($ticketService->completeTicket($conn, $churnTicket, 1) === true, 'churn: complete root ticket');

    $conn->query("DELETE FROM fat_details WHERE fatid = {$churnOrderId}");
    $insLineFor($itemA1, 1, $churnOrderId);
    $ticketService->syncForOrder($conn, $churnOrderId, 'updated', 1);
    $activeAfterChurnResave = (int) $conn->query("SELECT COUNT(*) c FROM kds_tickets WHERE order_id = {$churnOrderId} AND station_id = {$stationA} AND status IN ('new','in_progress')")->fetch_assoc()['c'];
    kdsRuntimeAssert($activeAfterChurnResave === 0, 'churn: identical re-save must not reopen the board');

    $insLineFor($itemA2, 1, $churnOrderId);
    $ticketService->syncForOrder($conn, $churnOrderId, 'updated', 1);
    $churnSupplement = $conn->query("SELECT id FROM kds_tickets WHERE order_id = {$churnOrderId} AND station_id = {$stationA} AND status IN ('new','in_progress')")->fetch_assoc();
    kdsRuntimeAssert($churnSupplement !== null, 'churn: post-complete add must spawn a supplement');
    $churnSupplementId = (int) $churnSupplement['id'];
    $churnSupplementLines = (int) $conn->query("SELECT COUNT(*) c FROM kds_ticket_lines WHERE ticket_id = {$churnSupplementId} AND line_status IN ('new','cooking')")->fetch_assoc()['c'];
    kdsRuntimeAssert($churnSupplementLines === 1, 'churn: supplement must contain only the new delta line');

    echo "kds-ticket-service-runtime-ok\n";
} finally {
    // --- Cleanup ---------------------------------------------------------
    foreach ($created['orders'] as $orderId) {
        $conn->query("DELETE l FROM kds_ticket_lines l JOIN kds_tickets t ON t.id = l.ticket_id WHERE t.order_id = {$orderId}");
        $conn->query("DELETE FROM kds_changes WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM kds_tickets WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM fat_details WHERE fatid = {$orderId}");
        $conn->query("DELETE FROM ot_head WHERE id = {$orderId}");
    }
    foreach ($created['items'] as $itemId) {
        $conn->query("DELETE FROM myitems WHERE id = {$itemId}");
    }
    foreach ($created['groups'] as $groupId) {
        $conn->query("DELETE FROM kds_station_categories WHERE item_group_id = {$groupId}");
        $conn->query("DELETE FROM item_group WHERE id = {$groupId}");
    }
    foreach ($created['stations'] as $stationId) {
        $conn->query("DELETE FROM kds_station_users WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_station_categories WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_tickets WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_changes WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_stations WHERE id = {$stationId}");
    }
}
