<?php 
include('../../includes/connect.php');

$name = $conn->real_escape_string($_GET['q']);
$sql = "SELECT name FROM clients WHERE name LIKE '%$name%' LIMIT 25";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    echo "<option value='" . htmlspecialchars($row['name']) . "'>";
}
?>