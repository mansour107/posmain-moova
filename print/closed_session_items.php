<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/print_client_bootstrap.php';
require_login();
if (!auth_guard_has_permission('reports.cash_flow', $conn)
    && !auth_guard_has_permission('pos.shift.close', $conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403, null, 'reports.cash_flow');
}

if (!isset($_GET['id'])) {
    die("رقم الشيفت غير محدد.");
}

$id = intval($_GET['id']);
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$shiftStmt = $conn->prepare(
    "SELECT ds.id, ds.user_id, ds.business_day AS date, ds.opened_at, ds.closed_at,
            u.uname AS user, cs.shift_number
     FROM drawer_sessions ds
     LEFT JOIN drawer_session_close_summaries cs ON cs.drawer_session_id = ds.id
     LEFT JOIN users u ON u.id = ds.user_id
     WHERE ds.id = ?
       AND (? = 0 OR ds.tenant = ?)
       AND (? = 0 OR ds.branch = ?)
       AND ds.status IN ('closed', 'forced_closed') LIMIT 1"
);
$shiftStmt->bind_param('iiiii', $id, $tenant, $tenant, $branch, $branch);
$shiftStmt->execute();
$shift = $shiftStmt->get_result()->fetch_assoc();
$shiftStmt->close();
if (!$shift) {
    die("الشيفت غير موجود.");
}

$user_name = $shift['user'];
$shift_date = $shift['date'];
$start_time = (string) $shift['opened_at'];
$end_time = (string) $shift['closed_at'];
$user_id = (int) $shift['user_id'];

// جلب الأصناف المباعة مقسمة بالتصنيفات
$items_query = "
    SELECT category_name, item_name, SUM(quantity_delta) AS total_qty, SUM(value_delta) AS total_value
    FROM (
        SELECT COALESCE(c.gname, 'بدون تصنيف') AS category_name,
               i.iname AS item_name,
               d.qty_out AS quantity_delta,
               d.det_value AS value_delta
        FROM fat_details d
        JOIN ot_head h ON d.fatid = h.id
        JOIN myitems i ON d.item_id = i.id
        LEFT JOIN item_group c ON i.group1 = c.id
        WHERE DATE(h.pro_date) = ?
          AND h.crtime > ?
          AND h.crtime <= ?
          AND h.user = ?
          AND d.isdeleted = 0
          AND h.isdeleted = 0
          AND (h.pro_tybe = 9 OR h.pro_tybe = 3 OR h.pro_tybe = 10 OR h.pro_tybe = 11)
        UNION ALL
        SELECT COALESCE(c.gname, 'بدون تصنيف') AS category_name,
               i.iname AS item_name,
               -cnl.quantity AS quantity_delta,
               -cnl.line_amount AS value_delta
        FROM credit_notes cn
        JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
        JOIN fat_details d ON d.id = cnl.original_detail_id
        JOIN myitems i ON d.item_id = i.id
        LEFT JOIN item_group c ON i.group1 = c.id
        WHERE cn.status = 'posted'
          AND cn.drawer_session_id = ?
    ) shift_items
    GROUP BY category_name, item_name
    HAVING ABS(total_qty) >= 0.000001 OR ABS(total_value) >= 0.01
    ORDER BY category_name ASC, total_qty DESC
";

$items_data = [];
$total_qty_all = 0;
$total_value_all = 0;

$stmt = $conn->prepare($items_query);
if ($stmt) {
    $stmt->bind_param("sssii", $shift_date, $start_time, $end_time, $user_id, $id);
    $stmt->execute();
    $res_items = $stmt->get_result();
    while ($row = $res_items->fetch_assoc()) {
        $items_data[$row['category_name']][] = $row;
        $total_qty_all += $row['total_qty'];
        $total_value_all += $row['total_value'];
    }
}

