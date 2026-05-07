<?php
include('../../includes/connect.php');

$resfatdet = $conn->query("SELECT * FROM fat_details where fatid is null ");
$x = 0;
while ($rowfatdet = $resfatdet->fetch_assoc()) {
    $x++;
}
