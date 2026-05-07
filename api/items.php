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
    // Get optional category filter
    $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    
    // Build query - all item fields with images
    $sql = "SELECT 
                i.id,
                i.iname as name,
                i.name2 as name2,
                i.code,
                i.barcode,
                i.itmqty as quantity,
                i.salesqty,
                i.info as description,
                i.market_price,
                i.cost_price,
                i.last_price as purchase_price,
                i.price1 as sale_price,
                i.price2,
                i.price3,
                i.group1,
                i.group2,
                i.group3,
                g1.gname as group1_name,
                g2.gname as group2_name,
                g3.gname as group3_name,
                i.crtime as created_at,
                i.mdtime as updated_at,
                img.iname as image_name
            FROM myitems i
            LEFT JOIN item_group g1 ON i.group1 = g1.id
            LEFT JOIN item_group g2 ON i.group2 = g2.id
            LEFT JOIN item_group g3 ON i.group3 = g3.id
            LEFT JOIN imgs img ON i.id = img.itemid
            WHERE i.isdeleted = 0";
    
    // Add category filter if provided (searches in all 3 groups)
    if ($categoryId) {
        $sql .= " AND (i.group1 = ? OR i.group2 = ? OR i.group3 = ?)";
    }
    
    $sql .= " ORDER BY i.id, img.id";
    
    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
    }
    
    if ($categoryId) {
        $stmt->bind_param("iii", $categoryId, $categoryId, $categoryId);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    // Fetch all items and group images
    $items = [];
    $currentItemId = null;
    $currentItem = null;
    
    while ($row = $result->fetch_assoc()) {
        // If we encounter a new item, save the previous one and start a new one
        if ($currentItemId !== $row['id']) {
            if ($currentItem !== null) {
                $items[] = $currentItem;
            }
            
            $currentItemId = $row['id'];
            $currentItem = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'name2' => $row['name2'],
                'code' => (int)$row['code'],
                'barcode' => $row['barcode'],
                'quantity' => (float)$row['quantity'],
                'sales_quantity' => (float)$row['salesqty'],
                'description' => $row['description'],
                'prices' => [
                    'sale_price' => (float)$row['sale_price'],
                    'price2' => (float)$row['price2'],
                    'price3' => (float)$row['price3'],
                    'purchase_price' => (float)$row['purchase_price'],
                    'cost_price' => (float)$row['cost_price'],
                    'market_price' => (float)$row['market_price']
                ],
                'categories' => [
                    'group1' => [
                        'id' => (int)$row['group1'],
                        'name' => $row['group1_name']
                    ],
                    'group2' => [
                        'id' => (int)$row['group2'],
                        'name' => $row['group2_name']
                    ],
                    'group3' => [
                        'id' => (int)$row['group3'],
                        'name' => $row['group3_name']
                    ]
                ],
                'images' => [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        // Add image URL if it exists
        if ($row['image_name']) {
            $currentItem['images'][] = 'uploads/' . $row['image_name'];
        }
    }
    
    // Don't forget to add the last item
    if ($currentItem !== null) {
        $items[] = $currentItem;
    }
    
    $stmt->close();
    $conn->close();
    
    // Return success response
    echo json_encode([
        'status' => 'success',
        'data' => $items
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Something went wrong',
        'debug' => $e->getMessage() // Remove this line in production
    ], JSON_PRETTY_PRINT);
}
