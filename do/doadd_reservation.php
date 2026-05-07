<?php
include('../includes/connect.php');

$sqlrsv = "SELECT COUNT(*) as total FROM reservations";
$result = $conn->query($sqlrsv);
$rowrsv = $result->fetch_assoc();

if ( $rowrsv['total'] > 50 && $lc_hadi != $rowstg['lic']) {
    header("Location: ../not_licenced.php?'$lc_hadi'");
    exit();
} else {
    if (isset($_POST['client'])) {
        $client = $conn->real_escape_string($_POST['client']);
        $date = $conn->real_escape_string($_POST['date']);
        $time = $conn->real_escape_string($_POST['time']);
        $paid = $conn->real_escape_string($_POST['paid']);
        $info = $conn->real_escape_string($_POST['info']);
        $visittybe = $conn->real_escape_string($_POST['visittybe']);

        // التحقق مما إذا كان العميل موجودًا
        $rowcl = $conn->query("SELECT * FROM clients WHERE name = '$client'")->fetch_assoc();
        if (!$rowcl) {
            $conn->query("INSERT INTO clients (name) VALUES ('$client')");
            $client = $conn->insert_id;
        } else {
            $client = $rowcl['id'];
        }

        // التحقق مما إذا كان الحجز موجودًا لتجنب التكرار
        $checkDuplicate = "SELECT * FROM reservations WHERE client = '$client' AND date = '$date' AND time = '$time'";
        $resultDuplicate = $conn->query($checkDuplicate);

        if ($resultDuplicate->num_rows == 0) {
            $sql = "INSERT INTO reservations (client, phone, date, time, paid, deserved, visittybe, info) VALUES ('$client', '$phone', '$date', '$time', '$paid', '$deserved', '$visittybe', '$info')";
            $conn->query("INSERT INTO `process`(`type`) VALUES ('add reservation')");

            if ($conn->query($sql) === TRUE) {
                header('Location: ../reservations.php');
                exit();
            } else {
                echo "Error: " . $sql . "<br>" . $conn->error;
            }
        } else {
            echo "The reservation already exists.";
        }
    } else {
        echo "There is a problem: client data is missing.";
    }
}

// إغلاق اتصال قاعدة البيانات
$conn->close();
?>
