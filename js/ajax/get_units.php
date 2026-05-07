<?php
include('../../includes/connect.php');

// التحقق من وجود item_id
if (isset($_GET['item_id'])) {
    $itemId = intval($_GET['item_id']);

    // استعلام SQL لجلب الوحدات الخاصة بالمنتج
    $sql = "SELECT * FROM item_units WHERE item_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $result = $stmt->get_result();








    $data = array();
    while ($row = $result->fetch_assoc()) {
        $data[] = array("id" => $row['id'], "uname" => $row['uname']);
    }

    $stmt->close();
    $conn->close();

    // إرجاع البيانات كـ JSON
    header('Content-Type: application/json');
    echo json_encode($data);
} else {
    // إرجاع رسالة خطأ في حال عدم وجود item_id
    echo json_encode(array("error" => "No item_id provided."));
}
?>
