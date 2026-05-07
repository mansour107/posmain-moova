<?php 
include('../../includes/connect.php');

$iname = $_GET['iname'];
$sqlcl = "select * from clients where name = '$iname'";
$rescl = $conn->query($sqlcl);
$rowcl = $rescl->fetch_assoc();
if (!empty($rowcl)) { 
  echo  "التليفون: " .$rowcl['phone'];

}else {
    echo "لا يوجد اسم في قاعده البيانات مشابه سيتم حفظ جديد";


}