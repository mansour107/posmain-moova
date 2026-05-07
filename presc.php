<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>


<body>





<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        
      <div  class="invoice p-3 mb-3">
              <!-- title row -->
              <div class="row">
                
              <?php
                if (isset($_GET['id'])) {
                  $prscid = $_GET['id'];
                    $sqlprsc = "SELECT * FROM prescs where id = $prscid";
                    echo $sqlprsc;
                    $resprsc = $conn->query($sqlprsc);
                    if ($resprsc->num_rows == 0 ){echo "لا يوجد روشتات بهذا الرقم ";}else{
                    $rowprsc = $resprsc->fetch_assoc();
                    $client = $rowprsc['client'];
                    $rowcl = $conn->query("SELECT * FROM clients where id = $client ")->fetch_assoc();
                
                ?>
                  <div class="col col-3 h3"><?= $rowcl['name']?></div>

              </div>
              <!-- /.row -->
              
                <div class="col col-3 h3">التحاليل المطلوبه</div>
                <br>
                <div class="col h6 bg-slate-100"><?= $rowprsc['analayses']?></div>
                <br>
                <div class="col col-3 h3">الادويه المطلوبة</div>
                    <?php
                    $prescid = $rowprsc['id'];
                    $resprscdet = $conn->query("SELECT * FROM prescdetails WHERE prescid = $prescid ");
                    $x=0;

                    while ($rowprscdet = $resprscdet->fetch_assoc()) {

                    ?>
                    
                      <?php
                      $drugid = $rowprscdet['drug']; 
                      $rowdrg = $conn->query("SELECT * FROM drugs WHERE id = $drugid")->fetch_assoc();
                      ?>
                      <div class="col h6 bg-slate-50"><?= $rowdrg['name'];?></div>
                      <div class="col h6 bg-slate-50"><?= $rowprscdet['dose']?></div>
                    
                   
                    <?php }?>

              <!-- this row will not appear when printing -->
              <div class="row no-print">
                <div class="col col-10">
                  <a href="print/presc_print.php?id=<?= $rowprsc['id']?>" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Print</a>
                 
                </div>
                <div class="col ">
                  

                <a href="http://wa.me/2<?= $rowcl['phone']?>&text=<?php 
                
                while ($rowprscdet = $resprscdet->fetch_assoc()) {

                $drugid = $rowprscdet['drug']; 
                  $rowdrg = $conn->query("SELECT * FROM drugs WHERE id = $drugid")->fetch_assoc();
                  echo $rowdrg['name'];
               echo "<br>";
               echo $rowprscdet['dose'];}
                ?>" target="_blank" class="btn btn-success"><i class="fa fa-comment" aria-hidden="true"></i> send watts</a>
                </div>
              </div>
            </div>


<?php }} ?>

</div>
</section>
</div>


</body>




<?php include('includes/footer.php') ?>