<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/connect.php') ?>
<?php include('includes/userprev.php') ?>


<style>
.error{font-size:5vw;}
.error2{font-size:4vw}
span{color:red}
.vtn{font-size:4vw;}
</style>

<center>
<div class="error">
    <?=$lang_warningmsg3?><span><?=$up['uname'] ?></span>
</div>
<div class="error2" >
<?=$lang_warningmsg4?>
</div>
<br>
<br>
<br>
<a class="btn btn-warning vtn" href="dashboard.php"><?=$lang_main_back?></a>
<br>
<br>
<script>
    document.write('<a class="btn btn-info vtn" href="' + document.referrer + '">الرجوع للخلف</a>');
</script>
</center>
<br>
<br>
<br>

<?php include('includes/footer.php') ?>