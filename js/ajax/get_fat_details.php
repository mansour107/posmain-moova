<?php
include('../includes/connect.php');
$sqlfatdet = "SELECT * FROM fat_details WHERE fatid is null";

// Perform the query
$resfatdet = $conn->query($sqlfatdet);

    // Fetch each row from the result set and add it to the array
    while ($rowfatdet = $resfatdet->fetch_assoc()) { ?>
    
    <tr class="bg-dark">
                    <th>م</th>
                    <th class="col-5">اسم الصنف</th>
                    <th>كميه</th>
                    <th>سعر</th>
                    <th>خصم</th>
                    <th>القيمه</th>
                    <th></th>
                </tr>
    
    
    <?php } 
    // Return the fetched rows as JSON
    $response = array("success" => true, "rows" => $rows);

// Close the database connection
$conn->close();

// Return the response as JSON
echo json_encode($response);
?>
