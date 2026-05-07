<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Database configuration - check if file exists
if (!file_exists(__DIR__ . '/../config/database.php')) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database configuration file not found'
    ]);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Check if connection exists
if (!isset($conn) || !$conn) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection not established'
    ]);
    exit;
}

try {
    // Get optional category ID filter
    $categoryId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    // Build query
    $sql = "SELECT 
                id,
                gname as name
            FROM item_group 
            WHERE isdeleted = 0";
    
    // Add ID filter if provided
    if ($categoryId) {
        $sql .= " AND id = ?";
    }
    
    $sql .= " ORDER BY id ASC";
    
    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    if ($categoryId) {
        $stmt->bind_param("i", $categoryId);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    // Fetch all categories
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'id' => (int)$row['id'],
            'name' => $row['name']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'data' => $categories
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Something went wrong',
        'debug' => $e->getMessage() // Remove this line in production
    ], JSON_PRETTY_PRINT);
}
