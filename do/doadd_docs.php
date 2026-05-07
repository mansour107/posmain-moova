<?php
include('../includes/connect.php');
echo '<pre>';
print_r($_POST);print_r($_GET);print_r($_FILES);
echo '</pre>';
$clprofile = $_GET['id'];
$img_date = $_POST['img_date'];





$img_date = $_POST['img_date'];
$imgs_name = $_FILES['imgs']['name'];
    if ( !empty($imgs_name['0']) ) {

    for ($i=0; $i < count($_FILES['imgs']['name']) ; $i++) { 

    $imgs_name = $_FILES['imgs']['name'][$i];
    $imgs_size = $_FILES['imgs']['size'][$i];
    $tmp_name  = $_FILES['imgs']['tmp_name'][$i];

    $arrkvr = explode(".", $imgs_name);
    $kvr_ext = end($arrkvr);
    // print_r($arrkvr);
    // echo "<br>";
    $allow_ext = ["jpg", "png", "gif", "jpeg","webp"];
    if (!in_array($kvr_ext, $allow_ext)) {
        echo $kvr_ext."<h2>الملف المحمل ليس صوره او امتداد غير مسموح به</h2>";
        exit();}
    $new_kvr_name = $arrkvr['0'].rand(1, 1000000)."-".$clprofile."-".".".$kvr_ext;
    move_uploaded_file($tmp_name, "../uploads/$new_kvr_name");
    $conn->query("INSERT INTO  imgs ( iname , size ,  clprofile ,  img_date ) VALUES ('$new_kvr_name','$imgs_size','$clprofile','$img_date')");
    }}else{echo "لا يوجد بيانات لحفظها";}
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add docs')");


    header("location:../clprofile.php?id=$clprofile");