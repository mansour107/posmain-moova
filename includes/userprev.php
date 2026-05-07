<?php
include('includes/connect.php');
$userIdForPrev = $_SESSION['userid'];
$sqlusersprev = "select * from users where id = $userIdForPrev ";
$resusersprev = $conn->query($sqlusersprev);
$up = $resusersprev->fetch_assoc();  
