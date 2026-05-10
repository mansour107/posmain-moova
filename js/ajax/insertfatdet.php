<?php
include('../../includes/connect.php');
$selectedItemId = $_POST['myitm'];
$quantity = $_POST['itmqty'];
$price = $_POST['itmprice'];
$discount = $_POST['itmdisc'];

// Calculate the value (you might want to adjust this based on your business logic)
$value = $quantity * ($price - $discount);

// Insert data into the database
$conn->query("INSERT INTO fat_details (item_id, qty_in, qty_out, price, discount, det_value) VALUES ($selectedItemId, 0, $quantity, $price, $discount, $value)");


header('Content-Type: application/json');
echo json_encode($response);
exit;  // Make sure to exit to prevent further output
?>
