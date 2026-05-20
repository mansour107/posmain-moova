<?php
include('../includes/connect.php');
require_once('../classes/Sync/MenuItemSyncRecorder.php');

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

    posmain_record_menu_item_sync($conn, $id, 'item_delete');
}
header('location:../myitems.php');
}else{
header("location:../myitems.php?pass='$password'&srvr='$srvrpass'");
}
