<?php include("../includes/connect.php");
echo "<pre>";
print_r($_POST);
echo "</pre>";
$accbegin = $_POST['accbegin'];
$accbegincode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accbegincode == 1 ? $accbegincode = 41101 : $accbegincode ;

$accsale = $_POST['accsale'];
$accsalecode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accsalecode == 1 ? $accsalecode = 41101 : $accsalecode ;

$accresale = $_POST['accresale'];
$accresalecode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accresalecode == 1 ? $accresalecode = 41101 : $accresalecode ;

$accbuy = $_POST['accbuy'];
$accbuycode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accbuycode == 1 ? $accbuycode = 41101 : $accbuycode ;

$accrebuy = $_POST['accrebuy'];
$accrebuycode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accrebuycode == 1 ? $accrebuycode = 41101 : $accrebuycode ;

$accend = $_POST['accend'];
$accendcode = $conn->query("SELECT COALESCE(MAX(code), 0) + 1 AS new_code FROM acc_head WHERE parent_id = 86")->fetch_assoc()['new_code'];
$accendcode == 1 ? $accendcode = 41101 : $accendcode ;





$conn->query("INSERT INTO acc_head (code, aname,is_basic,rentable,is_fund, parent_id, is_stock, secret , kind) VALUES ('$code', '$aname','$is_basic','$rentable','$is_fund', '$parent_id', '$is_stock', '$secret' , '$kind')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add store')");

$journal_lastid =  $conn->insert_id;

