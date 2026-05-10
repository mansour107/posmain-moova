<?php
include('../includes/connect.php');

$table_id = intval($_GET['table_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);
$order = null;
$table_name = '';

if ($order_id > 0) {
    $stmt = $conn->prepare("
        SELECT oh.*, t.tname
        FROM ot_head oh
        LEFT JOIN tables t ON t.id = oh.table_id
        WHERE oh.id = ?
          AND oh.isdeleted = 0
        LIMIT 1
    ");
    $stmt->bind_param("i", $order_id);
} elseif ($table_id > 0) {
    $stmt = $conn->prepare("
        SELECT oh.*, t.tname
        FROM ot_head oh
        LEFT JOIN tables t ON t.id = oh.table_id
        WHERE oh.table_id = ?
          AND oh.pro_tybe = 9
          AND oh.isdeleted = 0
          AND COALESCE(oh.order_status, 'active') = 'active'
          AND COALESCE(oh.payment_status, 'unpaid') IN ('unpaid', 'partial')
        ORDER BY oh.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $table_id);
} else {
    $stmt = null;
}

if ($stmt) {
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($order) {
    $order_id = (int) $order['id'];
    $table_name = $order['tname'] ?? '';
    $items_stmt = $conn->prepare("
        SELECT fd.*, i.iname
        FROM fat_details fd
        LEFT JOIN myitems i ON fd.item_id = i.id
        WHERE fd.fatid = ?
          AND fd.isdeleted = 0
        ORDER BY fd.id ASC
    ");
    $items_stmt->bind_param("i", $order_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
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
        <?php if ($table_name !== ''): ?>
        <p>الطاولة: <?= htmlspecialchars($table_name, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <p>رقم الطلب: <?= $order_id ?></p>
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
                <td><?= htmlspecialchars($item['iname'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= floatval($item['qty_out']) - floatval($item['qty_in']) ?></td>
                <td><?= htmlspecialchars($item['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
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
?>
