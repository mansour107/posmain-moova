<?php include('../includes/connect.php');
$clid = $_GET['id'];

$name = $_POST['name'];
$phone = $_POST['phone'];
$dateofbirth = $_POST['dateofbirth'];
$gender = $_POST['gender'];
$address = $_POST['address'];
$city = $_POST['city'];
$diseses = $_POST['diseses'];
$drugs = $_POST['drugs'];
$seriousdes = $_POST['seriousdes'];
$familydes = $_POST['familydes'];
$allergy = $_POST['allergy'];
$diabetes = $_POST['diabetes'];
$pressure = $_POST['pressure'];
$brate = $_POST['brate'];
$temp = $_POST['temp'];
$height = $_POST['height'];
$weight = $_POST['weight'];
$ref = $_POST['ref'];
$info = $_POST['info'];

$sql = "UPDATE clients SET name='$name', phone='$phone', address='$address', city ='$city',height='$height',weight='$weight',dateofbirth='$dateofbirth',ref='$ref',diseses='$diseses',info='$info',gender='$gender',drugs='$drugs',seriousdes='$seriousdes',familydes='$familydes',allergy='$allergy',temp='$temp',pressure='$pressure',diabetes='$diabetes',brate='$brate' WHERE id = $clid";
$conn->query($sql);

header("location:../clprofile.php?id=".$clid);

?>