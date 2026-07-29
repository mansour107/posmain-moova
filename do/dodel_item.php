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

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT id FROM myitems WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        throw new RuntimeException('ITEM_NOT_FOUND');
    }

    if ($variantService->isVariantChildId($conn, $id)) {
        $deletedItemIds = $variantService->softDeleteVariantChild($conn, $id);
    } else {
        $deletedItemIds = $variantService->softDeleteParentAndVariantFamily($conn, $id);
    }

    sort($deletedItemIds, SORT_NUMERIC);
    foreach ($deletedItemIds as $deletedItemId) {
        posmain_record_menu_item_sync(
            $conn,
            (int) $deletedItemId,
            'item_delete',
            'menu.item_saved',
            true
        );
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'item_delete');
    }
    header('location:../myitems.php?delete=fail');
    exit;
}

header('location:../myitems.php');
exit;
