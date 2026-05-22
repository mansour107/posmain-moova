<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$itemId = (int) ($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
if ($itemId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'invalid_item_id',
        'variants' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new ItemVariantService();
    echo json_encode([
        'success' => true,
        'item_id' => $itemId,
        'variants' => $service->variantsForParent($conn, $itemId, true),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'variant_lookup_failed',
        'variants' => [],
    ], JSON_UNESCAPED_UNICODE);
}
