<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<style>
    .card{
        position: relative;
    }
    .news_cover{
        width:100%;
        height:100%;
        opacity:0.5;
        transition: 1s;

    }
    .title{position: absolute; background-color: white; margin-left:20px;  top: 80%;  width:90%;  opacity: .4;  border: 1px solid white;  transition: 0.5s;}
    .news_cover:hover{width:103%;opacity:0.7;}
    .title:hover{position:absolute;background-color: white;opacity: .9;  border: 4px solid white;}
</style>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
        <div class="card">
           <div class="card-header">
            <a href="add_news.php" target="_blank" rel="noopener noreferrer">Add</a>
           </div>
           <div class="row">
           <?php 
           $sqlnew = "SELECT * FROM my_news where isdeleted != 1 order by id desc";
           $resnew = $conn->query($sqlnew);
           while ($rownew = $resnew->fetch_assoc()) {
           ?>
            <div class="col-lg-3">
            <a href="blogcontent.php?name=<?= $rownew['title'] ?>&id=<?= $rownew['id'] ?>">

                <div class="card-body">
                    <img src="uploads/<?= $rownew['img'] ?>" title="<?= $rownew['content'] ?>" class="news_cover" onerror="this.src='assets/favicon/favicon.png';">
                    
                </div>
                <center>
                    <h3 class="title"><?= $rownew['title']?></h3>
                    </center>
                    </a>

            </div>

            <?php } ?>
        










        </div>

    </section>
</div>





<?php include('includes/footer.php') ?>
