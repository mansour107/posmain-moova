<?php
// API لمزامنة البيانات الأوفلاين مع النظام الحالي
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

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
            syncOfflineOrders($data['orders']);
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

function syncOfflineOrders($orders) {
    global $conn;
    
    $syncedCount = 0;
    $errors = [];
    
    foreach ($orders as $order) {
        if ($order['synced']) continue; // تخطي الطلبات المتزامنة
        
        try {
            $conn->begin_transaction();
            
            // محاكاة معالجة بيانات النموذج
            parse_str($order['data'], $formData);
            
            // إدراج رأس الطلب
            $sql = "INSERT INTO ot_head (pro_tybe, pro_date, fat_net, info, emp_id, acc1, acc_fund, store_id, accural_date) 
                    VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $proTybe = $formData['pro_tybe'] ?? 9;
            $fatNet = $formData['headnet'] ?? 0;
            $info = $formData['info'] ?? 'طلب أوفلاين';
            $empId = $formData['emp_id'] ?? 1;
            $acc1 = $formData['acc2_id'] ?? 1;
            $accFund = $formData['fund_id'] ?? 1;
            $storeId = $formData['store_id'] ?? 1;
            $accuralDate = $formData['accural_date'] ?? date('Y-m-d');
            
            $stmt->bind_param('idsiiiiis', $proTybe, $fatNet, $info, $empId, $acc1, $accFund, $storeId, $accuralDate);
            $stmt->execute();
            
            $orderId = $conn->insert_id;
            
            // إدراج تفاصيل الطلب
            if (isset($formData['itmname']) && is_array($formData['itmname'])) {
                for ($i = 0; $i < count($formData['itmname']); $i++) {
                    $itemId = $formData['itmname'][$i];
                    $qty = $formData['itmqty'][$i] ?? 1;
                    $price = $formData['itmprice'][$i] ?? 0;
                    $value = $formData['itmval'][$i] ?? ($qty * $price);
                    
                    $sql = "INSERT INTO fat_details (pro_id, item_id, qty_out, price, det_value) 
                            VALUES (?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param('iiddd', $orderId, $itemId, $qty, $price, $value);
                    $stmt->execute();
                }
            }
            
            $conn->commit();
            $syncedCount++;
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = [
                'order_id' => $order['id'],
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