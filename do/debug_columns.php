<?php
require_once __DIR__ . '/../includes/production_guard.php';
production_guard_deny_route('do/debug_columns.php');

header('Content-Type: application/json');
include('../includes/connect.php');

$columns = [];
$res = $conn->query("SHOW COLUMNS FROM ot_head");
if ($res) {
    while($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
}
echo json_encode(['columns' => $columns]);
?>
