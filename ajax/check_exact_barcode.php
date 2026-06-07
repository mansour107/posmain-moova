<?php
session_start();
include('../includes/connect.php');

if (isset($_POST['barcode'])) {
    $barcode = mysqli_real_escape_string($conn, trim($_POST['barcode']));
    
    if (empty($barcode)) {
        echo json_encode(['success' => false, 'message' => 'Empty barcode']);
        exit;
    }

    // 1. Search in main items table by barcode or code
    $sql = "SELECT id, iname as name, barcode, price1 as price, 1 as u_val, '' as unit_name 
            FROM myitems 
            WHERE (barcode = '$barcode' OR code = '$barcode') AND isdeleted = 0 LIMIT 1";
            
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode(['success' => true, 'item' => $row]);
        exit;
    }
    
    // 2. Search in item units table
    $sql_units = "SELECT iu.item_id as id, m.iname as name, iu.unit_barcode as barcode, 
                         iu.price1 as price, iu.u_val as u_val
                  FROM item_units iu
                  JOIN myitems m ON m.id = iu.item_id
                  WHERE iu.unit_barcode = '$barcode' AND m.isdeleted = 0 LIMIT 1";
                  
    $result_units = $conn->query($sql_units);
    if ($result_units && $result_units->num_rows > 0) {
        $row = $result_units->fetch_assoc();
        $row['unit_name'] = 'وحدة فرعية'; 
        echo json_encode(['success' => true, 'item' => $row]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Item not found']);
} else {
    echo json_encode(['success' => false, 'message' => 'No barcode provided']);
}
?>
