<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/toggle_item_active.php');

require_once __DIR__ . '/../classes/Items/ItemCatalogStatus.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';

$id = (int) ($_POST['id'] ?? 0);
$active = (int) ($_POST['active'] ?? 1) === 1 ? 1 : 0;

if ($id < 1) {
    header('location:../myitems.php?active=invalid');
    exit;
}

if (!ItemCatalogStatus::hasActiveColumn($conn)) {
    header('location:../myitems.php?active=missing');
    exit;
}

$stmt = $conn->prepare('UPDATE myitems SET is_active = ? WHERE id = ? AND COALESCE(isdeleted, 0) = 0');
if (!$stmt) {
    header('location:../myitems.php?active=fail');
    exit;
}

$stmt->bind_param('ii', $active, $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    posmain_record_menu_item_sync($conn, $id, 'item_status_toggle');
}

header('location:../myitems.php?active=' . ($ok ? ($active ? 'enabled' : 'disabled') : 'fail'));
exit;
