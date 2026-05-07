<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
include('includes/db_connection.php');

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if ot_head table exists
$result = $conn->query("SHOW TABLES LIKE 'ot_head'");
if ($result->num_rows === 0) {
    die("Error: ot_head table does not exist");
}

// Check if there are any orders
$result = $conn->query("SELECT COUNT(*) as count FROM ot_head WHERE isdeleted = 0");
$row = $result->fetch_assoc();
$orderCount = $row['count'];

echo "Number of non-deleted orders in ot_head: " . $orderCount . "<br>\n";

// Get the first few orders for inspection
$result = $conn->query("SELECT id, invoice_number, date, total, status, client_id FROM ot_head WHERE isdeleted = 0 ORDER BY id DESC LIMIT 5");

if ($result->num_rows > 0) {
    echo "<h3>Recent Orders:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Invoice #</th><th>Date</th><th>Total</th><th>Status</th><th>Client ID</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['invoice_number'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($row['date']) . "</td>";
        echo "<td>" . htmlspecialchars($row['total']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status'] ?? 'N/A') . "</td>";
        echo "<td>" . ($row['client_id'] ? htmlspecialchars($row['client_id']) : 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No orders found in the database.";
}

// Check if clients table exists
$result = $conn->query("SHOW TABLES LIKE 'clients'");
if ($result->num_rows === 0) {
    echo "<p>Warning: clients table does not exist</p>";
} else {
    $result = $conn->query("SELECT COUNT(*) as count FROM clients");
    $row = $result->fetch_assoc();
    echo "<p>Number of clients: " . $row['count'] . "</p>";
}

$conn->close();
?>
