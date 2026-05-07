<?php
include('../includes/connect.php');

$name = $_POST['name']; 
// check
$rowchkname = $conn->query("select * from employees where name = '$name'")->fetch_assoc();
if (isset($rowchkname)){echo "<center><h1 class='bg-danger'>يوجد ادخال بهذا الاسم مسجل مسبقا<br>
	<button onclick='history.go(-1);'>back</button>
	</h1></center>
	" ;die;}
$basmaid = $_POST['basmaid'];   
$password = $_POST['password'];
$ent_tybe = $_POST['ent_tybe'];
$hash = md5($password);
$number = $_POST['number']; 
$email = $_POST['email'];
$info = $_POST['info'];
$dateofbirth = $_POST['dateofbirth']; 
$gender = $_POST['gender'];
$address = $_POST['address'];
$town = $_POST['town'];
$address2 = $_POST['address2']; 
$dateofhire = $_POST['dateofhire'];
$dateofend = $_POST['dateofend']; 
$salary = $_POST['salary'];
$jop = $_POST['jop'];
$department = $_POST['department'];
$joptybe = $_POST['joptybe'];
$joplevel = $_POST['joplevel'];
$shift = $_POST['shift'];
$basmaname = $_POST['basmaname'];
$hour_extra = $_POST['hour_extra'];
$day_extra = $_POST['day_extra'];

if ($_FILES['imgs']['size'] !== 0 )  {

$filekvr = $_FILES['imgs']['name'];
$kvr_uploaded_size = $_FILES['imgs']['size'];
$kvr_name = $_FILES['imgs']['tmp_name'];
$arrkvr = explode(".", $filekvr);
$kvr_ext = ($arrkvr['1']);
$allow_ext = ["jpg", "png", "gif", "jpeg"];
if (!in_array($kvr_ext, $allow_ext)) {
	echo $kvr_ext."<h2>الملف المحمل ليس صوره او امتداد غير مسموح به</h2>";
	exit();
}
if ($kvr_uploaded_size > 20000000) {
	echo "<h2>بعض الملفات اكبر من اللازم  20 ميجا بايت </h2>";
	exit();
}
$new_kvr_name = $arrkvr['0'].rand(1, 1000000).".".$arrkvr['1'];
move_uploaded_file($kvr_name, "../assets/$new_kvr_name");


if (isset($_POST['active'])) {
    $active = $_POST['active'];}





$sql ="INSERT INTO employees(name,  info, imgs, email, number, dateofbirth, gender, address, address2, town, jop, department, joptybe, joplevel, dateofhire, dateofend , shift,  salary ,basma_id,password, basma_name ,ent_tybe,hour_extra,day_extra ) VALUES ('$name', '$info', '$new_kvr_name', '$email', '$number', '$dateofbirth', '$gender', '$address', '$address2', '$town', '$jop', '$department', '$joptybe', '$joplevel', '$dateofhire', '$dateofend', '$shift',  '$salary',  '$basmaid',  '$password','$basmaname' , '$ent_tybe','$hour_extra','$day_extra')";
$conn->query($sql);
$empid = $conn->insert_id;

}else{

	if (isset($_POST['active'])) {
		$active = $_POST['active'];}
	
		$sql ="INSERT INTO employees(name, info, email, number, dateofbirth, gender, address, address2, town, jop, department, joptybe, joplevel, dateofhire, dateofend , shift,  salary ,basma_id,password , basma_name , ent_tybe,hour_extra,day_extra) VALUES ('$name', '$info', '$email', '$number', '$dateofbirth', '$gender', '$address', '$address2', '$town', '$jop', '$department', '$joptybe', '$joplevel', '$dateofhire', '$dateofend', '$shift',  '$salary','$basmaid',  '$password','$basmaname' , '$ent_tybe','$hour_extra','$day_extra')";
		$conn->query($sql);
		$empid = $conn->insert_id;
			}


			$sqllst = "SELECT * FROM acc_head where code like '213' AND is_basic = 0 order by id desc";
			$rowlast = $conn->query($sqllst)->fetch_assoc();if ($rowlast != null ) {
			$acccode = explode("213",$rowlast['code']);
			$lstacc = $acccode[1] ;
			$lstacc_int = (int)$lstacc; // Convert to integer
			$lstacc_int++; // Increment
			$lstacc_new = sprintf("%03d", $lstacc_int); // Format back to string with leading zeros
			$last_id = "213".$lstacc_new;}






$sqlemp ="INSERT INTO acc_head (code, aname,is_basic,rentable,is_fund, parent_id, is_stock, secret , kind) VALUES ('$last_id', '$name','0','0','0', '35', '0', '0' , '1')";

$conn->query("INSERT INTO `process`(`type`) VALUES ('add employee')");

header('location:../employees.php');
