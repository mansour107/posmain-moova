<?php

require_once __DIR__ . '/pos_takeaway_invoice_handler_test.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_save_recipe_' . getmypid();
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/posmain-table-save-recipe-session-' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-table-save-recipe-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = null;

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    posTakeawayInvoiceSeedFixtures($conn);
    posTakeawayInvoiceSeedRecipe($conn);
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'Recipe Save Table', 0, 0)");

    $server = posTakeawayInvoiceStartServer($root, $db, $sessionDir, $host, $port, $user, $pass);
    $baseUrl = preg_replace('#/do/doadd_invoice\.php$#', '', (string) $server['url']);
    $sessionId = (string) $server['session_id'];
    $payload = [
        'table_id' => 1,
        'order_id' => 0,
        'order_date' => '2026-05-25',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 10],
        ],
        'total' => 20,
        'discount' => 0,
        'net' => 20,
        'idempotency_key' => 'recipe-table-save-endpoint-' . getmypid(),
    ];

    $saved = posTableSaveRecipeEndpointJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $payload);
    posTableSaveRecipeAssert((int) ($saved['status'] ?? 0) === 200, 'table save endpoint should return HTTP 200');
    $savedPayload = json_decode((string) ($saved['body'] ?? ''), true);
    posTableSaveRecipeAssert(is_array($savedPayload), 'table save endpoint should return JSON');
    posTableSaveRecipeAssert(($savedPayload['success'] ?? false) === true, 'table save endpoint should succeed: ' . json_encode($savedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posTableSaveRecipeAssert(($savedPayload['code'] ?? '') === 'OK', 'table save endpoint should return OK');
    posTableSaveRecipeAssert(($savedPayload['request_id'] ?? '') === $payload['idempotency_key'], 'table save endpoint should return request id');
    $orderId = (int) ($savedPayload['order_id'] ?? 0);
    posTableSaveRecipeAssert($orderId > 0, 'table save endpoint should return order id');
    $firstKitchenRevision = (int) (($savedPayload['updated_state']['kitchen_revision'] ?? $savedPayload['kitchen_revision'] ?? 0));
    posTableSaveRecipeAssert($firstKitchenRevision >= 1, 'table save endpoint should return kitchen_revision');
    $firstMutationVersion = (int) (($savedPayload['updated_state']['mutation_version'] ?? $savedPayload['mutation_version'] ?? 0));
    posTableSaveRecipeAssert($firstMutationVersion >= 1, 'table save endpoint should return mutation_version');

    $replay = posTableSaveRecipeEndpointJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $payload);
    posTableSaveRecipeAssert((int) ($replay['status'] ?? 0) === 200, 'table save endpoint replay should return HTTP 200');
    $replayPayload = json_decode((string) ($replay['body'] ?? ''), true);
    posTableSaveRecipeAssert(is_array($replayPayload), 'table save replay should return JSON');
    posTableSaveRecipeAssert(($replayPayload['success'] ?? false) === true, 'table save endpoint replay should succeed');
    posTableSaveRecipeAssert((int) ($replayPayload['order_id'] ?? 0) === $orderId, 'table save endpoint replay should return original order id');
    posTableSaveRecipeAssert(($replayPayload['request_id'] ?? '') === $payload['idempotency_key'], 'table save endpoint replay should return original request id');

    posTableSaveRecipeAssertEndpointRows($conn, $orderId, $payload['idempotency_key']);

    $updatePayload = $payload;
    $updatePayload['order_id'] = $orderId;
    $updatePayload['mutation_version'] = $firstMutationVersion;
    $updatePayload['items'] = [
        ['id' => 10, 'qty' => 1, 'price' => 10],
    ];
    $updatePayload['total'] = 10;
    $updatePayload['net'] = 10;
    $updatePayload['idempotency_key'] = 'recipe-table-save-update-' . getmypid();

    $updated = posTableSaveRecipeEndpointJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $updatePayload);
    $updatedPayload = json_decode((string) ($updated['body'] ?? ''), true);
    posTableSaveRecipeAssert(
        ($updatedPayload['success'] ?? false) === true,
        'table save update should succeed: ' . json_encode($updatedPayload, JSON_UNESCAPED_SLASHES)
    );
    posTableSaveRecipeAssert((int) ($updatedPayload['order_id'] ?? 0) === $orderId, 'table save update should keep same order id');
    $secondKitchenRevision = (int) (($updatedPayload['updated_state']['kitchen_revision'] ?? 0));
    posTableSaveRecipeAssert($secondKitchenRevision > $firstKitchenRevision, 'table save update should bump kitchen_revision');

    $detailCount = (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'];
    posTableSaveRecipeAssert($detailCount === 1, 'table save update should replace detail rows');

    echo "pos-table-save-recipe-endpoint-runtime-ok db={$db}\n";
} finally {
    if (is_array($server)) {
        posTakeawayInvoiceStopServer($server);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    posTakeawayInvoiceRemoveDir($sessionDir);
}

function posTableSaveRecipeEndpointJsonPost(string $url, string $sessionId, array $payload): array
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

function posTableSaveRecipeAssertEndpointRows(mysqli $conn, int $orderId, string $idempotencyKey): void
{
    $order = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
    posTableSaveRecipeAssert(is_array($order), 'saved table order should exist');
    posTableSaveRecipeAssert((int) $order['table_id'] === 1, 'saved table order should belong to table 1');
    posTableSaveRecipeAssert((string) $order['order_type'] === 'table', 'saved table order should persist table order_type');
    posTableSaveRecipeAssert((string) $order['payment_status'] === 'unpaid', 'saved table order should remain unpaid');
    posTableSaveRecipeAssert((string) $order['order_status'] === 'active', 'saved table order should remain active');
    posTableSaveRecipeAssert((string) $order['invoice_status'] === 'draft', 'saved table order should remain draft');
    posTableSaveRecipeAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'saved table order should occupy the table');
    posTableSaveRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'] === 1, 'save replay should not create a second detail row');
    posTableSaveRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = {$orderId} AND event_type = 'order.saved'")->fetch_assoc()['c'] === 1, 'save replay should not duplicate order events');
    posTableSaveRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.table.save' AND idempotency_key = '{$idempotencyKey}' AND status = 'completed'")->fetch_assoc()['c'] === 1, 'save replay should keep one completed idempotency row');

    $outboxRows = (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = {$orderId} AND event_type = 'order.saved'")->fetch_assoc()['c'];
    posTableSaveRecipeAssert($outboxRows === 1, 'save replay should not duplicate order sync outbox rows');

    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableSaveRecipeAssert(count($usages) === 1, 'saved table order should create one recipe usage row');
    posTableSaveRecipeAssert((string) $usages[0]['status'] === 'reserved', 'saved table order recipe usage should be reserved');
    posTableSaveRecipeAssert((int) $usages[0]['sellable_item_id'] === 10, 'table save recipe usage should belong to pilot item');

    $movementTypes = array_column($conn->query("SELECT movement_type FROM inventory_movements WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'movement_type');
    posTableSaveRecipeAssert(in_array('reservation', $movementTypes, true), 'saved table order should record reservation movement');
    posTableSaveRecipeAssert(!in_array('recipe_consumption', $movementTypes, true), 'saved unpaid table order should not consume ingredients');

    require_once __DIR__ . '/../../includes/pos_operational_store.php';
    $operationalStoreId = posmain_operational_store_id($conn);
    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, $operationalStoreId, 12);
    posTableSaveRecipeAssert(is_array($balance), 'ingredient balance should exist after table save');
    posTableSaveRecipeAssert(
        (string) $balance['qty_on_hand'] === '10.000000',
        'table save reservation should not reduce on-hand stock; actual=' . (string) $balance['qty_on_hand']
            . '; balances=' . json_encode(
                $conn->query('SELECT * FROM inventory_item_balances ORDER BY store_id, item_id')->fetch_all(MYSQLI_ASSOC),
                JSON_UNESCAPED_SLASHES
            )
    );
    posTableSaveRecipeAssert(
        (string) $balance['qty_reserved'] === '2.000000',
        'table save should reserve two ingredient units; state=' . json_encode([
            'balance' => $balance,
            'reservations' => $conn->query("SELECT * FROM stock_reservations WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC),
            'movements' => $conn->query("SELECT id, movement_type, qty_in, qty_out, idempotency_key FROM inventory_movements WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC),
        ], JSON_UNESCAPED_SLASHES)
    );
}

function posTableSaveRecipeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
