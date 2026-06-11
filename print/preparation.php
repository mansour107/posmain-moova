<?php
include('../includes/connect.php');
require_once __DIR__ . '/../classes/Pos/Service/OrderPrintPayloadService.php';
require_once __DIR__ . '/../classes/Pos/Service/BrowserPrintAuditService.php';

$table_id = intval($_GET['table_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);
$order = null;
$table_name = '';
$print_payload = null;

try {
    $payloadService = new OrderPrintPayloadService();
    if ($order_id > 0) {
        $print_payload = $payloadService->buildKotPayloadByOrderId($conn, $order_id);
    } elseif ($table_id > 0) {
        $print_payload = $payloadService->buildKotPayloadByTableId($conn, $table_id);
    }
} catch (Throwable $printPayloadError) {
    $print_payload = null;
}

if ($print_payload !== null) {
    $order_id = (int) $print_payload['order']['id'];
    $table_name = $print_payload['table']['name'] ?? '';
    try {
        (new BrowserPrintAuditService())->recordRenderedPrint(
            $conn,
            'kot',
            $order_id,
            $print_payload,
            isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : null,
            [
                'source' => 'print_preparation_page',
                'reprint_reason' => $_GET['reprint_reason'] ?? null,
            ]
        );
    } catch (Throwable $printAuditError) {
    }
} elseif ($order_id > 0) {
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

if ($print_payload !== null || $order) {
    $print_lines = $print_payload['lines'] ?? null;
    if ($print_payload !== null) {
        $items_result = null;
    } else {
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
    }
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
        .receipt-fixed-table { table-layout: fixed; }
        .receipt-fixed-table th,
        .receipt-fixed-table td {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            vertical-align: top;
        }
        .receipt-item-name-cell {
            text-align: right;
            line-height: 1.35;
        }
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

    <table class="receipt-fixed-table">
        <colgroup>
            <col style="width: 44%;">
            <col style="width: 16%;">
            <col style="width: 40%;">
        </colgroup>
        <thead>
            <tr>
                <th>الصنف</th>
                <th>الكمية</th>
                <th>ملاحظات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (is_array($print_lines)): ?>
            <?php foreach ($print_lines as $item): ?>
            <tr>
                <td class="receipt-item-name-cell">
                    <?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <?php foreach (($item['modifiers'] ?? []) as $modifier): ?>
                        <div style="font-size: 12px;">+ <?= htmlspecialchars($modifier['name_ar'] ?? $modifier['name_en'] ?? '', ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($modifier['qty'] ?? '1.000', ENT_QUOTES, 'UTF-8') ?>)</div>
                    <?php endforeach; ?>
                </td>
                <td><?= htmlspecialchars($item['qty'] ?? '0.000', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php
                    $notes = is_array($item['notes'] ?? null) ? $item['notes'] : [];
                    if ($notes) {
                        foreach ($notes as $note) {
                            echo '<div>' . htmlspecialchars($note['note_text'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
                        }
                    } else {
                        echo htmlspecialchars($item['legacy_notes'] ?? '', ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <?php while ($item = $items_result->fetch_assoc()): ?>
            <tr>
                <td class="receipt-item-name-cell"><?= htmlspecialchars($item['iname'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= floatval($item['qty_out']) - floatval($item['qty_in']) ?></td>
                <td><?= htmlspecialchars($item['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endwhile; ?>
            <?php endif; ?>
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
