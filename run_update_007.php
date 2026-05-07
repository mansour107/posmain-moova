<?php
include('includes/connect.php');

$sql = file_get_contents('update/007_add_jal_amount.sql');
$queries = explode(';', $sql);

foreach ($queries as $query) {
    $query = trim($query);
    if (!empty($query)) {
        if ($conn->query($query) === TRUE) {
            echo "Query executed successfully: " . substr($query, 0, 50) . "...\n";
        } else {
            echo "Error executing query: " . $conn->error . "\n";
        }
    }
}
echo "Done.";
?>
