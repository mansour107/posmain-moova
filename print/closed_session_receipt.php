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

// جلب إعدادات النظام للترويسة
$settings_query = $conn->query("SELECT * FROM settings WHERE id = 1");
$settings = $settings_query->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة تقرير شيفت مغلق</title>
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body, html { margin: 0; padding: 0; background-color: #fff; }
            #printed { box-shadow: none !important; border: none !important; margin: 0 !important; }
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
        .summary-box {
            border: 1px dashed #000;
            padding: 5px;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 14px;
            border-top: 1px dashed #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        .total-row span:last-child {
            margin-left: 15mm;
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
            <p class="mb-0" style="font-size: 11px;">نسخة مطبوعة من شيفت مغلق</p>
        </div>

        <div class="header-title">تقرير إغلاق وردية (Z-Report)</div>
        
        <div class="info-row">
            <span>التاريخ:</span>
            <span><?= $shift['date'] ?></span>
        </div>
        <div class="info-row">
            <span>وقت الإغلاق:</span>
            <span><?= $shift['endtime'] ?></span>
        </div>
        <div class="info-row">
            <span>الكاشير:</span>
            <span><?= $shift['user'] ?></span>
        </div>
        <div class="info-row">
            <span>رقم الشيفت:</span>
            <span>#<?= $shift['id'] ?></span>
        </div>

        <div class="summary-box">
            <div class="info-row">
                <span>إجمالي المبيعات:</span>
                <span><?= number_format($shift['total_sales'], 2) ?></span>
            </div>
            <div class="info-row">
                <span>المصروفات:</span>
                <span><?= number_format($shift['expenses'], 2) ?></span>
            </div>
            <?php if (!empty($shift['exp_notes'])): ?>
            <div style="font-size: 10px; color: #555; text-align: right; margin-bottom: 5px;">
                (<?= htmlspecialchars($shift['exp_notes']) ?>)
            </div>
            <?php endif; ?>
            
            <div class="total-row">
                <span>تسليم الكاش:</span>
                <span><?= number_format($shift['cash'], 2) ?></span>
            </div>
            <div class="info-row mt-2" style="font-size: 11px;">
                <span>المتبقي في الدرج:</span>
                <span><?= number_format($shift['fund_after'], 2) ?></span>
            </div>
        </div>

        <?php if (!empty($shift['info'])): ?>
        <div class="info-row" style="flex-direction: column; text-align: center;">
            <span style="border-bottom: 1px solid #000; margin-bottom: 3px;">ملاحظات</span>
            <span style="font-weight: normal;"><?= nl2br(htmlspecialchars($shift['info'])) ?></span>
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
