<?php
// API لمزامنة البيانات الأوفلاين مع النظام الحالي
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../classes/Inventory/InventoryRetiredLegacyEndpoint.php';
include('../includes/connect.php');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'POST':
        handleOfflineSync($input);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function handleOfflineSync($data) {
    global $conn;
    
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'sync_orders':
            InventoryRetiredLegacyEndpoint::respond('offline_stock_replay_retired');
            break;
        case 'sync_customers':
            syncOfflineCustomers($data['customers']);
            break;
        case 'get_items':
            getItemsForOffline();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function syncOfflineCustomers($customers) {
    global $conn;
    
    $syncedCount = 0;
    $errors = [];
    
    foreach ($customers as $customer) {
        if ($customer['synced']) continue;
        
        try {
            // البحث عن العميل أولاً
            $sql = "SELECT id FROM customers WHERE phone = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('s', $customer['phone']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // تحديث العميل الموجود
                $sql = "UPDATE customers SET name = ?, address = ? WHERE phone = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sss', $customer['name'], $customer['address'], $customer['phone']);
            } else {
                // إضافة عميل جديد
                $sql = "INSERT INTO customers (phone, name, address) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sss', $customer['phone'], $customer['name'], $customer['address']);
            }
            
            $stmt->execute();
            $syncedCount++;
            
        } catch (Exception $e) {
            $errors[] = [
                'customer_phone' => $customer['phone'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'synced_count' => $syncedCount,
        'errors' => $errors
    ]);
}

function getItemsForOffline() {
    global $conn;
    
    try {
        $sql = "SELECT m.id, m.iname as name, m.price1 as price, m.barcode, m.group1,
                       i.iname as img_filename
                FROM myitems m 
                LEFT JOIN imgs i ON i.itemid = m.id 
                WHERE m.isdeleted = 0 
                GROUP BY m.id
                ORDER BY m.iname";
        
        $result = $conn->query($sql);
        $items = [];
        
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'barcode' => $row['barcode'] ?: $row['id'],
                'category' => $row['group1'],
                'image' => $row['img_filename'] ? 'uploads/' . $row['img_filename'] : null
            ];
        }
        
        echo json_encode([
            'success' => true,
            'items' => $items,
            'timestamp' => time()
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>
