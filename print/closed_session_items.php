<?php
session_start();
include('../includes/connect.php');

if (!isset($_GET['id'])) {
    die("رقم الشيفت غير محدد.");
}

$id = intval($_GET['id']);
$query = "SELECT * FROM closed_orders WHERE id = $id";
$res = $conn->query($query);
if ($res->num_rows == 0) {
    die("الشيفت غير موجود.");
}
$shift = $res->fetch_assoc();

$user_name = $shift['user'];
$shift_date = $shift['date'];
$end_time = $shift['crtime'];

// جلب ID المستخدم
$user_id = 0;
$user_stmt = $conn->prepare("SELECT id FROM acc_head WHERE aname = ? LIMIT 1");
if ($user_stmt) {
    $user_stmt->bind_param("s", $user_name);
    $user_stmt->execute();
    $u_res = $user_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $user_id = $u_row['id'];
    }
}

// جلب وقت بداية الشيفت
$start_time = "$shift_date 00:00:00"; // افتراضي بداية اليوم
$prev_stmt = $conn->prepare("SELECT crtime FROM closed_orders WHERE user = ? AND date = ? AND id < ? ORDER BY id DESC LIMIT 1");
if ($prev_stmt) {
    $prev_stmt->bind_param("ssi", $user_name, $shift_date, $id);
    $prev_stmt->execute();
    $p_res = $prev_stmt->get_result();
    if ($p_row = $p_res->fetch_assoc()) {
        $start_time = $p_row['crtime'];
    }
}

// جلب الأصناف المباعة مقسمة بالمجموعات
$items_query = "
    SELECT 
        COALESCE(c.gname, 'بدون مجموعة') as category_name,
        i.iname as item_name,
        SUM(d.qty_out) as total_qty,
        SUM(d.det_value) as total_value
    FROM fat_details d
    JOIN ot_head h ON d.fatid = h.id
    JOIN myitems i ON d.item_id = i.id
    LEFT JOIN item_group c ON i.group1 = c.id
    WHERE h.pro_date = ?
      AND h.crtime > ?
      AND h.crtime <= ?
      AND h.user = ?
      AND d.isdeleted = 0
      AND h.isdeleted = 0
      AND (h.pro_tybe = 9 OR h.pro_tybe = 3 OR h.pro_tybe = 10 OR h.pro_tybe = 11)
    GROUP BY c.gname, i.iname
    HAVING total_qty > 0
    ORDER BY c.gname ASC, total_qty DESC
";

$items_data = [];
$total_qty_all = 0;
$total_value_all = 0;

$stmt = $conn->prepare($items_query);
if ($stmt) {
    $stmt->bind_param("sssi", $shift_date, $start_time, $end_time, $user_id);
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
        .summary-box {
            border-top: 2px dashed #000;
            padding-top: 5px;
            margin-top: 10px;
        }
    </style>
</head>
<body class="bg-light">

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
            <span>#<?= $shift['id'] ?></span>
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
                            <td class="text-start"><?= htmlspecialchars($item['item_name']) ?></td>
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
