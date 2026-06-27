<?php
require_once __DIR__ . '/../../classes/Inventory/InventoryLegacyStockEndpointGuard.php';
include('../../includes/connect.php'); // تضمين ملف الاتصال بقاعدة البيانات

InventoryLegacyStockEndpointGuard::blockIfLive('legacy_recost_ajax_retired', 'json');

$fatid = isset($_GET['fatid']) ? intval($_GET['fatid']) : 1; // رقم العملية

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT id, qty_in, qty_out, cost_price, price FROM fat_details WHERE fatid = ? ORDER BY id");
    $stmt->bind_param("i", $fatid);
    $stmt->execute();
    $rows = $stmt->get_result();

    $prev_qty = 0;
    $prev_value = 0;

    while ($row = $rows->fetch_assoc()) {
        $id = $row['id'];
        if ($row['qty_in'] > 0) {
            $prev_value += $row['qty_in'] * $row['cost_price'];
            $prev_qty += $row['qty_in'];
        }
        $cost_price = ($prev_qty > 0) ? $prev_value / $prev_qty : 0;
        $profit = ($row['qty_out'] > 0) ? ($row['price'] - $cost_price) * $row['qty_out'] : 0;
        $prev_qty -= $row['qty_out'];
        $prev_value = $prev_qty * $cost_price;

        $update = $conn->prepare("UPDATE fat_details SET cost_price = ?, profit = ? WHERE id = ?");
        $update->bind_param("ddi", $cost_price, $profit, $id);
        $update->execute();
        $update->close();
    }
    $stmt->close();

    $conn->commit();
    echo "تم التحديث بنجاح!";
} catch (Exception $e) {
    $conn->rollback();
    echo "حدث خطأ: " . $e->getMessage();
}
?>
