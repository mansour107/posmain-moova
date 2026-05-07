<?php

include('../../includes/connect.php');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// اتصال ب Redis
$redis = new Predis\Client();

// جمع البيانات من الطلب
$clname = $_POST['clname'];
$phone = $_POST['phone'];
$phone2 = $_POST['phone2'];
$address = $_POST['address'];
$address2 = $_POST['address2'];
$address3 = $_POST['address3'];

// التحقق من وجود رقم الهاتف في الكاش
$cachedClient = $redis->get("client:phone:$phone");

$response = array();

if ($cachedClient) {
    // رقم الهاتف موجود في الكاش
    $response['exists'] = true;
} else {
    // التحقق من وجود رقم الهاتف في قاعدة البيانات
    $sqlchk = $conn->prepare("SELECT * FROM clients WHERE phone = ?");
    $sqlchk->bind_param("s", $phone);
    $sqlchk->execute();
    $reschk = $sqlchk->get_result();

    if ($reschk->num_rows > 0) {
        // رقم الهاتف موجود في قاعدة البيانات
        $response['exists'] = true;

        // حفظ البيانات في الكاش
        $clientData = $reschk->fetch_assoc();
        $redis->set("client:phone:$phone", json_encode($clientData));
    } else {
        // إدراج البيانات في قاعدة البيانات
        $sql = $conn->prepare("INSERT INTO clients (name, phone, phone2, address, address2, address3) VALUES (?, ?, ?, ?, ?, ?)");
        $sql->bind_param("ssssss", $clname, $phone, $phone2, $address, $address2, $address3);

        if ($sql->execute() === TRUE) {
            $response['exists'] = false;

            // حفظ البيانات في الكاش
            $clientData = array(
                "name" => $clname,
                "phone" => $phone,
                "phone2" => $phone2,
                "address" => $address,
                "address2" => $address2,
                "address3" => $address3
            );
            $redis->set("client:phone:$phone", json_encode($clientData));
        } else {
            echo "Error: " . $sql->error;
        }
    }
}

$conn->close();

echo json_encode($response);
?>
