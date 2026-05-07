<?php
include('../../includes/connect.php'); // تضمين ملف الاتصال بقاعدة البيانات
$pro_id = 1; // رقم العملية

try {
    $stmt = $conn->prepare("SELECT id, qty_in, qty_out, cost_price, price FROM fat_details WHERE pro_id = :pro_id ORDER BY id");
    $res= $conn->query($stmt);
    $rows = $res->fetch_assoc();

    $prev_qty = 0;
    $prev_value = 0;

    foreach ($rows as $row) {
        $id = $row['id'];
        if ($row['qty_in'] > 0) {
            $prev_value += $row['qty_in'] * $row['cost_price'];
            $prev_qty += $row['qty_in'];
        }
        $cost_price = ($prev_qty > 0) ? $prev_value / $prev_qty : 0;
        $profit = ($row['qty_out'] > 0) ? ($row['price'] - $cost_price) * $row['qty_out'] : 0;
        $prev_qty -= $row['qty_out'];
        $prev_value = $prev_qty * $cost_price;

        $conn->prepare("UPDATE fat_details SET cost_price = :cost_price, profit = :profit WHERE id = :id")
            ->execute([':cost_price' => $cost_price, ':profit' => $profit, ':id' => $id]);
    }

    $conn->commit();
    echo "تم التحديث بنجاح!";
} catch (Exception $e) {
    echo "حدث خطأ: " . $e->getMessage();
}
?>
