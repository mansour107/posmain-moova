<?php
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
