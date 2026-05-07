<?php include('../../includes/head.php') ?>
<?php include('../../includes/connect.php') ?>
<style>
  .banner{
    width:100%;
  }
  .bannerfooter{
    width:100%;
    position:fixed;
    bottom:0px;
  }

</style>
<body onload="window.print();">
  
      <div  class="invoice p-3 mb-3">
             
              <div class="row">
                <div class="banner" >
                <img src="../assets/print/header.jpeg" alt="" class="" style="width:90%;border:1px solid black;">
                </div>
              </div>
              <div class="row">
                <div class="col-lg-6">
                  <h1>التحاليل المطلوبه::</h1>
                <br>
                </div>
                <?php
                if (isset($_GET['id'])) {
                  $prscid = $_GET['id'];
                    $sqlprsc = "SELECT * FROM prescs where id = $prscid";
                    $resprsc = $conn->query($sqlprsc);
                    $rowprsc = $resprsc->fetch_assoc();
                }
                ?>
                <div class="col h4"><?= $rowprsc['analayses']?></div>
              </div>
              <h1>الادوية::</h1>
              <br>
              <br>
              
             
               <ul>
               <?php
                    $prescid = $rowprsc['id'];
                    $resprscdet = $conn->query("SELECT * FROM prescdetails WHERE prescid = $prescid ");
                    $x=0;
                    while ($rowprscdet = $resprscdet->fetch_assoc()) {
                    ?>
                <li>
                  <h3><?php
                      $drugid = $rowprscdet['drug']; 
                      $rowdrg = $conn->query("SELECT * FROM drugs WHERE id = $drugid")->fetch_assoc();
                      echo $rowdrg['name'];
                      ?></h3>
                      <h3>
                        ::__<?= $rowprscdet['dose']?>

                      </h3>
                </li>
                <br>
                <?php }?>
               </ul>
               <div class="bannerfooter" >
                <img src="../assets/print/footer.jpeg" alt="" class="" style="width:90%;border:1px solid black;">
                </div>
                   
              <!-- this row will not appear when printing -->
              <div class="row no-print">
                <div class="col-12">
                  <a href="invoice-print.html" target="_blank" class="btn btn-secondary"><i class="fas fa-print"></i> Print</a>
                 
              </div>
            </div>


            <div class="bannerfotter">
              <img src="assets/footer.jpeg" alt="">
            </div>
</body>




<?php include('../includes/foot.php') ?>