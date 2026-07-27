<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/KdsStationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/KdsTicketService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ModifierLineNoteService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../../classes/Sync/PosOrderSnapshotBuilder.php';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(
    getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
    getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
    getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2',
    (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307)
);
if ($conn->connect_error) {
    echo "kds-instruction-snapshot-runtime-skipped-no-db\n";
    exit(0);
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function kdsInstructionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERT FAILED: ' . $message);
    }
}

(new SyncSchemaManager())->apply($conn);
$stations = new KdsStationService();
$tickets = new KdsTicketService();
$customizations = new ModifierLineNoteService();
$preparation = new PreparationSelectionService();
$ids = ['orders' => [], 'details' => [], 'items' => [], 'categories' => [], 'stations' => [], 'modifier_groups' => [], 'modifier_options' => []];

try {
    $stationId = $stations->saveStation($conn, [
        'name' => 'TEST-INSTRUCTION-SNAPSHOT-' . uniqid(),
        'is_active' => 1,
        'is_default' => 0,
    ]);
    $ids['stations'][] = $stationId;
    $conn->query("INSERT INTO item_group (gname, isdeleted) VALUES ('TEST-INSTRUCTION-CAT', 0)");
    $categoryId = (int) $conn->insert_id;
    $ids['categories'][] = $categoryId;
    $stations->setCategoryStation($conn, $categoryId, $stationId);

    $conn->query("INSERT INTO myitems (iname, group1, price3, isdeleted) VALUES ('شاي اختبار', {$categoryId}, 10, 0)");
    $itemId = (int) $conn->insert_id;
    $ids['items'][] = $itemId;
    $conn->query("
        INSERT INTO modifier_groups (name_ar, name_en, selection_min, selection_max, is_required, is_active, sort_order)
        VALUES ('إضافات اختبار', 'Test additions', 0, 2, 0, 1, 0)
    ");
    $modifierGroupId = (int) $conn->insert_id;
    $ids['modifier_groups'][] = $modifierGroupId;
    $conn->query("
        INSERT INTO modifier_options (group_id, name_ar, name_en, price_delta, is_active, sort_order)
        VALUES
          ({$modifierGroupId}, 'نعناع', 'Mint', 1, 1, 0),
          ({$modifierGroupId}, 'ليمون', 'Lemon', 2, 1, 1)
    ");
    $mintId = (int) $conn->insert_id;
    $lemonId = $mintId + 1;
    $ids['modifier_options'] = [$mintId, $lemonId];
    $conn->query("INSERT INTO item_modifier_groups (item_id, group_id, sort_order) VALUES ({$itemId}, {$modifierGroupId}, 0)");

    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net)
        VALUES (999901, 9, 'takeaway', 'active', 'unpaid', 0, 20)
    ");
    $orderId = (int) $conn->insert_id;
    $ids['orders'][] = $orderId;
    $insertLine = $conn->prepare("
        INSERT INTO fat_details (pro_tybe, item_id, qty_in, qty_out, price, det_value, fatid, fat_tybe, isdeleted)
        VALUES (9, ?, 0, 1, 10, 10, ?, 9, 0)
    ");
    $insertLine->bind_param('ii', $itemId, $orderId);
    $insertLine->execute();
    $lineOne = (int) $insertLine->insert_id;
    $insertLine->execute();
    $lineTwo = (int) $insertLine->insert_id;
    $insertLine->close();
    $ids['details'] = [$lineOne, $lineTwo];

    $customizations->saveLineCustomizations(
        $conn,
        $orderId,
        $lineOne,
        $itemId,
        [['option_id' => $mintId, 'qty' => 2]],
        [['note_type' => 'kitchen', 'note_text' => 'خفيف جداً']],
        ['modifiers_enabled' => true, 'user_id' => 1]
    );
    $customizations->saveLineCustomizations(
        $conn,
        $orderId,
        $lineTwo,
        $itemId,
        [['option_id' => $lemonId, 'qty' => 1]],
        [['note_type' => 'kitchen', 'note_text' => 'مغلي جيداً']],
        ['modifiers_enabled' => true, 'user_id' => 1]
    );
    $preparation->persistLineValues($conn, $orderId, $lineOne, $itemId, [[
        'code' => 'sugar_spoons', 'label_ar' => 'ملاعق سكر', 'value' => 0, 'max_value' => 999,
    ]]);
    $preparation->persistLineValues($conn, $orderId, $lineTwo, $itemId, [[
        'code' => 'sugar_spoons', 'label_ar' => 'ملاعق سكر', 'value' => 3, 'max_value' => 999,
    ]]);

    $tickets->syncForOrder($conn, $orderId, 'new', 1);
    $ticket = $conn->query("SELECT id, revision FROM kds_tickets WHERE order_id = {$orderId} AND station_id = {$stationId}")->fetch_assoc();
    kdsInstructionAssert(is_array($ticket), 'station ticket should be created');
    $ticketId = (int) $ticket['id'];
    $revision = (int) $ticket['revision'];
    $feed = $tickets->changesSince($conn, $stationId, 0);
    $publicTicket = null;
    foreach ($feed['changes'] as $change) {
        if ((int) ($change['ticket']['id'] ?? 0) === $ticketId) {
            $publicTicket = $change['ticket'];
        }
    }
    kdsInstructionAssert(is_array($publicTicket), 'reconnect snapshot should contain the ticket');
    $lines = $publicTicket['lines'] ?? [];
    kdsInstructionAssert(count($lines) === 2, 'repeated products must remain two distinct kitchen lines');
    kdsInstructionAssert($lines[0]['modifiers'][0]['name_ar'] === 'نعناع', 'first modifier snapshot must not leak');
    kdsInstructionAssert($lines[1]['modifiers'][0]['name_ar'] === 'ليمون', 'second modifier snapshot must not leak');
    kdsInstructionAssert($lines[0]['notes'] === 'خفيف جداً', 'first free-form instruction expected');
    kdsInstructionAssert($lines[1]['notes'] === 'مغلي جيداً', 'second free-form instruction expected');
    kdsInstructionAssert((int) $lines[0]['preparation_values'][0]['value'] === 0, 'explicit zero preparation must survive');
    kdsInstructionAssert((int) $lines[1]['preparation_values'][0]['value'] === 3, 'second preparation value expected');

    $tickets->syncForOrder($conn, $orderId, 'updated', 1);
    $sameRevision = (int) $conn->query("SELECT revision FROM kds_tickets WHERE id = {$ticketId}")->fetch_assoc()['revision'];
    kdsInstructionAssert($sameRevision === $revision, 'idempotent resend must not bump the ticket');
    $lineCount = (int) $conn->query("SELECT COUNT(*) c FROM kds_ticket_lines WHERE ticket_id = {$ticketId}")->fetch_assoc()['c'];
    kdsInstructionAssert($lineCount === 2, 'idempotent resend must not duplicate kitchen work');

    $conn->query("UPDATE myitems SET iname = 'اسم قائمة متغير', group1 = 0 WHERE id = {$itemId}");
    $conn->query("UPDATE modifier_options SET name_ar = 'إضافة متغيرة' WHERE group_id = {$modifierGroupId}");
    $tickets->syncForOrder($conn, $orderId, 'updated', 1);
    $afterRename = $tickets->changesSince($conn, $stationId, 0);
    $renamedTicket = null;
    foreach ($afterRename['changes'] as $change) {
        if ((int) ($change['ticket']['id'] ?? 0) === $ticketId) {
            $renamedTicket = $change['ticket'];
        }
    }
    kdsInstructionAssert($renamedTicket['lines'][0]['name'] === 'شاي اختبار', 'later menu rename must not alter sent item');
    kdsInstructionAssert($renamedTicket['lines'][0]['modifiers'][0]['name_ar'] === 'نعناع', 'later option rename must not alter sent modifier');
    $activeStation = (int) $conn->query("
        SELECT station_id FROM kds_tickets
        WHERE order_id = {$orderId} AND status IN ('new','in_progress')
        ORDER BY id DESC LIMIT 1
    ")->fetch_assoc()['station_id'];
    kdsInstructionAssert($activeStation === $stationId, 'later category changes must not reroute the sent kitchen snapshot');
    $syncSnapshot = (new PosOrderSnapshotBuilder())->build(
        $conn,
        '11111111-1111-4111-8111-111111111111',
        $orderId,
        ['source_system' => 'kds_instruction_test']
    );
    kdsInstructionAssert(
        $syncSnapshot['lines'][0]['kitchen_snapshot']['payload']['name'] === 'شاي اختبار',
        'sync must carry the authoritative sent kitchen snapshot'
    );
    kdsInstructionAssert(
        $syncSnapshot['lines'][1]['kitchen_snapshot']['payload']['notes'][0]['note_text'] === 'مغلي جيداً',
        'sync must preserve per-line preparation instructions'
    );

    $conn->query("
        INSERT INTO ot_head (pro_id, pro_tybe, order_type, order_status, payment_status, isdeleted, fat_net)
        VALUES (999902, 9, 'takeaway', 'active', 'unpaid', 0, 10)
    ");
    $badOrderId = (int) $conn->insert_id;
    $ids['orders'][] = $badOrderId;
    $insertLine = $conn->prepare("
        INSERT INTO fat_details (pro_tybe, item_id, qty_in, qty_out, price, det_value, fatid, fat_tybe, isdeleted)
        VALUES (9, ?, 0, 1, 10, 10, ?, 9, 0)
    ");
    $insertLine->bind_param('ii', $itemId, $badOrderId);
    $insertLine->execute();
    $badLineId = (int) $insertLine->insert_id;
    $insertLine->close();
    $ids['details'][] = $badLineId;
    $conn->query("
        INSERT INTO order_line_preparation_values
            (order_id, fat_detail_id, item_id, field_code, label_ar, value_int, max_value, inventory_qty_per_value)
        VALUES ({$badOrderId}, {$badLineId}, {$itemId}, '', '', 1, 999, 0)
    ");
    $failed = false;
    try {
        $tickets->syncForOrder($conn, $badOrderId, 'new', 1);
    } catch (RuntimeException $exception) {
        $failed = $exception->getMessage() === 'KITCHEN_SNAPSHOT_PREPARATION_INVALID';
    }
    kdsInstructionAssert($failed, 'malformed required preparation must fail visibly');
    $badTicketCount = (int) $conn->query("SELECT COUNT(*) c FROM kds_tickets WHERE order_id = {$badOrderId}")->fetch_assoc()['c'];
    kdsInstructionAssert($badTicketCount === 0, 'malformed preparation must not create an incomplete ticket');

    echo "kds-instruction-snapshot-runtime-ok\n";
} finally {
    foreach ($ids['orders'] as $orderId) {
        $conn->query("DELETE FROM kds_order_events WHERE order_id = {$orderId}");
        $conn->query("DELETE l FROM kds_ticket_lines l JOIN kds_tickets t ON t.id = l.ticket_id WHERE t.order_id = {$orderId}");
        $conn->query("DELETE FROM kds_changes WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM kds_tickets WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM order_line_kitchen_snapshots WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM order_line_preparation_values WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM order_line_notes WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM order_line_modifiers WHERE order_id = {$orderId}");
        $conn->query("DELETE FROM fat_details WHERE fatid = {$orderId}");
        $conn->query("DELETE FROM ot_head WHERE id = {$orderId}");
    }
    foreach ($ids['items'] as $itemId) {
        $conn->query("DELETE FROM item_modifier_groups WHERE item_id = {$itemId}");
        $conn->query("DELETE FROM myitems WHERE id = {$itemId}");
    }
    foreach ($ids['modifier_options'] as $optionId) {
        $conn->query("DELETE FROM modifier_options WHERE id = {$optionId}");
    }
    foreach ($ids['modifier_groups'] as $groupId) {
        $conn->query("DELETE FROM modifier_groups WHERE id = {$groupId}");
    }
    foreach ($ids['categories'] as $categoryId) {
        $conn->query("DELETE FROM kds_station_categories WHERE item_group_id = {$categoryId}");
        $conn->query("DELETE FROM item_group WHERE id = {$categoryId}");
    }
    foreach ($ids['stations'] as $stationId) {
        $conn->query("DELETE FROM kds_station_users WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_station_categories WHERE station_id = {$stationId}");
        $conn->query("DELETE FROM kds_stations WHERE id = {$stationId}");
    }
    $conn->close();
}
