<?php 
/**
 * صفحة طباعة الفاتورة للويترز - مع تسجيل خروج تلقائي
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// التحقق من وجود جلسة ويتر
$is_waiter_mode = isset($_SESSION['waiter_logged_in']) && $_SESSION['waiter_logged_in'] === true;

include('../includes/connect.php'); 

if (!isset($_GET['id'])) {
    echo "لا يوجد فاتورة بهذا الرقم";
    die;
}

$id = intval($_GET['id']);
$rowfat = $conn->query("SELECT * FROM `ot_head` where id = $id")->fetch_assoc();
if ($rowfat == null) {
    echo "لا يوجد فاتورة بهذا الرقم";
    die;
}

$tybe = $rowfat['pro_tybe'];
$rowstg = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة رقم <?= $id ?></title>
    <link href="../assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/libs/fontawesome.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; padding: 0; }
        }
        .receipt-container {
            width: 72mm;
            margin: 20px auto;
            padding: 10px;
            border: 1px solid #ddd;
        }
        .text-center { text-align: center; }
        .btn-container {
            text-align: center;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="receipt-container" id="printed">
        <div class="card-body">
            <?php 
            $logo_path = '../assets/logo/logo.jpg';
            if (file_exists($logo_path)) {
                echo '<img src="' . $logo_path . '" alt="" style="width: 90px; height: auto; display: block; margin: 0 auto;">';
            }
            ?>
            <h1 class="text-center p-3 p-0 font-bold" style="font-size: 23px;font-weight:bolder;">
                <?= $rowstg['company_name'] ?>
            </h1>

            <?php
            $prodate = date('md', strtotime($rowfat['pro_date']));
            ?>
            <p style="font-size:12px;text-align:center">
                فاتورة رقم: <?= $prodate.$rowfat['pro_id'] ?>
            </p>

            <!-- تفاصيل الفاتورة -->
            <table style="width: 100%; font-size: 12px;">
                <thead>
                    <tr>
                        <th>الصنف</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $items = $conn->query("SELECT * FROM ot_details WHERE fat_id = $id");
                    $total = 0;
                    while($item = $items->fetch_assoc()) {
                        $item_total = $item['price'] * $item['quantity'];
                        $total += $item_total;
                        ?>
                        <tr>
                            <td><?= $item['item_name'] ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= number_format($item['price'], 2) ?></td>
                            <td><?= number_format($item_total, 2) ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>الإجمالي:</strong></td>
                        <td><strong><?= number_format($total, 2) ?></strong></td>
                    </tr>
                </tfoot>
            </table>

            <p class="text-center" style="margin-top: 20px; font-size: 11px;">
                شكراً لزيارتكم
            </p>
        </div>
    </div>

    <div class="btn-container no-print">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> طباعة
        </button>
        
        <?php if ($is_waiter_mode): ?>
            <button onclick="logoutAndReturn()" class="btn btn-success">
                <i class="fas fa-check"></i> تم - تسجيل خروج
            </button>
        <?php else: ?>
            <a href="../pos_barcode.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> عودة
            </a>
        <?php endif; ?>
    </div>

    <script src="../assets/libs/jquery/jquery-3.6.0.min.js"></script>
    <script>
        <?php if ($is_waiter_mode): ?>
        // طباعة تلقائية عند فتح الصفحة
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };

        // تسجيل خروج تلقائي بعد الطباعة
        window.onafterprint = function() {
            setTimeout(function() {
                logoutAndReturn();
            }, 1000);
        };

        function logoutAndReturn() {
            window.location.href = '../waiter_logout.php';
        }
        <?php endif; ?>
    </script>
</body>
</html>
