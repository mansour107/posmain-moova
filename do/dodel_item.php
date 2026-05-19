<?php
include('../includes/connect.php');
require_once('../classes/Sync/SyncOutboxEventService.php');

$password = $_POST['password'];
$srvrpass = $rowstg['edit_pass'];
if ($password == $rowstg['edit_pass']) { 
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $conn->prepare('UPDATE myitems SET isdeleted = 1 WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $conn->query('UPDATE myitems SET isdeleted = 1 WHERE id = ' . $id);
    }

    try {
        SyncOutboxEventService::recordMenuItemSnapshot($conn, $id, array(
            'event_type' => 'menu.item_saved',
            'source_system' => 'item_delete',
        ));
    } catch (Throwable $e) {
        error_log('[Moova Sync] Failed to record deleted item snapshot: ' . $e->getMessage());
    }
}
header('location:../myitems.php');
}else{
header("location:../myitems.php?pass='$password'&srvr='$srvrpass'");
}
