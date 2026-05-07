<?php include('includes/header.php') ?>
<?php
if (!isset($_GET['id'])) {
    echo "لا يوجد فاتورة بهذا الرقم";die;
}else
$id=$_GET['id'];
$rowfat = $conn->query("SELECT * FROM `ot_head` where id = $id")->fetch_assoc();
if ($rowfat == null) {
    echo "لا يوجد فاتورة بهذا الرقم";die;
}else{
    $tybe = $rowfat['pro_tybe'];
?>

<style>
@media print {
  body { font-size: 11px !important; margin: 0; padding: 10px; }
  .invoice-header { font-size: 18px !important; }
  .invoice-title { font-size: 14px !important; }
  table { font-size: 10px !important; }
  th, td { padding: 4px !important; }
  .no-print { display: none !important; }
  .card { box-shadow: none !important; border: none !important; }
}

body { 
    font-family: 'Cairo', 'Segoe UI', Tahoma, sans-serif;
    background-color: #f5f5f5;
    padding: 20px;
}

.card {
    max-width: 900px;
    margin: 0 auto;
    background: white;
    box-shadow: 0 0 20px rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
}

.card-body {
    padding: 30px;
}

.invoice-header {
    font-size: 22px;
    font-weight: bold;
    color: #1a2f5a;
    padding: 15px !important;
    background: linear-gradient(135deg, #1a2f5a 0%, #2d4a7c 100%) !important;
    color: white !important;
    border-radius: 5px;
}

.company-info {
    background-color: #f8f9fa;
    padding: 12px !important;
    border-radius: 5px;
    color: #666;
    font-size: 12px;
}

.invoice-title {
    font-size: 16px;
    font-weight: bold;
    color: #1a2f5a;
    padding: 10px !important;
    background-color: #e8f0fe !important;
}

.info-row {
    background-color: #fafafa;
    padding: 10px !important;
}

table.table-bordered {
    border: 1px solid #dee2e6;
    border-radius: 5px;
    overflow: hidden;
}

table.table-bordered th {
    background-color: #1a2f5a;
    color: white;
    font-weight: 600;
    text-align: center;
    padding: 10px 5px !important;
    border-color: #2d4a7c;
}

table.table-bordered td {
    padding: 8px 5px !important;
    vertical-align: middle;
    border-color: #dee2e6;
}

table.table-striped tbody tr:nth-of-type(odd) {
    background-color: #f8f9fa;
}

table.table-striped tbody tr:hover {
    background-color: #e9ecef;
}

.summary-table {
    background-color: #fff;
    border: 2px solid #1a2f5a;
}

.summary-table th {
    background-color: #f8f9fa;
    color: #333;
    font-weight: 600;
    width: 40%;
    text-align: right;
    padding: 8px 12px !important;
}

.summary-table td {
    text-align: left;
    padding: 8px 12px !important;
    font-weight: 500;
}

.summary-table tr.total-row {
    background-color: #1a2f5a !important;
    color: white !important;
}

.summary-table tr.total-row th,
.summary-table tr.total-row td {
    color: white !important;
    font-size: 14px;
    font-weight: bold;
}

.payment-policy {
    background-color: #fff9e6;
    border: 1px solid #ffd700;
    border-radius: 5px;
    padding: 15px;
    font-size: 11px;
    line-height: 1.6;
}

.payment-policy strong {
    color: #1a2f5a;
    display: block;
    margin-bottom: 5px;
}

#printButton {
    background: linear-gradient(135deg, #ff8c42 0%, #ff6b35 100%);
    color: white;
    border: none;
    padding: 8px 20px;
    font-size: 14px;
    font-weight: bold;
    border-radius: 5px;
    cursor: pointer;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    margin-top: 20px;
}

#printButton:hover {
    background: linear-gradient(135deg, #ff6b35 0%, #ff8c42 100%);
    box-shadow: 0 6px 8px rgba(0,0,0,0.15);
    transform: translateY(-2px);
}

#printButton i {
    margin-left: 8px;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}
</style>

<div class="card" id="printed">
<div class="card-body">

<!-- Header -->
<table class="table table-bordered" style="margin-bottom: 15px;">
<tr>
<td class="text-center invoice-header">
<?= $rowstg['company_name'] ?>
</td>
</tr>
<tr>
<td class="text-center company-info">
<?= $rowstg['company_add'] ?> | <?= $rowstg['company_tel'] ?> | <?= $rowstg['company_email'] ?>
</td>
</tr>
</table>

<!-- Invoice Info -->
<table class="table table-bordered" style="margin-bottom: 15px;">
<tr>
<td class="invoice-title" style="width: 50%;">
<?php 
if($tybe == 4){
    echo "فاتورة مشتريات";
} elseif($tybe == 3 || $tybe == 9){
    echo "فاتورة مبيعات";
} else {
    echo "فاتورة";
}
?>
</td>
<td class="text-right invoice-title" style="width: 50%;">
التاريخ: <?= $rowfat['pro_date'] ?>
</td>
</tr>
<tr>
<td class="info-row">
<?php
if($tybe == 4){
    $accid = $rowfat['acc2'];
    $label = "المورد";
} elseif($tybe == 3 || $tybe == 9){
    $accid = $rowfat['acc1'];
    $label = "العميل";
} else {
    $accid = $rowfat['acc1'];
    $label = "العميل";
}
$rowacc1= $conn->query("SELECT aname from acc_head where id = $accid")->fetch_assoc();
echo "<strong>" . $label . ":</strong> " . $rowacc1['aname'];?>
</td>
<td class="text-right info-row">
<strong>رقم:</strong> #<?=$rowfat['pro_id']?> | <strong>SN:</strong> <?= $rowfat['pro_serial']?>
</td>
</tr>
</table>

<!-- Items Table -->
<table class="table table-bordered table-striped" style="margin-bottom: 15px;">
<thead>
<tr>
<th style="width: 5%;">#</th>
<th style="width: 12%;">كود</th>
<th style="width: 35%;">الصنف</th>
<th style="width: 10%;">الكمية</th>
<th style="width: 10%;">الوحدة</th>
<th style="width: 14%;">السعر</th>
<th style="width: 14%;">القيمة</th>
</tr>
</thead>
<tbody>
    <?php 
    $x =0;
    $resdet = $conn->query("SELECT * FROM fat_details where pro_id = $id AND isdeleted = 0 order by id desc");
    while ($rowdet =$resdet->fetch_assoc()) {
        $x++;
        $itmid= $rowdet['item_id']; 
        $rowitm = $conn->query("SELECT * FROM myitems where id = $itmid ")->fetch_assoc();
        if ($tybe == 4 || $tybe == 10){
            $qty = $rowdet['qty_in'] / $rowdet['u_val'];
        } elseif($tybe == 3 || $tybe == 9 || $tybe == 11){
            $qty = $rowdet['qty_out'] / $rowdet['u_val'];
        } else {
            $qty = 0;
        } 
    ?>
<tr>
<td class="text-center"><?= $x ?></td>
<td><?= $rowitm['barcode']  ?></td>
<td><?= $rowitm['iname']  ?></td>
<td class="text-center"><?= number_format($qty, 2)  ?></td>
<td class="text-center"><?php 
$uval = $rowdet['u_val'];
$itmuntsql = "SELECT unit_id from item_units where item_id = $itmid AND u_val = $uval";
$itmunt=$conn->query($itmuntsql)->fetch_assoc();
$unitid =$conn->query("SELECT uname from myunits where id = $itmunt[unit_id]")->fetch_assoc();
echo $unitid['uname'];
?></td>
<td class="text-center"><?= number_format($rowdet['price'] * $rowdet['u_val'], 2) ?></td>
<td class="text-center"><strong><?= number_format($rowdet['det_value'], 2)?></strong></td>
</tr>
<?php }?>
</tbody>
</table>

<!-- Summary Section -->
<table class="table table-bordered" style="margin-top: 15px;">
<tr>
<td style="width: 50%; vertical-align: top;">
<div class="payment-policy">
<strong>سياسة المدفوعات:</strong>
الرصيد في الفاتورة يعتبر صحيح ما لم يتم المراجعة قبل 15 يوم
<br><br>
<strong>تاريخ الاستحقاق:</strong> <?= $rowfat['accural_date']?>
</div>
</td>
<td style="width: 50%; padding: 0 !important;">
<table class="table table-sm table-bordered summary-table" style="margin: 0;">
<tr><th>الإجمالي:</th><td><?= number_format($rowfat['fat_total'], 2) ?></td></tr>
<?php if ($rowfat['fat_disc'] > 0 ){?>
<tr><th>خصم:</th><td style="color: #dc3545;">- <?= number_format($rowfat['fat_disc'], 2) ?></td></tr>
<?php }?>
<?php if ($rowfat['fat_plus'] > 0 ){?>
<tr><th>إضافي:</th><td style="color: #28a745;">+ <?= number_format($rowfat['fat_plus'], 2) ?></td></tr>
<?php }?>
<tr class="total-row"><th>صافي الفاتورة:</th><td><strong><?= number_format($rowfat['fat_net'], 2) ?></strong></td></tr>
<?php 
// إخفاء المدفوع والمتبقي لأوامر الشراء والبيع وعروض الأسعار
if (!in_array($tybe, [12, 13, 14])): 
?>
<tr><th>المدفوع:</th><td style="color: #28a745;"><?php
           // البحث عن سند قبض/دفع مرتبط بالفاتورة
           $rowpaid = $conn->query("SELECT * FROM ot_head WHERE (pro_tybe = '2' OR pro_tybe = '1') AND op2 = $id AND isdeleted = 0")->fetch_assoc();
           
           if ($rowpaid && $rowpaid['pro_value'] !== null) {
               // إذا وجد سند، استخدم قيمته
               $paidval = $rowpaid['pro_value'];
           } else {
               // إذا لم يوجد سند، افترض أن المدفوع = الصافي (دفع كامل)
               $paidval = $rowfat['fat_net'];
           }
           
           $change = $rowfat['fat_net'] - $paidval;
           echo number_format($paidval, 2);
    ?></td></tr>
<tr><th>المتبقي:</th><td style="color: <?= $change > 0 ? '#dc3545' : '#28a745' ?>; font-weight: bold;"><?= number_format($change, 2) ?></td></tr>
<?php endif; ?>
</table>
</td>
</tr>
</table>

</div>
</div>

<div class="row no-print">
<div class="col-12">
    <button id="printButton">
<i class="fas fa-print"></i> طباعة الفاتورة
</button>
</div>
</div>

<?php }?>
<script>
sessionStorage.setItem('pos_print_page_opened', 'true');
console.log('Print page opened, flag set');

document.addEventListener('DOMContentLoaded', function() {
    var printButton = document.getElementById('printButton');
    
    if (printButton) {
        printButton.addEventListener('click', function() {
            console.log('Print button clicked');
            window.print();
        });
    }
});
</script>

<?php include('includes/footer.php') ?>