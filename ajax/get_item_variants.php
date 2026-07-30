<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/get_item_variants.php');
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';

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
    $variants = $service->variantsForParent($conn, $itemId, true);
    $variants = (new PreparationSelectionService())->decorateItems($conn, $variants);
    echo json_encode([
        'success' => true,
        'item_id' => $itemId,
        'variants' => $variants,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'variant_lookup_failed',
        'variants' => [],
    ], JSON_UNESCAPED_UNICODE);
}
