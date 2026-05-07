<?php
include('../includes/connect.php');

// Export database
$exportFileName = 'database_backup_' .$dbname. date('Y-m-d_H-i-s') . '.sql';

// Get the database content
$query = "SELECT * FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbname'";
$result = mysqli_query($conn, $query);
$schema = mysqli_fetch_assoc($result);

// Prepare the header
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($exportFileName) . '"');

// Get the database structure
$structureQuery = "SHOW CREATE DATABASE " . $dbname;