<?php
session_start();
include('../includes/connect.php');

$term = isset($_GET['term']) ? mysqli_real_escape_string($conn, trim($_GET['term'])) : '';

$items = [];

if (!empty($term)) {
    // 1. Search by exact barcode or code first
    $sql = "SELECT id, iname as name, barcode, price1 as price, 1 as u_val, '' as unit_name 
            FROM myitems 
            WHERE (barcode = '$term' OR code = '$term') AND isdeleted = 0 LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $items[] = [
            'id' => $row['id'],
            'label' => $row['name'] . ' (' . ($row['barcode'] ?: $row['id']) . ') - ' . $row['price'] . ' ج.م',
            'value' => $row['name'],
            'item' => $row
        ];
    } else {
        // Also check units
        $sql_units = "SELECT iu.item_id as id, m.iname as name, iu.unit_barcode as barcode, 
                             iu.price1 as price, iu.u_val as u_val
                      FROM item_units iu
                      JOIN myitems m ON m.id = iu.item_id
                      WHERE iu.unit_barcode = '$term' AND m.isdeleted = 0 LIMIT 1";
        $result_units = $conn->query($sql_units);
        if ($result_units && $result_units->num_rows > 0) {
            $row = $result_units->fetch_assoc();
            $row['unit_name'] = 'وحدة فرعية';
            $items[] = [
                'id' => $row['id'],
                'label' => $row['name'] . ' (' . $row['barcode'] . ') - ' . $row['price'] . ' ج.م',
                'value' => $row['name'],
                'item' => $row
            ];
        }
    }
    
    // 2. Search by name (LIKE search)
    $sql_name = "SELECT id, iname as name, barcode, price1 as price, 1 as u_val, '' as unit_name 
                 FROM myitems 
                 WHERE iname LIKE '%$term%' AND isdeleted = 0 LIMIT 15";
    $result_name = $conn->query($sql_name);
    if ($result_name && $result_name->num_rows > 0) {
        while ($row = $result_name->fetch_assoc()) {
            // Check if already in array
            $exists = false;
            foreach ($items as $it) {
                if ($it['id'] == $row['id']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $items[] = [
                    'id' => $row['id'],
                    'label' => $row['name'] . ' (' . ($row['barcode'] ?: $row['id']) . ') - ' . $row['price'] . ' ج.م',
                    'value' => $row['name'],
                    'item' => $row
                ];
            }
        }
    }
}

echo json_encode($items);
?>
