<?php
include('../includes/connect.php');

$id = (int) ($_GET['id'] ?? 0);
$gname = trim((string) ($_POST['gname'] ?? ''));
$returnTo = ($_GET['return_to'] ?? '') === 'item_categories' ? 'item_categories.php' : 'mygroups.php';

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

header('location:../' . $returnTo);
