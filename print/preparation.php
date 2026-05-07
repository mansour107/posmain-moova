<?php
include('../includes/connect.php');

$table_id = $_GET['table_id'] ?? 0;

if ($table_id) {
    // جلب بيانات الطاولة والطلب
    $table_query = "SELECT tname FROM tables WHERE id = $table_id";
    $table_result = $conn->query($table_query);
    $table_name = $table_result->fetch_assoc()['tname'] ?? '';
    
    // جلب الطلب
    $order_query = "SELECT * FROM ot_head WHERE info LIKE '%$table_name%' AND pro_tybe = 9 ORDER BY id DESC LIMIT 1";
    $order_result = $conn->query($order_query);
    $order = $order_result->fetch_assoc();
    
    if ($order) {
        $order_id = $order['id'];
        
        // جلب الأصناف
        $items_query = "SELECT fd.*, i.iname FROM fat_details fd 
                       LEFT JOIN myitems i ON fd.item_id = i.id 
                       WHERE fd.pro_id = $order_id AND fd.isdeleted = 0";
        $items_result = $conn->query($items_query);
?>
<!DOCTYPE html>
<html>
<head>
    <title>طباعة التحضير</title>
    <style>
        body { font-family: Arial; direction: rtl; }
        .header { text-align: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 8px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>طلب التحضير</h2>
        <p>الطاولة: <?= $table_name ?></p>
        <p>التاريخ: <?= date('Y-m-d H:i') ?></p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>الصنف</th>
                <th>الكمية</th>
                <th>ملاحظات</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($item = $items_result->fetch_assoc()): ?>
            <tr>
                <td><?= $item['iname'] ?></td>
                <td><?= $item['qty'] ?></td>
                <td><?= $item['notes'] ?? '' ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    
    <script>
        window.print();
        window.close();
    </script>
</body>
</html>
<?php
    }
}
?>