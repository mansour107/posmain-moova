<?php

require_once __DIR__ . '/pos_takeaway_invoice_handler_test.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_split_recipe_endpoint_' . getmypid();
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/posmain-split-recipe-session-' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-split-payment-recipe-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = null;

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    $conn->query("ALTER TABLE ot_head ADD COLUMN parent_order_id INT NULL, ADD COLUMN split_group_id VARCHAR(64) NULL");
    $conn->query("ALTER TABLE fat_details ADD COLUMN stock_value DECIMAL(15,4) NOT NULL DEFAULT 0, ADD COLUMN plus DECIMAL(15,4) NOT NULL DEFAULT 0");
    posTakeawayInvoiceSeedFixtures($conn);
    posTakeawayInvoiceSeedRecipe($conn);
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'Recipe Split Table', 0, 0)");

    $server = posTakeawayInvoiceStartServer($root, $db, $sessionDir, $host, $port, $user, $pass);
    $baseUrl = preg_replace('#/do/doadd_invoice\.php$#', '', (string) $server['url']);
    $sessionId = (string) $server['session_id'];
    $savePayload = [
        'table_id' => 1,
        'order_id' => 0,
        'order_date' => '2026-05-25',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 3, 'price' => 10],
        ],
        'total' => 30,
        'discount' => 0,
        'net' => 30,
        'idempotency_key' => 'recipe-split-save-' . getmypid(),
    ];

    $saved = posSplitRecipeEndpointJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $savePayload);
    $savedPayload = json_decode((string) ($saved['body'] ?? ''), true);
    posSplitRecipeEndpointAssert((int) ($saved['status'] ?? 0) === 200 && is_array($savedPayload) && ($savedPayload['success'] ?? false) === true, 'table save setup should succeed: ' . (string) ($saved['body'] ?? ''));
    $orderId = (int) ($savedPayload['order_id'] ?? 0);
    posSplitRecipeEndpointAssert($orderId > 0, 'table save setup should return order id');
    $detailId = (int) $conn->query("SELECT id FROM fat_details WHERE fatid = {$orderId} AND item_id = 10 AND isdeleted = 0 ORDER BY id LIMIT 1")->fetch_assoc()['id'];
    posSplitRecipeEndpointAssert($detailId > 0, 'saved order should have one splittable detail row');
    posSplitRecipeEndpointAssertReserved($conn, $orderId, '3.000000');

    $splitPayload = [
        'table_id' => 1,
        'order_id' => $orderId,
        'items' => [
            ['detail_id' => $detailId, 'qty' => 1],
        ],
        'paid_amount' => 10,
        'payment_method' => 'cash',
        'idempotency_key' => 'recipe-split-endpoint-' . getmypid(),
    ];
    $split = posSplitRecipeEndpointJsonPost($baseUrl . '/ajax/process_split_payment.php', $sessionId, $splitPayload);
    $splitPayloadDecoded = json_decode((string) ($split['body'] ?? ''), true);
    posSplitRecipeEndpointAssert((int) ($split['status'] ?? 0) === 200, 'split endpoint should return HTTP 200');
    posSplitRecipeEndpointAssert(is_array($splitPayloadDecoded), 'split endpoint should return JSON');
    posSplitRecipeEndpointAssert(($splitPayloadDecoded['success'] ?? false) === true, 'split endpoint should succeed: ' . json_encode($splitPayloadDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posSplitRecipeEndpointAssert(($splitPayloadDecoded['code'] ?? '') === 'OK', 'split endpoint should return OK');
    $childOrderId = (int) ($splitPayloadDecoded['new_invoice_id'] ?? 0);
    posSplitRecipeEndpointAssert($childOrderId > $orderId, 'split endpoint should return paid child order id');
    posSplitRecipeEndpointAssert(abs((float) ($splitPayloadDecoded['remaining_total'] ?? 0) - 20.0) < 0.0001, 'split endpoint should return remaining total');

    $replay = posSplitRecipeEndpointJsonPost($baseUrl . '/ajax/process_split_payment.php', $sessionId, $splitPayload);
    $replayPayload = json_decode((string) ($replay['body'] ?? ''), true);
    posSplitRecipeEndpointAssert((int) ($replay['status'] ?? 0) === 200 && is_array($replayPayload), 'split replay should return JSON');
    posSplitRecipeEndpointAssert(($replayPayload['success'] ?? false) === true, 'split replay should succeed');
    posSplitRecipeEndpointAssert((int) ($replayPayload['new_invoice_id'] ?? 0) === $childOrderId, 'split replay should return original child order id');
    posSplitRecipeEndpointAssert(($replayPayload['request_id'] ?? '') === $splitPayload['idempotency_key'], 'split replay should return original request id');

    posSplitRecipeEndpointAssertSplitRows($conn, $orderId, $childOrderId, $splitPayload['idempotency_key']);

    echo "pos-split-payment-recipe-endpoint-runtime-ok db={$db}\n";
} finally {
    if (is_array($server)) {
        posTakeawayInvoiceStopServer($server);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    posTakeawayInvoiceRemoveDir($sessionDir);
}

function posSplitRecipeEndpointJsonPost(string $url, string $sessionId, array $payload): array
{
    $csrf = 'takeaway-http-csrf-fixed';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Cookie: PHPSESSID=' . $sessionId,
                'X-CSRF-Token: ' . $csrf,
                'X-POSMAIN-CSRF-Token: ' . $csrf,
                'X-Requested-With: XMLHttpRequest',
            ]) . "\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return [
        'status' => $status,
        'body' => is_string($raw) ? $raw : '',
    ];
}

