<?php include('../includes/connect.php');

$emp_id = $_POST['emp_id'];
$kbi_id = $_POST['kbi_id'];
$kbi_weight = $_POST['kbi_weight'];


foreach ($kbi_id as $key => $value) {
    $sql = "INSERT INTO `emp_kbis` (`emp_id`, `kbi_id`, `kbi_weight`) VALUES ('$emp_id', '$value', '$kbi_weight[$key]')";
    $conn->query($sql);
}
header('location:../emp_kbis.php');