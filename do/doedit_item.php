<?php
session_start();
include('../includes/connect.php');

$item_id = $_GET['edit'];
$usid = $_SESSION['userid'];

// Ensure user is authenticated
if (!isset($usid)) {
    header('location:login.php');
    exit();
} 

// Barcode Handling
if (isset($_POST['barcode'])) {
    $barcode = $_POST['barcode'];
} else {
    // Get the last barcode from the database and generate a new one
    $last_barcode_result = $conn->query('SELECT barcode FROM myitems ORDER BY id DESC LIMIT 1');
    if ($last_barcode_result && $last_barcode_result->num_rows > 0) {
        $last_barcode = $last_barcode_result->fetch_assoc()['barcode'];
        $barcode = $last_barcode + 1;
    } else {
        $barcode = 1000001; // Starting point if no barcodes exist
    }
}

// Item Name Validation (Check for duplicate names)
$iname = $_POST['iname']; 
$sqlchkname  = "SELECT * FROM myitems WHERE iname = ? AND id != ?";
$stmt = $conn->prepare($sqlchkname);
$stmt->bind_param('si', $iname, $item_id);
$stmt->execute();
$chkname = $stmt->get_result()->fetch_assoc();

if ($chkname !== null) {
    header('Location: ../add_item.php?edit=' . (int) $item_id . '&error=duplicate_name');
    exit;
}

// Prepare to update the main item
$code = $_POST['code'];
$name2 = $_POST['name2']; 
$group1 = $_POST['group1']; 
$group2 = $_POST['group2']; 
$info = $_POST['info']; 
$cost_price = $_POST['cost_price'][0]; 
$price1 = $_POST['price1'][0];
$price2 = $_POST['price2'][0]; 




// Handle image upload
if (isset($_FILES['imgs']) && !empty($_FILES['imgs']['name'][0])) {
    $imgs_name = $_FILES['imgs']['name'][0];
    $tmp_name = $_FILES['imgs']['tmp_name'][0];
    
    $arrkvr = explode(".", $imgs_name);
    $kvr_ext = end($arrkvr);
    
    $allow_ext = ["jpg", "png", "gif", "jpeg", "webp"];
    if (in_array($kvr_ext, $allow_ext)) {
        $new_kvr_name = $arrkvr[0] . rand(1, 1000000) . "." . $kvr_ext;
        if (move_uploaded_file($tmp_name, "../uploads/$new_kvr_name")) {
            // حذف الصور القديمة للصنف
            $conn->query("DELETE FROM imgs WHERE itemid = '$item_id'");
            // إضافة الصورة الجديدة
            $conn->query("INSERT INTO imgs (iname, itemid) VALUES ('$new_kvr_name', '$item_id')");
        }
    }
}

// تحديث الجدول الرئيسي
$sql = "UPDATE myitems SET iname='$iname', name2='$name2', code='$code', info='$info', cost_price='$cost_price', group1='$group1', group2='$group2', price1='$price1' WHERE id='$item_id'";

// إضافة العمود إذا لم يكن موجوداً
$checkColumn = $conn->query("SHOW COLUMNS FROM myitems LIKE 'manual_price_edit'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE myitems ADD COLUMN manual_price_edit TINYINT(1) DEFAULT 0");
}

// تعيين علامة التعديل اليدوي
$conn->query("UPDATE myitems SET manual_price_edit=1 WHERE id='$item_id'");
if (!$conn->query($sql)) {
    header('Location: ../add_item.php?edit=' . (int) $item_id . '&error=save_failed');
    exit;
}

// تحديث وحدات الصنف
foreach ($_POST['unit_id'] as $index => $unit_id) {
    $u_val = $_POST['u_val'][$index];
    $unit_barcode = !empty($_POST['unit_barcode'][$index]) ? $_POST['unit_barcode'][$index] : "99" . $index . $_POST['unit_barcode'][0];
    $cost_price_unit = $_POST['cost_price'][$index];
    $price1_unit = $_POST['price1'][$index];
    $price2_unit = $_POST['price2'][$index];
    $market_price_unit = isset($_POST['price3'][$index]) ? $_POST['price3'][$index] : (isset($_POST['market_price'][$index]) ? $_POST['market_price'][$index] : 0);
    
    $sqlunit = "UPDATE item_units SET 
                cost_price='$cost_price_unit',
                price1='$price1_unit',
                price2='$price2_unit',
                price3='$market_price_unit', 
                u_val='$u_val',
                unit_barcode='$unit_barcode' 
                WHERE item_id='$item_id' AND unit_id='$unit_id'";
    
    $conn->query($sqlunit);
}


    header('Location: ../add_item.php?edit=' . (int) $item_id . '&saved=1');
    exit;
?>
