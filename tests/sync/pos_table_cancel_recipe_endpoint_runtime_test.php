<?php

require_once __DIR__ . '/pos_takeaway_invoice_handler_test.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_cancel_recipe_' . getmypid();
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/posmain-table-cancel-recipe-session-' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-table-cancel-recipe-endpoint-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = null;

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    $conn->query("ALTER TABLE ot_head ADD COLUMN cancelled_at DATETIME NULL, ADD COLUMN cancelled_by INT NULL, ADD COLUMN cancellation_reason VARCHAR(255) NULL");
    posTakeawayInvoiceSeedFixtures($conn);
    posTakeawayInvoiceSeedRecipe($conn);
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'Recipe Cancel Table', 0, 0)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (2, 'Recipe Clear Table', 0, 0)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (3, 'Recipe Status Clear Table', 0, 0)");

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
            ['id' => 10, 'qty' => 2, 'price' => 10],
        ],
        'total' => 20,
        'discount' => 0,
        'net' => 20,
        'idempotency_key' => 'recipe-table-cancel-save-' . getmypid(),
    ];

    $saved = posTableCancelRecipeJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $savePayload);
    $savedPayload = json_decode((string) ($saved['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($saved['status'] ?? 0) === 200 && is_array($savedPayload) && ($savedPayload['success'] ?? false) === true, 'table save setup should succeed: ' . (string) ($saved['body'] ?? ''));
    $orderId = (int) ($savedPayload['order_id'] ?? 0);
    posTableCancelRecipeAssert($orderId > 0, 'table save setup should return order id');
    $mutationVersion = (int) (($savedPayload['updated_state']['mutation_version'] ?? $savedPayload['mutation_version'] ?? 0));
    posTableCancelRecipeAssert($mutationVersion > 0, 'table save setup should return mutation version');
    posTableCancelRecipeAssertReserved($conn, $orderId);

    $cancelPayload = [
        'table_id' => 1,
        'order_id' => $orderId,
        'mutation_version' => $mutationVersion,
        'reason' => 'recipe endpoint cancel smoke',
        'idempotency_key' => 'recipe-table-cancel-endpoint-' . getmypid(),
    ];
    $cancelled = posTableCancelRecipeFormPost($baseUrl . '/ajax/delete_order.php', $sessionId, $cancelPayload);
    $cancelledPayload = json_decode((string) ($cancelled['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($cancelled['status'] ?? 0) === 200, 'delete_order endpoint should return HTTP 200');
    posTableCancelRecipeAssert(is_array($cancelledPayload), 'delete_order endpoint should return JSON');
    posTableCancelRecipeAssert(($cancelledPayload['success'] ?? false) === true, 'delete_order endpoint should succeed: ' . json_encode($cancelledPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posTableCancelRecipeAssert(($cancelledPayload['code'] ?? '') === 'OK', 'delete_order endpoint should return OK');
    posTableCancelRecipeAssert((int) ($cancelledPayload['order_id'] ?? 0) === $orderId, 'delete_order endpoint should return cancelled order id');

    $replay = posTableCancelRecipeFormPost($baseUrl . '/ajax/delete_order.php', $sessionId, $cancelPayload);
    $replayPayload = json_decode((string) ($replay['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($replay['status'] ?? 0) === 200 && is_array($replayPayload), 'delete_order replay should return JSON');
    posTableCancelRecipeAssert(($replayPayload['success'] ?? false) === true, 'delete_order replay should succeed');
    posTableCancelRecipeAssert((int) ($replayPayload['order_id'] ?? 0) === $orderId, 'delete_order replay should return original order id');
    posTableCancelRecipeAssert(($replayPayload['request_id'] ?? '') === $cancelPayload['idempotency_key'], 'delete_order replay should return original request id');

    posTableCancelRecipeAssertReleased($conn, $orderId, $cancelPayload['idempotency_key'], 1);

    $clearSavePayload = $savePayload;
    $clearSavePayload['table_id'] = 2;
    $clearSavePayload['idempotency_key'] = 'recipe-table-clear-save-' . getmypid();
    $clearSaved = posTableCancelRecipeJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $clearSavePayload);
    $clearSavedPayload = json_decode((string) ($clearSaved['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($clearSaved['status'] ?? 0) === 200 && is_array($clearSavedPayload) && ($clearSavedPayload['success'] ?? false) === true, 'table clear setup save should succeed: ' . (string) ($clearSaved['body'] ?? ''));
    $clearOrderId = (int) ($clearSavedPayload['order_id'] ?? 0);
    posTableCancelRecipeAssert($clearOrderId > 0, 'table clear setup should return order id');
    $clearMutationVersion = (int) (($clearSavedPayload['updated_state']['mutation_version'] ?? $clearSavedPayload['mutation_version'] ?? 0));
    posTableCancelRecipeAssert($clearMutationVersion > 0, 'table clear setup should return mutation version');
    posTableCancelRecipeAssertReserved($conn, $clearOrderId);

    $clearPayload = [
        'table_id' => 2,
        'order_id' => $clearOrderId,
        'mutation_version' => $clearMutationVersion,
        'reason' => 'recipe endpoint clear smoke',
        'idempotency_key' => 'recipe-table-clear-endpoint-' . getmypid(),
    ];
    $cleared = posTableCancelRecipeFormPost($baseUrl . '/ajax/clear_table.php', $sessionId, $clearPayload);
    $clearedPayload = json_decode((string) ($cleared['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($cleared['status'] ?? 0) === 200, 'clear_table endpoint should return HTTP 200');
    posTableCancelRecipeAssert(is_array($clearedPayload), 'clear_table endpoint should return JSON');
    posTableCancelRecipeAssert(($clearedPayload['success'] ?? false) === true, 'clear_table endpoint should succeed: ' . json_encode($clearedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posTableCancelRecipeAssert(($clearedPayload['code'] ?? '') === 'OK', 'clear_table endpoint should return OK');
    posTableCancelRecipeAssert((int) ($clearedPayload['order_id'] ?? 0) === $clearOrderId, 'clear_table endpoint should resolve the active order id');

    $clearReplay = posTableCancelRecipeFormPost($baseUrl . '/ajax/clear_table.php', $sessionId, $clearPayload);
    $clearReplayPayload = json_decode((string) ($clearReplay['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($clearReplay['status'] ?? 0) === 200 && is_array($clearReplayPayload), 'clear_table replay should return JSON');
    posTableCancelRecipeAssert(($clearReplayPayload['success'] ?? false) === true, 'clear_table replay should succeed');
    posTableCancelRecipeAssert((int) ($clearReplayPayload['order_id'] ?? 0) === $clearOrderId, 'clear_table replay should return original order id');
    posTableCancelRecipeAssert(($clearReplayPayload['request_id'] ?? '') === $clearPayload['idempotency_key'], 'clear_table replay should return original request id');

    posTableCancelRecipeAssertReleased($conn, $clearOrderId, $clearPayload['idempotency_key'], 2);

    $statusSavePayload = $savePayload;
    $statusSavePayload['table_id'] = 3;
    $statusSavePayload['idempotency_key'] = 'recipe-table-status-clear-save-' . getmypid();
    $statusSaved = posTableCancelRecipeJsonPost($baseUrl . '/ajax/save_order.php', $sessionId, $statusSavePayload);
    $statusSavedPayload = json_decode((string) ($statusSaved['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($statusSaved['status'] ?? 0) === 200 && is_array($statusSavedPayload) && ($statusSavedPayload['success'] ?? false) === true, 'table status setup save should succeed: ' . (string) ($statusSaved['body'] ?? ''));
    $statusOrderId = (int) ($statusSavedPayload['order_id'] ?? 0);
    posTableCancelRecipeAssert($statusOrderId > 0, 'table status setup should return order id');
    $statusMutationVersion = (int) (($statusSavedPayload['updated_state']['mutation_version'] ?? $statusSavedPayload['mutation_version'] ?? 0));
    posTableCancelRecipeAssert($statusMutationVersion > 0, 'table status setup should return mutation version');
    posTableCancelRecipeAssertReserved($conn, $statusOrderId);

    $statusPayload = [
        'table_id' => 3,
        'order_id' => $statusOrderId,
        'mutation_version' => $statusMutationVersion,
        'action' => 'clear',
        'reason' => 'recipe endpoint status clear smoke',
        'idempotency_key' => 'recipe-table-status-clear-endpoint-' . getmypid(),
    ];
    $statusCleared = posTableCancelRecipeFormPost($baseUrl . '/ajax/update_table_status.php', $sessionId, $statusPayload);
    $statusClearedPayload = json_decode((string) ($statusCleared['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($statusCleared['status'] ?? 0) === 200, 'update_table_status clear endpoint should return HTTP 200');
    posTableCancelRecipeAssert(is_array($statusClearedPayload), 'update_table_status clear endpoint should return JSON');
    posTableCancelRecipeAssert(($statusClearedPayload['success'] ?? false) === true, 'update_table_status clear endpoint should succeed: ' . json_encode($statusClearedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    posTableCancelRecipeAssert(($statusClearedPayload['code'] ?? '') === 'OK', 'update_table_status clear endpoint should return OK');
    posTableCancelRecipeAssert((int) ($statusClearedPayload['order_id'] ?? 0) === $statusOrderId, 'update_table_status clear endpoint should resolve the active order id');

    $statusReplay = posTableCancelRecipeFormPost($baseUrl . '/ajax/update_table_status.php', $sessionId, $statusPayload);
    $statusReplayPayload = json_decode((string) ($statusReplay['body'] ?? ''), true);
    posTableCancelRecipeAssert((int) ($statusReplay['status'] ?? 0) === 200 && is_array($statusReplayPayload), 'update_table_status replay should return JSON');
    posTableCancelRecipeAssert(($statusReplayPayload['success'] ?? false) === true, 'update_table_status replay should succeed');
    posTableCancelRecipeAssert((int) ($statusReplayPayload['order_id'] ?? 0) === $statusOrderId, 'update_table_status replay should return original order id');
    posTableCancelRecipeAssert(($statusReplayPayload['request_id'] ?? '') === $statusPayload['idempotency_key'], 'update_table_status replay should return original request id');

    posTableCancelRecipeAssertReleased($conn, $statusOrderId, $statusPayload['idempotency_key'], 3);

    echo "pos-table-cancel-recipe-endpoint-runtime-ok db={$db}\n";
} finally {
    if (is_array($server)) {
        posTakeawayInvoiceStopServer($server);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    posTakeawayInvoiceRemoveDir($sessionDir);
}

function posTableCancelRecipeJsonPost(string $url, string $sessionId, array $payload): array
{
    return posTableCancelRecipeHttpPost($url, $sessionId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');
}

function posTableCancelRecipeFormPost(string $url, string $sessionId, array $payload): array
{
    $payload['csrf_token'] = 'takeaway-http-csrf-fixed';
    return posTableCancelRecipeHttpPost($url, $sessionId, http_build_query($payload), 'application/x-www-form-urlencoded');
}

function posTableCancelRecipeHttpPost(string $url, string $sessionId, string $body, string $contentType): array
{
    $csrf = 'takeaway-http-csrf-fixed';
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: ' . $contentType,
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

function posTableCancelRecipeAssertReserved(mysqli $conn, int $orderId): void
{
    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableCancelRecipeAssert(count($usages) === 1, 'saved table order should create one recipe usage before cancel');
    posTableCancelRecipeAssert((string) $usages[0]['status'] === 'reserved', 'saved table order should be reserved before cancel');
    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posTableCancelRecipeAssert((string) $balance['qty_on_hand'] === '10.000000', 'reservation should not reduce on-hand stock before cancel');
    posTableCancelRecipeAssert((string) $balance['qty_reserved'] === '2.000000', 'reservation should reserve two ingredient units before cancel');
}

function posTableCancelRecipeAssertReleased(mysqli $conn, int $orderId, string $idempotencyKey, int $tableId): void
{
    $order = $conn->query("SELECT * FROM ot_head WHERE id = {$orderId}")->fetch_assoc();
    posTableCancelRecipeAssert(is_array($order), 'cancelled order should still exist for audit');
    posTableCancelRecipeAssert((string) $order['order_status'] === 'cancelled', 'cancel endpoint should mark order cancelled');
    posTableCancelRecipeAssert((string) $order['invoice_status'] === 'cancelled', 'cancel endpoint should mark invoice cancelled');
    posTableCancelRecipeAssert((string) $order['payment_status'] === 'voided', 'cancel endpoint should mark unpaid order voided');
    posTableCancelRecipeAssert((int) $order['isdeleted'] === 1, 'cancel endpoint should hide order from active lists');
    posTableCancelRecipeAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = {$tableId}")->fetch_assoc()['table_case'] === 0, 'cancel endpoint should release the table');
    posTableCancelRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$orderId} AND isdeleted = 0")->fetch_assoc()['c'] === 0, 'cancel endpoint should soft-delete order lines');
    posTableCancelRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = {$orderId} AND event_type = 'order.cancelled'")->fetch_assoc()['c'] === 1, 'cancel replay should not duplicate order events');
    posTableCancelRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_local_id = {$orderId} AND event_type = 'order.cancelled'")->fetch_assoc()['c'] === 1, 'cancel replay should not duplicate order outbox rows');
    posTableCancelRecipeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.order.cancel' AND idempotency_key = '{$idempotencyKey}' AND status = 'completed'")->fetch_assoc()['c'] === 1, 'cancel replay should keep one completed idempotency row');

    $usages = $conn->query("SELECT * FROM recipe_order_line_usage WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    posTableCancelRecipeAssert(count($usages) === 1, 'cancelled table order should keep one recipe usage row');
    posTableCancelRecipeAssert((string) $usages[0]['status'] === 'released', 'cancel endpoint should release reserved recipe usage');
    $movementTypes = array_column($conn->query("SELECT movement_type FROM inventory_movements WHERE order_id = {$orderId} ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'movement_type');
    posTableCancelRecipeAssert(in_array('reservation', $movementTypes, true), 'cancel fixture should include original reservation movement');
    posTableCancelRecipeAssert(in_array('reservation_release', $movementTypes, true), 'cancel endpoint should record reservation release movement');
    posTableCancelRecipeAssert(!in_array('recipe_consumption', $movementTypes, true), 'cancelled unpaid table order should not consume ingredients');

    $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 12);
    posTableCancelRecipeAssert(is_array($balance), 'ingredient balance should exist after cancel');
    posTableCancelRecipeAssert((string) $balance['qty_on_hand'] === '10.000000', 'cancel endpoint should not reduce on-hand ingredient stock');
    posTableCancelRecipeAssert((string) $balance['qty_reserved'] === '0.000000', 'cancel endpoint should clear reserved ingredient stock');
}

function posTableCancelRecipeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
