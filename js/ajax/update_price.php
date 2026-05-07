<?php
include('../../includes/connect.php');

$id = $_POST['id'];
$price = $_POST['price'];

$stmt = $conn->prepare("UPDATE myitems SET price1 = ? WHERE id = ?");
$stmt->bind_param("di", $price, $id);
$result = $stmt->execute();

echo $result ? 'success' : 'error';

$stmt->close();
$conn->close();
?>