// جلب إعدادات النظام للترويسة
$settings_query = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_query->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مبيعات الأصناف للشيفت</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            body, html { margin: 0; padding: 0; background-color: #fff; }
            #printed { box-shadow: none !important; border: none !important; margin: 0 !important; width: 78mm !important; }
            .no-print { display: none !important; }
        }
        #printed {
            font-family: 'Cairo', sans-serif;
            color: #000;
            background: #fff;
            padding: 5px;
        }
        .header-title {
            font-weight: bold;
            font-size: 14px;
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 3px;
            font-weight: bold;
        }
        .info-row span:last-child {
            margin-left: 15mm;
        }
        .category-title {
            font-weight: bold;
            font-size: 13px;
            background: #f0f0f0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 2px 5px;
            margin-top: 10px;
            text-align: right;
            -webkit-print-color-adjust: exact;
        }
        .item-table {
            width: 100%;
            font-size: 11px;
            margin-bottom: 5px;
            table-layout: fixed;
        }
        .item-table th {
            border-bottom: 1px dashed #000;
            text-align: center;
            padding: 2px;
        }
        .item-table td {
            text-align: center;
            padding: 2px;
        }
        .item-table td.text-start {
            text-align: right !important;
        }
        .item-table th,
        .item-table td {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
            vertical-align: top;
        }
        .receipt-item-name-cell {
            line-height: 1.35;
        }
        .summary-box {
            border-top: 2px dashed #000;
            padding-top: 5px;
            margin-top: 10px;
        }
    </style>
    <?= posmain_render_print_client_bootstrap('../') ?>
</head>
<body class="bg-light" data-print-job-type="report" data-print-content-selector="#printed">

<div class="card no-print mx-auto mt-3" style="width: 78mm;">
    <div class="card-body text-center">
        <button onclick="window.print()" class="btn btn-primary w-100"><i class="fas fa-print"></i> طباعة الان</button>
        <button onclick="window.close()" class="btn btn-secondary w-100 mt-2">إغلاق</button>
    </div>
</div>

<div class="card shadow-sm" id="printed" style="width: 78mm; margin: 0; border: 1px solid #eee; float: right; margin-right: 10mm;">
    <div class="card-body" style="padding: 15px !important;">
        
        <div class="text-center mb-2">
            <h4 class="mb-1" style="font-size: 16px; font-weight: bold;"><?= $settings['site_name'] ?? 'النظام' ?></h4>
            <p class="mb-0" style="font-size: 11px;">مبيعات الأصناف بالشيفت (Z-Report Items)</p>
        </div>

        <div class="header-title">تفاصيل الوردية</div>
        
        <div class="info-row">
            <span>الكاشير:</span>
            <span><?= $user_name ?></span>
        </div>
        <div class="info-row">
            <span>التاريخ:</span>
            <span><?= $shift_date ?></span>
        </div>
        <div class="info-row" style="font-size: 10px; font-weight: normal;">
            <span>من:</span>
            <span><?= substr($start_time, 11, 8) ?></span>
        </div>
        <div class="info-row" style="font-size: 10px; font-weight: normal;">
            <span>إلى:</span>
            <span><?= substr($end_time, 11, 8) ?></span>
        </div>
        <div class="info-row">
            <span>رقم الشيفت:</span>
            <span>#<?= htmlspecialchars((string) ($shift['shift_number'] ?: $shift['id'])) ?></span>
        </div>

        <?php if (empty($items_data)): ?>
            <div class="text-center mt-4 mb-4" style="font-size: 12px; font-weight: bold;">
                لا توجد مبيعات أصناف في هذه الوردية
            </div>
        <?php else: ?>
            
            <?php foreach ($items_data as $category => $items): ?>
                <div class="category-title"><?= htmlspecialchars($category) ?></div>
                <table class="item-table">
                    <thead>
                        <tr>
                            <th class="text-start" style="width: 50%;">الصنف</th>
                            <th style="width: 20%;">كمية</th>
                            <th style="width: 30%;">القيمة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $cat_qty = 0;
                        $cat_val = 0;
                        foreach ($items as $item): 
                            $cat_qty += $item['total_qty'];
                            $cat_val += $item['total_value'];
                        ?>
                        <tr>
                            <td class="text-start receipt-item-name-cell"><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= number_format($item['total_qty'], 2) ?></td>
                            <td><?= number_format($item['total_value'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px dotted #ccc; font-weight: bold;">
                            <td class="text-start">الإجمالي</td>
                            <td><?= number_format($cat_qty, 2) ?></td>
                            <td><?= number_format($cat_val, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <div class="summary-box">
                <div class="info-row" style="font-size: 14px;">
                    <span>إجمالي الكميات:</span>
                    <span><?= number_format($total_qty_all, 2) ?></span>
                </div>
                <div class="info-row" style="font-size: 14px;">
                    <span>إجمالي المبيعات:</span>
                    <span><?= number_format($total_value_all, 2) ?></span>
                </div>
            </div>

        <?php endif; ?>

        <div class="text-center mt-3" style="font-size: 10px; border-top: 1px dashed #000; padding-top: 5px;">
            تمت الطباعة في: <?= date('Y-m-d H:i:s') ?>
        </div>

    </div>
</div>

<script>
    window.onload = function() {
        window.print();
    }
</script>
</body>
</html>
