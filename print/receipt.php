<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';


include('includes/header.php');
require_once __DIR__ . '/../classes/Pos/Service/OrderPrintPayloadService.php';
require_once __DIR__ . '/../classes/Pos/Service/BrowserPrintAuditService.php';

if (!isset($_GET['id'])) {
    echo "لا يوجد فاتورة بهذا الرقم";
    die;
}

$id = intval($_GET['id']); // حماية من SQL injection
$rowfat = $conn->query("SELECT * FROM `ot_head` where id = $id")->fetch_assoc();
if ($rowfat == null) {
    echo "لا يوجد فاتورة بهذا الرقم";die;
}else{
    $print_payload = null;
    try {
        $print_payload = (new OrderPrintPayloadService())->buildReceiptPayload($conn, $id);
    } catch (Throwable $printPayloadError) {
        $print_payload = null;
    }
    if ($print_payload !== null) {
        try {
            (new BrowserPrintAuditService())->recordRenderedPrint(
                $conn,
                'receipt',
                $id,
                $print_payload,
                isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : null,
                [
                    'source' => 'print_receipt_page',
                    'reprint_reason' => $_GET['reprint_reason'] ?? null,
                ]
            );
        } catch (Throwable $printAuditError) {
        }
    }
    $tybe = $rowfat['pro_tybe'];
    
    // تحديد صفحة العودة حسب نوع POS
    $pos_type = $rowstg['pos_type'] ?? 'barcode';
    // التحقق من طلب القفل بعد الطباعة
    if (isset($_SESSION['lock_after_print']) && $_SESSION['lock_after_print'] === true) {
        $back_page = '../pos_barcode.php?logout=1';
        unset($_SESSION['lock_after_print']); // مسح المتغير بعد الاستخدام
    } else {
        $back_page = ($pos_type === 'clothes') ? '../pos_clothes.php' : '../pos_barcode.php';
    }
?>



<div class="card" id="printed" style="width: 72mm;">
<div class="card-body">

<?php 
$logo_path = '../assets/logo/logo.jpg';
if (file_exists($logo_path)) {
    echo '<img src="' . $logo_path . '" alt="" style="width: 90px; height: auto; display: block; margin: 0 auto;">';
} else {
    echo '<div class="text-center p-2">لوجو الشركة</div>';
}
?>
<h1 class="text-center p-3 p-0 font-bold" style="font-size: 23px;font-weight:bolder;">
<?= $rowstg['company_name'] ?></h1>

<?php
$prodate = date('md', strtotime($rowfat['pro_date']));
?>
<div class="row" >
    <div class="col-12">
<p style="font-size:12px;text-align:center">
    <?= $prodate.$rowfat['pro_id'] ?></p>

    <?php
    $table_name = $print_payload['table']['name'] ?? '';
    if ($table_name === '' && !empty($rowfat['table_id'])) {
        $table_id = intval($rowfat['table_id']);
        $table_row = $conn->query("SELECT tname FROM tables WHERE id = $table_id")->fetch_assoc();
        $table_name = $table_row['tname'] ?? '';
    }
    
    if (!empty($table_name)) {
        echo '<div style="text-align:center; font-weight:bold; font-size:16px; margin-bottom:5px; border:1px dashed #000; padding:2px;">' . $table_name . '</div>';
    }
    ?>

<?php
$accid = $rowfat['acc1'];
$rowacc1= $conn->query("SELECT aname,info from acc_head where id = $accid")->fetch_assoc();
$is_delivery = ($rowfat['order_type'] ?? '') === 'delivery';

if ($is_delivery) {
    $customer_name = '';
    $customer_phone = '';
    $customer_address = '';
    $delivery_zone = '';
    $fulfillmentTable = $conn->query("SHOW TABLES LIKE 'order_fulfillment'");
    if ($fulfillmentTable && $fulfillmentTable->num_rows > 0) {
        $fulfillmentStmt = $conn->prepare("SELECT customer_name, customer_phone, customer_address, delivery_zone, delivery_fee FROM order_fulfillment WHERE order_id = ? LIMIT 1");
        if ($fulfillmentStmt) {
            $fulfillmentStmt->bind_param('i', $id);
            $fulfillmentStmt->execute();
            $fulfillmentRow = $fulfillmentStmt->get_result()->fetch_assoc();
            $fulfillmentStmt->close();
            if ($fulfillmentRow) {
                $customer_name = trim((string) ($fulfillmentRow['customer_name'] ?? ''));
                $customer_phone = trim((string) ($fulfillmentRow['customer_phone'] ?? ''));
                $customer_address = trim((string) ($fulfillmentRow['customer_address'] ?? ''));
                $delivery_zone = trim((string) ($fulfillmentRow['delivery_zone'] ?? ''));
            }
        }
    }
    if ($customer_name === '' && $customer_phone === '' && $customer_address === '') {
        $info = $rowfat['info'];
        preg_match('/العميل: ([^-]+)/', $info, $name_match);
        preg_match('/الهاتف: ([^-]+)/', $info, $phone_match);
        preg_match('/العنوان: (.+)$/', $info, $address_match);
        $customer_name = isset($name_match[1]) ? trim($name_match[1]) : $rowacc1['aname'];
        $customer_phone = isset($phone_match[1]) ? trim($phone_match[1]) : '';
        $customer_address = isset($address_match[1]) ? trim($address_match[1]) : '';
    } elseif ($customer_name === '') {
        $customer_name = $rowacc1['aname'];
    }
    
    echo '<div class="row invoice-info font-thin m-0"><div class="col-sm-12 invoice-col"><address>';
    if($customer_name) echo "<b>العميل:</b> " . htmlspecialchars($customer_name, ENT_QUOTES, 'UTF-8');
    if ($customer_address) echo "<br><b>العنوان:</b> " . htmlspecialchars($customer_address, ENT_QUOTES, 'UTF-8');
    if ($delivery_zone) echo "<br><b>المنطقة:</b> " . htmlspecialchars($delivery_zone, ENT_QUOTES, 'UTF-8');
    if ($customer_phone) echo "<br><b>الموبايل:</b> " . htmlspecialchars($customer_phone, ENT_QUOTES, 'UTF-8');
    echo '</address></div></div>';
}
?>

<div class="row">





<table class="table col-md-12 table-bordered text-center receipt-fixed-table" style="border: 1px solid #ddd;">
<colgroup>
    <col style="width: 46%;">
    <col style="width: 18%;">
    <col style="width: 18%;">
    <col style="width: 18%;">
</colgroup>
<thead>
<tr style="font-size:x-small; background-color: #f0f0f0;">
<th style="border: 1px solid #ddd; padding: 8px;">الصــــنـــف</th>
<th style="border: 1px solid #ddd; padding: 8px;">الكمية</th>
<th style="border: 1px solid #ddd; padding: 8px;">السعر</th>
<th style="border: 1px solid #ddd; padding: 8px;">القيمة</th>
</tr>
</thead>
<tbody>
    <?php 
    $receipt_lines = is_array($print_payload['lines'] ?? null) ? $print_payload['lines'] : null;
    if ($receipt_lines !== null) {
        foreach ($receipt_lines as $line) {
            $line_modifiers = is_array($line['modifiers'] ?? null) ? $line['modifiers'] : [];
            $line_notes = is_array($line['notes'] ?? null) ? $line['notes'] : [];
            $line_preparation_values = is_array($line['preparation_values'] ?? null) ? $line['preparation_values'] : [];
    ?>
<tr>
<td class="p-1 receipt-item-name-cell" style="font-size:small; border: 1px solid #ddd;">
    <?= htmlspecialchars($line['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    <?php foreach ($line_modifiers as $modifier): ?>
        <div style="font-size:10px; color:#444;">+ <?= htmlspecialchars($modifier['name_ar'] ?? $modifier['name_en'] ?? '', ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($modifier['qty'] ?? '1.000', ENT_QUOTES, 'UTF-8') ?>)</div>
    <?php endforeach; ?>
    <?php foreach ($line_notes as $note): ?>
        <div style="font-size:10px; color:#555;">ملاحظة: <?= htmlspecialchars($note['note_text'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
    <?php foreach ($line_preparation_values as $preparation): ?>
        <div style="font-size:10px; color:#555;">تحضير: <?= htmlspecialchars($preparation['label_ar'] ?? '', ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string) ($preparation['value'] ?? 0), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
</td>
<td style="border: 1px solid #ddd;"><?= htmlspecialchars($line['qty'] ?? '0.000', ENT_QUOTES, 'UTF-8') ?></td>
<td style="border: 1px solid #ddd;"><?= htmlspecialchars($line['price'] ?? '0.00', ENT_QUOTES, 'UTF-8') ?></td>
<td style="border: 1px solid #ddd;"><?= htmlspecialchars($line['line_total'] ?? '0.00', ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php
        }
    } else {
        $x =0;
        $resdet = $conn->query("SELECT * FROM fat_details where fatid = $id AND isdeleted = 0");
        while ($rowdet =$resdet->fetch_assoc()) {
            $x++;
            $itmid= $rowdet['item_id'];
            $rowitm = $conn->query("SELECT * FROM myitems where id = $itmid ")->fetch_assoc();
            $qty = $rowdet['qty_out'];
?>
<tr>
<td class="p-1 receipt-item-name-cell" style="font-size:small; border: 1px solid #ddd;"><?= $rowitm['iname']  ?></td>
<td style="border: 1px solid #ddd;"><?= $qty  ?></td>
<td style="border: 1px solid #ddd;"><?= $rowdet['price']?></td>
<td style="border: 1px solid #ddd;"><?= $rowdet['det_value']?></td>
</tr>
<?php
        }
    }
?>
</tbody>
</table>

<table class="table col-md-12 table-bordered text-center" style="border: 1px solid #ddd; margin-top: 0;">
<tbody>
<?php $receipt_totals = is_array($print_payload['totals'] ?? null) ? $print_payload['totals'] : null; ?>
<tr style="font-weight: bold;background-color: #f0f0f0;">
<td style="border: 1px solid #ddd; padding: 8px;">اجمالي</td>
<?php if (($receipt_totals !== null ? (float) $receipt_totals['discount'] : (float) $rowfat['fat_disc']) > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;">خصم</td>
<?php }?>
<?php if (($receipt_totals !== null ? (float) $receipt_totals['extra'] : (float) $rowfat['fat_plus']) > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;">اضافي</td>
<?php }?>
<td style="border: 1px solid #ddd; padding: 8px;">الصافي</td>
</tr>
<tr style="font-weight: bold;">
<td style="border: 1px solid #ddd; padding: 8px;"><?= $receipt_totals !== null ? $receipt_totals['total'] : $rowfat['fat_total'] ?></td>
<?php if (($receipt_totals !== null ? (float) $receipt_totals['discount'] : (float) $rowfat['fat_disc']) > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $receipt_totals !== null ? $receipt_totals['discount'] : $rowfat['fat_disc'] ?></td>
<?php }?>
<?php if (($receipt_totals !== null ? (float) $receipt_totals['extra'] : (float) $rowfat['fat_plus']) > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $receipt_totals !== null ? $receipt_totals['extra'] : $rowfat['fat_plus'] ?></td>
<?php }?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $receipt_totals !== null ? $receipt_totals['net'] : $rowfat['fat_net'] ?></td>
</tr>
</tbody>
</table>

</div>


<div class="row">
<div class="col">
    <p style="font-size:12px;text-align:center"><?= $rowfat['crtime'] ?></p>
    <?php
    $escalationAttribution = trim((string) ($print_payload['escalation_attribution'] ?? ''));
    if ($escalationAttribution !== '') {
        echo '<p style="font-size:11px;text-align:center;font-weight:bold;margin:6px 0;">'
            . htmlspecialchars($escalationAttribution, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
    ?>
    <div style="text-align: center; direction: ltr; font-size: 12px; font-weight: bold;">
        Thank you for choosing us where good ideas find the  
        <p>❤ perfect place to grow</p>
    </div>
    
    <div style="text-align: center; margin-top: 15px;">
        <img src="../qrCode.png" alt="QR Code" style="width: 60px; height: 60px; display: block; margin: 0 auto;">
        <div style="margin-top: 5px;">
            <i class="fab fa-facebook" style="font-size: 10px; color: #1877f2;"></i>
            <span style="font-size: 10px;">FOCUS HOUSE</span>
        </div>
    </div>
</div>
</div>

</div>
</div>

</div>
</div>

<div class="row no-print">
<div class="col-12">
    <button id="printButton" class="btn btn-secondary frst" >
<i class="fas fa-print" ></i> طباعه
</button>
<a href="<?= $back_page ?>" id="back">عودة</a>


</div>
</div>

<?php }?>
<style>
#printed .receipt-fixed-table {
    width: 100%;
    table-layout: fixed;
}

#printed .receipt-fixed-table th,
#printed .receipt-fixed-table td {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    vertical-align: top;
}

#printed .receipt-item-name-cell {
    text-align: right;
    line-height: 1.35;
}

@media print {
    @page {
        size: 72mm 210mm;
        margin: 0;
    }

    html,
    body {
        width: 72mm !important;
        min-width: 72mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    body > *:not(#printed) {
        display: none !important;
    }

    #printed {
        display: block !important;
        width: 72mm !important;
        max-width: 72mm !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        box-shadow: none !important;
        position: static !important;
    }

    #printed .card-body {
        width: 72mm !important;
        margin: 0 !important;
        padding: 2mm !important;
        box-sizing: border-box !important;
    }

    #printed .row,
    #printed [class^="col"],
    #printed [class*=" col"] {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    #printed .table {
        width: 100% !important;
        margin-bottom: 2mm !important;
        table-layout: fixed !important;
    }

    #printed th,
    #printed td {
        padding: 1.2mm !important;
        word-break: break-word !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        vertical-align: top !important;
    }

    #printed .receipt-item-name-cell {
        text-align: right !important;
        line-height: 1.35 !important;
    }
}
</style>
<script>
// استخدام JavaScript عادي بدلاً من jQuery
document.addEventListener('DOMContentLoaded', function() {
    var printButton = document.getElementById('printButton');
    
    if (printButton) {
        printButton.addEventListener('click', function() {
            console.log('Print button clicked');
            window.print();
        });
    }
    
    // زر Escape للعودة
    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            var backButton = document.getElementById('back');
            if (backButton) {
                backButton.click();
            }
        }
    });
});
</script>

<?php include('includes/footer.php') ?>