function posSplitRecipeEndpointAssertReserved(mysqli $conn, int $orderId, string $expectedQty): void
{
    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posSplitRecipeEndpointAssert(count($usages) === 1, 'saved table order should create one recipe usage before split');
    posSplitRecipeEndpointAssert((string) $usages[0]['status'] === 'reserved', 'saved table order should be reserved before split');
    posSplitRecipeEndpointAssert((string) $usages[0]['order_qty'] === $expectedQty, 'saved table order should reserve the expected recipe quantity before split');
    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posSplitRecipeEndpointAssert(is_array($balance), 'ingredient balance should exist before split');
    posSplitRecipeEndpointAssert((string) $balance['qty_on_hand'] === '10.000000', 'reservation should not reduce on-hand stock before split');
    posSplitRecipeEndpointAssert((string) $balance['qty_reserved'] === $expectedQty, 'reservation should reserve expected ingredient units before split');
}

function posSplitRecipeEndpointAssertSplitRows(mysqli $conn, int $orderId, int $childOrderId, string $idempotencyKey): void
{
    $original = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
    $child = $conn->query("SELECT * FROM ot_head WHERE id = {$childOrderId}")->fetch_assoc();
    posSplitRecipeEndpointAssert(is_array($original) && is_array($child), 'split should keep original and child orders');
    posSplitRecipeEndpointAssert((string) $original['order_status'] === 'active', 'original order should stay active after partial split');
    posSplitRecipeEndpointAssert((string) $original['payment_status'] === 'unpaid', 'original order should remain unpaid after partial split');
    posSplitRecipeEndpointAssert((string) $child['order_status'] === 'completed', 'split child order should be completed');
    posSplitRecipeEndpointAssert((string) $child['payment_status'] === 'paid', 'split child order should be paid');
    posSplitRecipeEndpointAssert((int) $child['parent_order_id'] === $orderId, 'split child should link to original order');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'table should remain occupied for remaining original order');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_payments WHERE order_id = {$childOrderId}")->fetch_assoc()['c'] === 1, 'split replay should not create a second child payment row');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.payment.split' AND idempotency_key = '{$idempotencyKey}' AND status = 'completed'")->fetch_assoc()['c'] === 1, 'split replay should keep one completed idempotency row');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = {$orderId} AND event_type = 'order.updated'")->fetch_assoc()['c'] === 1, 'split replay should not duplicate original order outbox row');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = {$childOrderId} AND event_type = 'order.split_paid'")->fetch_assoc()['c'] === 1, 'split replay should not duplicate split child outbox row');

    $originalUsages = $conn->query("SELECT status, order_qty FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $childUsages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$childOrderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posSplitRecipeEndpointAssert(array_column($originalUsages, 'status') === ['released', 'reserved'], 'split should release old original reservation and reserve remaining quantity');
    posSplitRecipeEndpointAssert(array_column($originalUsages, 'order_qty') === ['3.000000', '2.000000'], 'original recipe usage quantities should track old and remaining split quantities');
    posSplitRecipeEndpointAssert(count($childUsages) === 1 && (string) $childUsages[0]['status'] === 'consumed', 'split child should have one consumed recipe usage');
    posSplitRecipeEndpointAssert((string) $childUsages[0]['order_qty'] === '1.000000', 'split child should consume only the paid split quantity');

    $movementTypes = array_column($conn->query("SELECT movement_type FROM inventory_movements WHERE order_id IN ({$orderId}, {$childOrderId}) ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'movement_type');
    posSplitRecipeEndpointAssert(in_array('reservation', $movementTypes, true), 'split fixture should include reservation movement');
    posSplitRecipeEndpointAssert(in_array('reservation_release', $movementTypes, true), 'split should record reservation release movement');
    posSplitRecipeEndpointAssert(in_array('recipe_consumption', $movementTypes, true), 'split child payment should record recipe consumption');
    posSplitRecipeEndpointAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE order_id = {$childOrderId} AND movement_type = 'recipe_consumption'")->fetch_assoc()['c'] === 1, 'split replay should not duplicate child recipe consumption');
    $consumedQty = $conn->query("SELECT COALESCE(SUM(qty_out), 0) AS qty FROM inventory_movements WHERE item_id = 12 AND movement_type = 'recipe_consumption'")->fetch_assoc();
    posSplitRecipeEndpointAssert((string) $consumedQty['qty'] === '1.000000', 'split endpoint should consume only one paid ingredient unit');

    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posSplitRecipeEndpointAssert(is_array($balance), 'ingredient balance should exist after split');
    posSplitRecipeEndpointAssert((string) $balance['qty_on_hand'] === '9.000000', 'split endpoint should deduct only the paid split ingredient quantity');
    posSplitRecipeEndpointAssert((string) $balance['qty_reserved'] === '2.000000', 'split endpoint should leave remaining original quantity reserved');
}

function posSplitRecipeEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
