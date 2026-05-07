<?php
// Test file to check orders
session_start();
include('../includes/connect.php');

echo "<h2>Testing Orders Query</h2>";

// Check if ot_head table exists
$check_table = $conn->query("SHOW TABLES LIKE 'ot_head'");
if ($check_table && $check_table->num_rows > 0) {
    echo "<p style='color: green;'>✓ Table 'ot_head' exists</p>";
} else {
    echo "<p style='color: red;'>✗ Table 'ot_head' does NOT exist</p>";
    exit;
}

// Count all orders
$count_all = $conn->query("SELECT COUNT(*) as total FROM ot_head");
$total = $count_all->fetch_assoc()['total'];
echo "<p>Total orders in ot_head: <strong>$total</strong></p>";

// Count non-deleted orders
$count_active = $conn->query("SELECT COUNT(*) as total FROM ot_head WHERE isdeleted = 0");
$active = $count_active->fetch_assoc()['total'];
echo "<p>Non-deleted orders: <strong>$active</strong></p>";

// Count POS orders (pro_tybe = 9)
$count_pos = $conn->query("SELECT COUNT(*) as total FROM ot_head WHERE isdeleted = 0 AND pro_tybe = 9");
$pos = $count_pos->fetch_assoc()['total'];
echo "<p>POS orders (pro_tybe = 9): <strong>$pos</strong></p>";

// Show sample of last 10 orders
echo "<h3>Last 10 Orders (any type):</h3>";
$sample = $conn->query("SELECT id, pro_num, pro_date, pro_tybe, isdeleted, closed FROM ot_head ORDER BY id DESC LIMIT 10");

if ($sample && $sample->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Invoice #</th><th>Date</th><th>Type</th><th>Deleted</th><th>Closed</th></tr>";
    while ($row = $sample->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['pro_num']}</td>";
        echo "<td>{$row['pro_date']}</td>";
        echo "<td>{$row['pro_tybe']}</td>";
        echo "<td>{$row['isdeleted']}</td>";
        echo "<td>{$row['closed']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>No orders found!</p>";
}

// Test the exact query used in get_recent_orders.php
echo "<h3>Testing Exact Query from get_recent_orders.php:</h3>";
$query = "SELECT 
            o.id,
            COALESCE(o.pro_num, CONCAT('ORD-', o.id)) as invoice_number,
            o.pro_date as date,
            o.fat_total as total,
            CASE 
                WHEN o.closed = 1 THEN 'مكتمل'
                ELSE 'قيد التنفيذ'
            END as status,
            o.acc2 as client_id,
            COALESCE((SELECT aname FROM acc_head WHERE id = o.acc1 LIMIT 1), 'عميل نقدي') as customer_name,
            o.isdeleted,
            o.pro_tybe as order_type,
            o.info as order_info,
            o.age as order_age
          FROM ot_head o
          WHERE o.isdeleted = 0 AND o.pro_tybe = 9
          ORDER BY o.id DESC
          LIMIT 10";

$result = $conn->query($query);

if ($result === false) {
    echo "<p style='color: red;'>Query Error: " . $conn->error . "</p>";
} else {
    echo "<p style='color: green;'>Query executed successfully. Rows returned: " . $result->num_rows . "</p>";
    
    if ($result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Invoice</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th><th>Type</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['invoice_number']}</td>";
            echo "<td>{$row['date']}</td>";
            echo "<td>{$row['customer_name']}</td>";
            echo "<td>{$row['total']}</td>";
            echo "<td>{$row['status']}</td>";
            echo "<td>{$row['order_age']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

$conn->close();
?>
