<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_production.php');

foreach($_POST as $key => $value){
    $$key = $value;
}

$x = count($emp_name);
for ($i=0; $i < $x; $i++) { 
   $sql =  "INSERT INTO productions(snd_id, date, qty, price, value,emp_name, info, info2) VALUES ('$snd_id', '$date', '$qty[$i]', '$price[$i]', '$val[$i]', '$emp_name[$i]', '$info','$info2[$i]')";
   $conn->query($sql);
}
header('location:../production.php');
?>
