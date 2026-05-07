<?php
session_start();
include('../includes/connect.php');
$usid = $_SESSION['userid'];
    
    $iname = $_POST['iname']; 
    $title = $_POST['title'];
    $tags = $_POST['tags'];
    $content = $_POST['content'];
    $user = $usid;
    
if(isset($_FILES['img']['name'])){
    $sql="INSERT INTO  my_news (title , tags ,  content ,  user) VALUES ('$title' , '$tags' ,  '$content' ,  '$user')";

}else{
    $img_name = $_FILES['img']['name'];
    $tmp_name =$_FILES['img']['tmp_name'];

    $arrkvr = explode(".", $img_name);
    $kvr_ext = end($arrkvr);
    $allow_ext = ["jpg", "png", "gif", "jpeg","webp"];
    if (!in_array($kvr_ext, $allow_ext)) {
        echo $kvr_ext."<h2>الملف المحمل ليس صوره او امتداد غير مسموح به</h2>";
        exit();}
        
    
    $new_kvr_name = $arrkvr['0'].rand(1, 1000000).".".$kvr_ext;
    move_uploaded_file($tmp_name, "../uploads/$new_kvr_name");
   
    
    $sql="INSERT INTO  my_news (title ,  img ,  tags ,  content ,  user) VALUES ('$title' ,  '$new_kvr_name' ,  '$tags' ,  '$content' ,  '$user')";
}
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add news')");


header('location:../news.php');
?>
