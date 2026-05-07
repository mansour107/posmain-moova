<?php
include('../../includes/connect.php');

// Query to fetch refreshed options
$query = "SELECT id, iname FROM myitems";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    // Output options
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['id'] . '">' . $row['iname'] . '</option>';
    }
} else {
    echo '<option value="">No items found</option>';
}


?>
