<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_group.php');

$id = (int) ($_GET['id'] ?? 0);
$returnTo = 'mygroups.php';

if ($id > 0) {
    $stmt = $conn->prepare("UPDATE item_group SET isdeleted = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header('location:../' . $returnTo);
