<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    $query = "SELECT * FROM myitems WHERE isdeleted = 0 ORDER BY iname ASC";
    $result = $conn->query($query);
    
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

