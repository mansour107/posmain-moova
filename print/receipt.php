<?php 

include('includes/header.php'); 

if (!isset($_GET['id'])) {
    echo "لا يوجد فاتورة بهذا الرقم";
    die;
}

$id = intval($_GET['id']); // حماية من SQL injection
$rowfat = $conn->query("SELECT * FROM `ot_head` where id = $id")->fetch_assoc();
if ($rowfat == null) {
    echo "لا يوجد فاتورة بهذا الرقم";die;
}else{
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
    // Check for Table information
    $info = $rowfat['info'];
    $table_name = '';
    
    if (strpos($info, 'طاولة') !== false || strpos($info, 'Table') !== false) {
        // Try to extract full table name if it's formatted in a specific way, otherwise just use info if it's short
        // Assuming info might contain just "طاولة 1" or mixed text.
        // If it comes from tables.php, info is usually just the table name.
        $table_name = $info;
    }
    
    if (!empty($table_name)) {
        echo '<div style="text-align:center; font-weight:bold; font-size:16px; margin-bottom:5px; border:1px dashed #000; padding:2px;">' . $table_name . '</div>';
    }
    ?>

<?php
$accid = $rowfat['acc1'];
$rowacc1= $conn->query("SELECT aname,info from acc_head where id = $accid")->fetch_assoc();
$is_delivery = strpos($rowfat['info'], 'دليفري') !== false;

if ($is_delivery) {
    $info = $rowfat['info'];
    preg_match('/العميل: ([^-]+)/', $info, $name_match);
    preg_match('/الهاتف: ([^-]+)/', $info, $phone_match);
    preg_match('/العنوان: (.+)$/', $info, $address_match);
    
    $customer_name = isset($name_match[1]) ? trim($name_match[1]) : $rowacc1['aname'];
    $customer_phone = isset($phone_match[1]) ? trim($phone_match[1]) : '';
    $customer_address = isset($address_match[1]) ? trim($address_match[1]) : '';
    
    echo '<div class="row invoice-info font-thin m-0"><div class="col-sm-12 invoice-col"><address>';
    if($customer_name) echo "<b>العميل:</b> " . $customer_name;
    if ($customer_address) echo "<br><b>العنوان:</b> " . $customer_address;
    if ($customer_phone) echo "<br><b>الموبايل:</b> " . $customer_phone;
    echo '</address></div></div>';
}
?>

<div class="row">





<table class="table col-md-12 table-bordered text-center" style="border: 1px solid #ddd;">
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
    $x =0;
    $resdet = $conn->query("SELECT * FROM fat_details where fatid = $id");
    while ($rowdet =$resdet->fetch_assoc()) {
        $x++;
        $itmid= $rowdet['item_id']; 
        $rowitm = $conn->query("SELECT * FROM myitems where id = $itmid ")->fetch_assoc();
        $qty = $rowdet['qty_out'];       
    ?>
<tr>
<td class="p-1" style="font-size:small; border: 1px solid #ddd;"><?= $rowitm['iname']  ?></td>
<td style="border: 1px solid #ddd;"><?= $qty  ?></td>
<td style="border: 1px solid #ddd;"><?= $rowdet['price']?></td>
<td style="border: 1px solid #ddd;"><?= $rowdet['det_value']?></td>
</tr>
<?php }?>
</tbody>
</table>

<table class="table col-md-12 table-bordered text-center" style="border: 1px solid #ddd; margin-top: 0;">
<tbody>
<tr style="font-weight: bold;background-color: #f0f0f0;">
<td style="border: 1px solid #ddd; padding: 8px;">اجمالي</td>
<?php if ($rowfat['fat_disc'] > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;">خصم</td>
<?php }?>
<?php if ($rowfat['fat_plus'] > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;">اضافي</td>
<?php }?>
<td style="border: 1px solid #ddd; padding: 8px;">الصافي</td>
</tr>
<tr style="font-weight: bold;">
<td style="border: 1px solid #ddd; padding: 8px;"><?= $rowfat['fat_total'] ?></td>
<?php if ($rowfat['fat_disc'] > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $rowfat['fat_disc'] ?></td>
<?php }?>
<?php if ($rowfat['fat_plus'] > 0 ){?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $rowfat['fat_plus'] ?></td>
<?php }?>
<td style="border: 1px solid #ddd; padding: 8px;"><?= $rowfat['fat_net'] ?></td>
</tr>
</tbody>
</table>

</div>


<div class="row">
<div class="col">
    <p style="font-size:12px;text-align:center"><?= $rowfat['crtime'] ?></p>
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