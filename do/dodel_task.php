<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_task.php');

$id = $_POST['id'];
$emp_comment = $_POST['emp_comment'];

$conn->query("UPDATE tasks SET isdeleted = 1 , `emp_comment`='$emp_comment' where id = $id");
header('location:../tasks.php');
