<?php
include('../../includes/connect.php');


$startTime = $_POST['start_time'];
$endTime = $_POST['end_time'];
$clientid = $_GET['id'];
$q = $_GET['q'];

$rowres = $conn->query("SELECT id FROM `reservations` where client = '$clientid' order by id DESC")->fetch_assoc();
$resid = $rowres['id'];

// التحقق من صحة تنسيق الوقت وإضافة التاريخ الحالي إذا لزم الأمر
if ($q == 0) {
    // للبداية - إضافة التاريخ الحالي إذا كان الوقت فقط
    if (strlen($startTime) <= 5 && strpos($startTime, ':') !== false) {
        $startTime = date('Y-m-d') . ' ' . $startTime . ':00';
    }
    $sql = "UPDATE reservations SET start_time = '$startTime' WHERE id = '$resid'";
    
    if ($conn->query($sql) === TRUE) {
        echo "Record inserted successfully ".$startTime;
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
} elseif ($q == 1) {
    // للنهاية - حساب المدة
    if (strlen($endTime) <= 5 && strpos($endTime, ':') !== false) {
        $endTime = date('Y-m-d') . ' ' . $endTime . ':00';
    }
    
    // الحصول على وقت البداية من قاعدة البيانات
    $startTimeFromDB = $conn->query("SELECT start_time FROM reservations WHERE id = '$resid'")->fetch_assoc()['start_time'];
    
    if ($startTimeFromDB) {
        $startDateTime = new DateTime($startTimeFromDB);
        $endDateTime = new DateTime($endTime);
        $durationS = $endDateTime->getTimestamp() - $startDateTime->getTimestamp();
        $duration = $durationS/60;
        
        $sql = "UPDATE reservations SET end_time = '$endTime' , duration = $duration WHERE id = '$resid'";
        
        if ($conn->query($sql) === TRUE) {
            echo "Record inserted successfully ".$endTime;
        } else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
    } else {
        echo "Error: Start time not found";
    }
}

$conn->close();
?>
