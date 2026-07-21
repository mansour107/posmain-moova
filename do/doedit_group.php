<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doedit_group.php');

$id = (int) ($_GET['id'] ?? 0);
$gname = trim((string) ($_POST['gname'] ?? ''));
$returnTo = 'mygroups.php';

if ($id < 1 || $gname === '') {
    header('location:../' . $returnTo);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM item_group WHERE gname = ? AND isdeleted = 0 AND id != ? LIMIT 1");
$stmt->bind_param("si", $gname, $id);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($exists) {
    header('location:../' . $returnTo . '?error=duplicate');
    exit;
}

$stmt = $conn->prepare("UPDATE item_group SET gname = ? WHERE id = ?");
$stmt->bind_param("si", $gname, $id);
$stmt->execute();
$stmt->close();

require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';
posmain_record_operational_row_sync($conn, 'item_category', $id, 'item_group_form');

if (function_exists('posmain_app_config') && !empty(posmain_app_config()['features']['preparation_fields'])) {
    require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';
    $preparationConfigId = (new PreparationSelectionService())->setCategorySugarAllowed(
        $conn,
        $id,
        !empty($_POST['sugar_spoons_enabled']),
        current_user_id()
    );
    if ($preparationConfigId > 0) {
        posmain_record_operational_row_sync($conn, 'item_group_preparation_config', $preparationConfigId, 'item_group_form');
    }

    require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';
    $items = $conn->prepare('SELECT id FROM myitems WHERE group1 = ? AND COALESCE(isdeleted, 0) = 0');
    $items->bind_param('i', $id);
    $items->execute();
    $result = $items->get_result();
    while ($item = $result->fetch_assoc()) {
        posmain_record_menu_item_sync($conn, (int) $item['id'], 'item_group_form');
    }
    $items->close();
}

header('location:../' . $returnTo);
