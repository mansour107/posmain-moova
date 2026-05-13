<?php
include("../includes/connect.php");

$id = (int) ($_GET['id'] ?? 0);
$returnTo = ($_GET['return_to'] ?? '') === 'item_categories' ? 'item_categories.php' : 'mygroups.php';

if ($id > 0) {
    $stmt = $conn->prepare("UPDATE item_group SET isdeleted = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header('location:../' . $returnTo);
