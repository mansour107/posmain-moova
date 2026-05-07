<?php
include('../includes/connect.php');
$resitm = $conn->query("SELECT * FROM myitems ORDER BY iname");
while ($rowitm = $resitm->fetch_assoc()) {
    echo '<option value="">اختر صنف</option>';
    echo '<option value="' . $rowitm['id'] . '">' . $rowitm['iname'] . '</option>';
}
