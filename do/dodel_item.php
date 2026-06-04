<?php
include('../includes/connect.php');
require_once('../classes/Sync/MenuItemSyncRecorder.php');
require_once('../classes/Pos/Service/ItemVariantService.php');

$password = $_POST['password'];
$srvrpass = $rowstg['edit_pass'];
if ($password == $rowstg['edit_pass']) {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
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
    }
    header('location:../myitems.php');
} else {
    header("location:../myitems.php?pass='$password'&srvr='$srvrpass'");
}
