<?php
include('../includes/connect.php');

// Get the item ID from the URL parameter
$id = isset($_GET['id']) ? $_GET['id'] : null;

if ($id) {
    // Prepare the statement to get item information from 'myitems' table
    $stmt = $conn->prepare("SELECT * FROM myitems WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $rowinfo = $result->fetch_assoc();

        // Get units information from 'item_units' and 'myunits' tables
        $unit_stmt = $conn->prepare("
            SELECT u.id, u.uname, iu.cost_price, iu.price1, iu.price2, iu.price3, iu.price4 ,iu.u_val 
            FROM item_units iu 
            INNER JOIN myunits u ON iu.unit_id = u.id 
            WHERE iu.item_id = ?
        ");
        $unit_stmt->bind_param("i", $id);
        $unit_stmt->execute();
        $unit_result = $unit_stmt->get_result();

        $units = [];
        while ($unit_row = $unit_result->fetch_assoc()) {
            $units[] = [
                'unit_id' => $unit_row['id'],
                'unit_name' => $unit_row['uname'],
                'unit_value' => $unit_row['u_val'],
                'ucost' => $unit_row['cost_price'],
                'uprice1' => $unit_row['price1'],
                'uprice2' => $unit_row['price2'],
                'uprice3' => $unit_row['price3'],
                'uprice4' => $unit_row['price4']
            ];
        }

        // Combine the item info and the units data
        $rowinfo['units'] = $units;

        // Return the data in JSON format
        header('Content-Type: application/json');
        echo json_encode($rowinfo);
    } else {
        // Return an error message if the item is not found
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Item not found']);
    }

    // Close the statement
    $stmt->close();
    $unit_stmt->close();
} else {
    // Return an error if no item ID is provided
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Item ID not provided']);
}

// Close the database connection
$conn->close();
?>
