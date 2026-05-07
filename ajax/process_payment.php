<?php
$table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : 0;
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

if ($table_id > 0 && $amount > 0) {
    echo '{"success":true,"message":"تم السداد بنجاح","table_id":' . $table_id . ',"amount":' . $amount . '}';
} else {
    echo '{"success":false,"message":"بيانات غير صحيحة"}';
}
?>