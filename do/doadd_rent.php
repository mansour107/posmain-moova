<?php include('../includes/connect.php');
echo "<pre>";
print_r($_POST);
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$cl_id = $_POST['cl_id'];
$rent_id = $_POST['rent_id'];
$phone = $_POST['phone'];
$idintity = $_POST['idintity'];
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$pay_tybe = $_POST['pay_tybe'];
$r_value = $_POST['r_value'];
$bnd1 = $_POST['bnd1'];
$bnd2 = $_POST['bnd2'];
$bnd3 = $_POST['bnd3'];
$bnd4 = $_POST['bnd4'];
$sql = "INSERT INTO myrents(cl_id, rent_id, phone, idintity, start_date, end_date, pay_tybe, r_value, bnd1, bnd2, bnd3, bnd4) VALUES ('$cl_id', '$rent_id', '$phone', '$idintity', '$start_date', '$end_date', '$pay_tybe', '$r_value', '$bnd1', '$bnd2', '$bnd3', '$bnd4')";
// Assuming $conn is your MySQLi connection object and $sql is your SQL query

$result = $conn->query($sql);
$contract = $conn->insert_id;


// الاقساط
$start_date_diff = new DateTime($start_date);
$end_date_diff = new DateTime($end_date);

$interval = DateInterval::createFromDateString('1 month');
$period = new DatePeriod($start_date_diff, $interval, $end_date_diff);

foreach ($period as $dt) {
 $ins_date =  $dt->format('Y-m-01') . "\n";
$conn->query("INSERT INTO  myinstallments (cl_id ,  rent_id ,  contract ,ins_value ,  ins_date ,  ins_case ,  ins_paid ) VALUES ('$cl_id','$rent_id','$contract','$r_value','$ins_date','1','0')");
}
if (!$result) {
    echo "يوجد خطأ ما ";
} else {
$conn->query("UPDATE acc_head set rentable = 2 where id = $rent_id");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add rent')");

header('location:../add_rent.php?res=s');
}
