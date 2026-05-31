<?php
// API لمزامنة بيانات POS أوفلاين مع قاعدة البيانات
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/classes/Inventory/InventoryRetiredLegacyEndpoint.php';
include('includes/connect.php');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost($input);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function handleGet() {
    global $conn;
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'items':
            getItems();
            break;
        case 'orders':
            getOrders();
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePost($input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'sync_order':
            InventoryRetiredLegacyEndpoint::respond('pos_sync_stock_replay_retired');
            break;
        case 'sync_items':
            syncItems($input['items']);
            break;
        case 'add_item':
            addItem($input['item']);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function getItems() {
    global $conn;
    
    try {
        $sql = "SELECT id, iname as name, price1 as price, barcode 
                FROM myitems 
                WHERE isdeleted = 0 
                ORDER BY iname";
        
        $result = $conn->query($sql);
        $items = [];
        
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'barcode' => $row['barcode'] ?: $row['id']
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

function getOrders() {
    global $conn;
    
    try {
        $sql = "SELECT o.id, o.fat_net as total, o.pro_date as date,
                       GROUP_CONCAT(
                           CONCAT(m.iname, ':', fd.qty_out, ':', fd.price) 
                           SEPARATOR '|'
                       ) as items
                FROM ot_head o
                LEFT JOIN fat_details fd ON o.id = fd.fatid
                LEFT JOIN myitems m ON fd.item_id = m.id
                WHERE o.pro_tybe = 9 AND o.isdeleted = 0
                AND DATE(o.pro_date) = CURDATE()
                GROUP BY o.id
                ORDER BY o.id DESC
                LIMIT 50";
        
        $result = $conn->query($sql);
        $orders = [];
        
        while ($row = $result->fetch_assoc()) {
            $items = [];
            if ($row['items']) {
                $itemsData = explode('|', $row['items']);
                foreach ($itemsData as $itemData) {
                    $parts = explode(':', $itemData);
                    if (count($parts) >= 3) {
                        $items[] = [
                            'name' => $parts[0],
                            'quantity' => (int)$parts[1],
                            'price' => (float)$parts[2]
                        ];
                    }
                }
            }
            
            $orders[] = [
                'id' => (int)$row['id'],
                'total' => (float)$row['total'],
                'date' => $row['date'],
                'items' => $items
            ];
        }
        
        echo json_encode([
            'success' => true,
            'orders' => $orders
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function addItem($itemData) {
    global $conn;
    
    try {
        $sql = "INSERT INTO myitems (iname, price1, barcode, group1) 
                VALUES (?, ?, ?, 1)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sds', $itemData['name'], $itemData['price'], $itemData['barcode']);
        $stmt->execute();
        
        $itemId = $conn->insert_id;
        
        echo json_encode([
            'success' => true,
            'item_id' => $itemId,
            'message' => 'تم إضافة الصنف بنجاح'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function syncItems($items) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        foreach ($items as $item) {
            if (isset($item['id']) && $item['id'] > 0) {
                // تحديث صنف موجود
                $sql = "UPDATE myitems SET iname = ?, price1 = ?, barcode = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sdsi', $item['name'], $item['price'], $item['barcode'], $item['id']);
            } else {
                // إضافة صنف جديد
                $sql = "INSERT INTO myitems (iname, price1, barcode, group1) VALUES (?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('sds', $item['name'], $item['price'], $item['barcode']);
            }
            $stmt->execute();
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم تزامن الأصناف بنجاح'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>
