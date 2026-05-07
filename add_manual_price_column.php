<?php
include('includes/connect.php');

// إضافة عمود manual_price_edit إلى جدول myitems
$sql = "ALTER TABLE myitems ADD COLUMN manual_price_edit TINYINT(1) DEFAULT 0";

if ($conn->query($sql) === TRUE) {
    echo "تم إضافة العمود بنجاح";
} else {
    if (strpos($conn->error, 'Duplicate column name') !== false) {
        echo "العمود موجود مسبقاً";
    } else {
        echo "خطأ: " . $conn->error;
    }
}

$conn->close();
?>