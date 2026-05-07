<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<style>
    .news_cover{width:25%;height:100%;opacity:0.5;transition: 1s; display: block;
margin-left: auto; margin-right: auto;}
    .news_cover:hover{width:30%;opacity:1;}
</style>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">


<?php 
if (!isset($_GET['id'])) {?>
<h3>يبدو أنك دخلت هذه الصفحةمن المكان الخطأ من فضلك أعد المحاولة في وقت لاحق أو ارجع لمزود الخدمة</h3>
<?php }else{
    $id = $_GET['id'];
    $sqlnew = "SELECT * FROM my_news where id = $id";
    $resnew = $conn->query($sqlnew);
     $rownew = $resnew->fetch_assoc();?>

     <div class="card">
        <div class="card-header">
            <h2><?= $rownew['title'] ?></h2>

        </div>
        <div class="card-body">
            <img class="news_cover" src="uploads/<?= $rownew['img']?>" alt="" onerror="this.src='assets/favicon/favicon.png';">

            <h4><?= $rownew['content']?></h4>
            <p><?= $rownew['tags']?></p>

        </div>
        <div class="card-footer">

        </div>
     </div>


<?php } ?>    
      </div>

    </section>
</div>





<?php include('includes/footer.php') ?>
