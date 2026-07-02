<?php
include('../includes/connect.php');

$gname = trim((string) ($_POST['gname'] ?? ''));
$returnTo = 'mygroups.php';

if ($gname === '') {
    header('location:../' . $returnTo);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM item_group WHERE gname = ? AND isdeleted = 0 LIMIT 1");
$stmt->bind_param("s", $gname);
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

if ($exists) {
    header('location:../' . $returnTo . '?error=duplicate');
    exit;
}

$stmt = $conn->prepare("INSERT INTO item_group (gname) VALUES (?)");
$stmt->bind_param("s", $gname);
$stmt->execute();
$groupId = (int) $stmt->insert_id;
$stmt->close();

require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';
if ($groupId > 0) {
    posmain_record_operational_row_sync($conn, 'item_category', $groupId, 'item_group_form');
}

$conn->query("INSERT INTO `process`(`type`) VALUES ('add group')");

header('location:../' . $returnTo);
