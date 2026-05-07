<?php include('includes/header.php');?>
<?php include('includes/connect.php');?>
<?php 
$id = $_GET['id'];

?>
<style>
    h1{
        color:red;
        font-size:10vw
    }
    .btn{
        font-size:8vw;
    }
</style>
<center>
<h1>تحذير</h1>
<h2>انت علي وشك مسح او تعديل الزنكه و لا يمكنك الرجوع لهذه المعلومات مره اخري</h2>
<h2>هل تريد فعلا مسح البيانات؟</h2>
<h1>
<a href="zankat.php"><div class="btn btn-secondary btn-lg"> رجوع</div></a>
<a href="edit_zanka.php?id=<?= $id ?>"><div class="btn btn-warning btn-lg"> تعديل</div></a>
<a href="do/do_delzanka.php?id=<?= $id ?>"><div class="btn btn-danger btn-lg">مسح </div></a>
</center></h1>
<?php include('includes/footer.php');?>



