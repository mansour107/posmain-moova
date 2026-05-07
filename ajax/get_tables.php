<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $query = "SELECT * FROM tables WHERE isdeleted = 0 ORDER BY id ASC";
    $result = $conn->query($query);
    
    $tables = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'tables' => $tables
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

