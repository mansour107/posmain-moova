<?php
include('../includes/connect.php');
$uname = $_POST['uname'];
$password = $_POST['password'];
// استخدام password_hash للتشفير الآمن بدلاً من MD5
$hashpass = md5($password);
$usertype = $_POST['usertype'];
$usertype = $_POST['userrole'];
$is_waiter = isset($_POST['is_waiter']) ? 1 : 0;

if ($_FILES['img']['size'] > 100 )  {
$filekvr = $_FILES['img']['name'];
$kvr_uploaded_size = $_FILES['img']['size'];
$kvr_name = $_FILES['img']['tmp_name'];
$arrkvr = explode(".", $filekvr);
$kvr_ext = ($arrkvr['1']);
$allow_ext = ["jpg", "png", "gif", "jpeg"];
if (!in_array($kvr_ext, $allow_ext)) {
	echo $kvr_ext."<h2>الملف المحمل ليس صوره او امتداد غير مسموح به</h2>";
	exit();
}
if ($kvr_uploaded_size > 2000000) {
	echo "<h2>بعض الملفات اكبر من اللازم  20 ميجا بايت </h2>";
	exit();
}
$new_kvr_name = $arrkvr['0'].rand(1, 1000000).".".$arrkvr['1'];
move_uploaded_file($kvr_name, "../uploads/$new_kvr_name");


$sql ="INSERT INTO users (uname , password , userrole , img , is_waiter) VALUES ('$uname','$hashpass','$usertype' , '$new_kvr_name', $is_waiter)";
}else{
	$sql ="INSERT INTO users (uname , password , userrole , is_waiter) VALUES ('$uname','$hashpass','$usertype', $is_waiter)";
}
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add user')");

header('location:../users.php');
?>



