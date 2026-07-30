<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/update_item_price.php');

require_once __DIR__ . '/../classes/Financial/UnitPrice.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$itemId = (int) ($_POST['item_id'] ?? 0);
$priceField = trim((string) ($_POST['price_field'] ?? ''));
$rawPrice = $_POST['new_price'] ?? null;
$allowedFields = [
    'price1' => 'price1',
    'cost_price' => 'cost_price',
    'market_price' => 'market_price',
    'last_price' => 'last_price',
];

try {
    if ($itemId < 1 || !isset($allowedFields[$priceField])) {
        throw new InvalidArgumentException('ITEM_PRICE_INPUT_INVALID');
    }
    $canonicalPrice = UnitPrice::from($rawPrice)->toString();
    $column = $allowedFields[$priceField];

    $conn->begin_transaction();
    $existsStmt = $conn->prepare('SELECT id FROM myitems WHERE id = ? LIMIT 1 FOR UPDATE');
    $existsStmt->bind_param('i', $itemId);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()->fetch_assoc();
    $existsStmt->close();
    if (!$exists) {
        throw new RuntimeException('ITEM_NOT_FOUND');
    }

    $stmt = $conn->prepare(
        "UPDATE myitems
         SET `{$column}` = ?, manual_price_edit = 1
         WHERE id = ?"
    );
    $stmt->bind_param('si', $canonicalPrice, $itemId);
    $stmt->execute();
    $stmt->close();

    posmain_record_menu_item_sync(
        $conn,
        $itemId,
        'item_price_update',
        'menu.item_saved',
        true
    );
    $conn->commit();

    echo json_encode([
        'success' => true,
        'item_id' => $itemId,
        'price_field' => $priceField,
        'value' => $canonicalPrice,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->rollback();
    }
    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'item_price_update');
    }
    http_response_code($exception->getMessage() === 'ITEM_NOT_FOUND' ? 404 : 503);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage() === 'ITEM_NOT_FOUND'
            ? 'ITEM_NOT_FOUND'
            : 'ITEM_PRICE_UPDATE_FAILED',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
