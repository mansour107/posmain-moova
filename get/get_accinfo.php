<?php
include('../includes/connect.php');

// Get the item ID from the URL parameter
if(isset($_GET['id'])) {
    // Sanitize the input to prevent SQL injection
    $itemId = $_GET['id'];
    
    // Prepare and bind the query
    $query = 'SELECT aname FROM acc_head WHERE aname = "?" ';
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, 's', $itemId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    
    if(mysqli_stmt_num_rows($stmt) > 0) {
        // Item exists, set response accordingly
        
        echo json_encode(array("status" => "exists", "message" => "<p class='text-danger'>هذا الاسم موجود مسبقا</p>"));
    } else {
        // Item does not exist, set response accordingly
        echo json_encode(array("status" => "available", "message" => "<p class='text-success'>هذا الاسم يمكن استخدامه</p>"));
    }
    mysqli_stmt_close($stmt);
} else {
    // 'id' parameter is not set, handle error
    echo json_encode(array("status" => "error", "message" => "Parameter 'id' is missing"));
}
