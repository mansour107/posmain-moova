<?php
session_start();
include('../includes/connect.php');

$usid = $_SESSION['userid'];

if (isset($_POST['barcode'])) {
    $barcode = $_POST['barcode'];
} else {
    $last_barcode = $conn->query('SELECT barcode FROM myitems ORDER BY id DESC LIMIT 1')->fetch_assoc()['barcode'];
    $barcode = $last_barcode + 1;
} 

$iname = $_POST['iname']; 
$chkname = $conn->query("SELECT * FROM myitems WHERE iname = '$iname'")->fetch_assoc();

if ($chkname !== null) {
    header('Location: ../add_item.php?error=duplicate_name');
    exit;
}

$code = $_POST['code'];
    $name2 = $_POST['name2']; 
    $group1 = $_POST['group1']; 
    $group2 = $_POST['group2']; 
    $info = $_POST['info']; 
    $cost_price = $_POST['cost_price'][0]; // نأخذ القيمة الأولى
    $price1 = $_POST['price1'][0]; // نأخذ القيمة الأولى
    $price2 = $_POST['price2'][0]; // نأخذ القيمة الأولى
    $market_price = $_POST['market_price'][0]; // نأخذ القيمة الأولى
 


    // إدخال البيانات إلى جدول myitems
    $sql = "INSERT INTO myitems(iname, name2, code, barcode, info, market_price, cost_price, price1, price2, group1, group2, user) 
            VALUES ('$iname', '$name2', '$code', '$barcode', '$info', '$market_price', '$cost_price', '$price1', '$price2', '$group1', '$group2', '$usid')";
    if (!$conn->query($sql)) {
        header('Location: ../add_item.php?error=save_failed');
        exit;
    }

    $last_id = $conn->insert_id;

    // إدخال بيانات الوحدات إلى جدول item_units
    foreach ($_POST['unit_id'] as $index => $unit_id) {
        // التأكد من أن جميع القيم في المصفوفات متاحة
        $u_val = $_POST['u_val'][$index];
        
        // التحقق من وجود باركود للوحدة، إذا لم يكن موجودًا يتم توليد باركود تلقائي
        if (!empty($_POST['unit_barcode'][$index])) {
            $unit_barcode = $_POST['unit_barcode'][$index];
        } else {
            $unit_barcode = "99" . $index . $_POST['unit_barcode'][0]; // توليد باركود تلقائي
        }
            // التعامل مع الأسعار كـ Array
            $cost_price = $_POST['cost_price'][$index]; // نأخذ القيمة الأولى
            $price1 = $_POST['price1'][$index]; // نأخذ القيمة الأولى
            $price2 = $_POST['price2'][$index]; // نأخذ القيمة الأولى
            $market_price = $_POST['market_price'][$index]; // نأخذ القيمة الأولى



        // إدخال البيانات إلى item_units
        $sqlunit = "INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode,cost_price,price1, price2,price3) 
        VALUES ('$last_id', '$unit_id', '$u_val', '$unit_barcode','$cost_price','$price1','$price2','$market_price')";
    
        $conn->query($sqlunit);
    }

    // معالجة رفع الصور
    $imgs_name = $_FILES['imgs']['name'];
    if (!empty($imgs_name['0'])) {
        for ($i = 0; $i < count($_FILES['imgs']['name']); $i++) {
            $imgs_name = $_FILES['imgs']['name'][$i];
            $tmp_name = $_FILES['imgs']['tmp_name'][$i];
            
        
            $arrkvr = explode(".", $imgs_name);
            $kvr_ext = end($arrkvr);

            $allow_ext = ["jpg", "png", "gif", "jpeg", "webp"];
            if (!in_array($kvr_ext, $allow_ext)) {
                header('Location: ../add_item.php?error=invalid_image');
                exit;
            }

            $new_kvr_name = $arrkvr[0] . rand(1, 1000000) . "." . $kvr_ext;
            move_uploaded_file($tmp_name, "../uploads/$new_kvr_name");

            $conn->query("INSERT INTO imgs (iname, itemid) VALUES ('$new_kvr_name', '$last_id')");
        }
    }

    // إضافة سجل في جدول process
    $conn->query("INSERT INTO process(type) VALUES ('add item')");

    // إعادة التوجيه إلى صفحة إضافة الصنف
    header('Location: ../add_item.php?saved=1');
    exit;
?>
