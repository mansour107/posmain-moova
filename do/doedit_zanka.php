<?php
include('../includes/connect.php');
$id = $_GET['id'];
$zname = $_POST['zname'];
$colors = $_POST['colors'];
$ptype = $_POST['ptype'];
$service = $_POST['service'];
$prod = $_POST['prod'];
$measure = $_POST['measure'];
$draw = $_POST['draw'];
$farkh = ceil($draw/$measure) ;
$info = $_POST['info'];
$date = $_POST['date'];
$user = $_POST['user'];
$print = $_POST['print'];
$ctp = $_POST['ctp'];

$sql="UPDATE zankat SET zname='$zname',colors='$colors',ctp='$ctp',print='$print',ptype='$ptype',service='$service',prod='$prod',measure='$measure',draw='$draw',farkh='$farkh',info='$info',date='$date',user='$user' WHERE id = $id";
$conn->query($sql);

 header('location:../zankat.php');
