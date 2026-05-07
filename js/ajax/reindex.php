<?php
include('../../includes/connect.php');
// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Update quantities
$sql = "
    UPDATE myitems
    SET itmqty = (
        SELECT COALESCE(SUM(qty_in), 0) - COALESCE(SUM(qty_out), 0)
        FROM fat_details
        WHERE item_id = myitems.id AND isdeleted = 0
    )
";

if ($conn->query($sql) === TRUE) {
    echo json_encode(['status' => 'success', 'message' => 'Reindexing completed successfully.']);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}

$conn->close();
?>
