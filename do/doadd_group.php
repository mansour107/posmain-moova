<?php
include('../includes/connect.php');

$gname = trim((string) ($_POST['gname'] ?? ''));
$returnTo = ($_GET['return_to'] ?? '') === 'item_categories' ? 'item_categories.php' : 'mygroups.php';

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
$stmt->close();

$conn->query("INSERT INTO `process`(`type`) VALUES ('add group')");

header('location:../' . $returnTo);
