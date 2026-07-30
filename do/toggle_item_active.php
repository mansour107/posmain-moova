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

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT id FROM myitems WHERE id = ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        throw new RuntimeException('ITEM_NOT_FOUND');
    }

    $stmt = $conn->prepare('UPDATE myitems SET is_active = ? WHERE id = ?');
    $stmt->bind_param('ii', $active, $id);
    $stmt->execute();
    $stmt->close();
    posmain_record_menu_item_sync(
        $conn,
        $id,
        'item_status_toggle',
        'menu.item_saved',
        true
    );
    $conn->commit();
    $ok = true;
} catch (Throwable $exception) {
    $conn->rollback();
    $ok = false;
    if (function_exists('posmain_log_exception') && function_exists('posmain_error_reference')) {
        posmain_log_exception($exception, posmain_error_reference(), 'item_status_toggle');
    }
}

header('location:../myitems.php?active=' . ($ok ? ($active ? 'enabled' : 'disabled') : 'fail'));
exit;
