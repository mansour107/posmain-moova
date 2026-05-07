<?php
include '../../includes/connect.php'; // الاتصال بقاعدة البيانات

if (isset($_POST['parent_id'])) {
    $parentId = intval($_POST['parent_id']);
    $query = "SELECT id, aname FROM acc_head WHERE isdeleted = 0 AND perant_id = $parentId ORDER BY id DESC";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        echo '<option value="">اختر الحساب</option>';
        while ($row = $result->fetch_assoc()) {
            echo '<option value="' . $row['id'] . '">' . $row['aname'] . '</option>';
        }
    } else {
        echo '<option value="">لا توجد حسابات متاحة</option>';
    }
}
?>
