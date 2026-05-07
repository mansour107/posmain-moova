<?php
include('includes/connect.php');
session_start();

echo "<h1>Debug Info</h1>";
echo "<h2>Session</h2>";
echo "User ID: " . ($_SESSION['userid'] ?? 'Not Set') . "<br>";
echo "User Type (usty): " . ($_SESSION['usty'] ?? 'Not Set') . "<br>";
echo "Login: " . ($_SESSION['login'] ?? 'Not Set') . "<br>";

echo "<h2>Last 5 Tasks</h2>";
$sql = "SELECT id, name, user, isdeleted, crtime FROM tasks ORDER BY id DESC LIMIT 5";
$res = $conn->query($sql);

if ($res) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>User ID</th><th>Is Deleted</th><th>Created Time</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['name']}</td>";
        echo "<td>{$row['user']}</td>";
        echo "<td>" . (is_null($row['isdeleted']) ? 'NULL' : $row['isdeleted']) . "</td>";
        echo "<td>{$row['crtime']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "Query Failed: " . $conn->error;
}
?>
