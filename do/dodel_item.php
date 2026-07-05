<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_item.php');

require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('location:../myitems.php');
    exit;
}

$variantService = new ItemVariantService();
$variantService->ensureSchema($conn);

if ($variantService->isVariantChildId($conn, $id)) {
    $deletedItemIds = $variantService->softDeleteVariantChild($conn, $id);
} else {
    $deletedItemIds = $variantService->softDeleteParentAndVariantFamily($conn, $id);
}

foreach ($deletedItemIds as $deletedItemId) {
    posmain_record_menu_item_sync($conn, (int) $deletedItemId, 'item_delete');
}

header('location:../myitems.php');
exit;
