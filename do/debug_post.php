<?php
session_start();

echo "<h2>🔍 POST Data Debug</h2>";
echo "<hr>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>✅ POST Request Received</h3>";
    
    echo "<h4>Session Data:</h4>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
    
    echo "<h4>POST Data:</h4>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    echo "<h4>Critical Fields Check:</h4>";
    $required = ['pro_tybe', 'store_id', 'acc2_id', 'emp_id', 'fund_id', 'itmname', 'headtotal', 'headnet'];
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Isset?</th><th>Value</th></tr>";
    foreach ($required as $field) {
        $isset = isset($_POST[$field]) ? '✅' : '❌';
        $value = isset($_POST[$field]) ? (is_array($_POST[$field]) ? 'Array[' . count($_POST[$field]) . ']' : $_POST[$field]) : 'NOT SET';
        echo "<tr><td>$field</td><td>$isset</td><td>$value</td></tr>";
    }
    echo "</table>";
    
    echo "<h4>Items Data:</h4>";
    if (isset($_POST['itmname']) && is_array($_POST['itmname'])) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>#</th><th>Name</th><th>ID</th><th>Qty</th><th>Price</th><th>Value</th></tr>";
        $count = count($_POST['itmname']);
        for ($i = 0; $i < $count; $i++) {
            echo "<tr>";
            echo "<td>" . ($i + 1) . "</td>";
            echo "<td>" . (isset($_POST['itmname'][$i]) ? $_POST['itmname'][$i] : 'N/A') . "</td>";
            echo "<td>" . (isset($_POST['itmid'][$i]) ? $_POST['itmid'][$i] : 'N/A') . "</td>";
            echo "<td>" . (isset($_POST['itmqty'][$i]) ? $_POST['itmqty'][$i] : 'N/A') . "</td>";
            echo "<td>" . (isset($_POST['itmprice'][$i]) ? $_POST['itmprice'][$i] : 'N/A') . "</td>";
            echo "<td>" . (isset($_POST['itmval'][$i]) ? $_POST['itmval'][$i] : 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>❌ No items found in POST data</p>";
    }
    
    echo "<hr>";
    echo "<a href='../pos_barcode.php'>← العودة لـ POS</a>";
    
} else {
    echo "<p>❌ No POST request received</p>";
    echo "<p>Request Method: " . $_SERVER['REQUEST_METHOD'] . "</p>";
}
?>

<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th { background: #0a7ea4; color: white; }
    tr:nth-child(even) { background: #f2f2f2; }
</style>