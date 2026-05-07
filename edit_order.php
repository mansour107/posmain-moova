<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">

      <div class=" card card-warning">
        <div class="card-header ">
          <h3 class="card-title "> <?= $lang_editOrder ?> </h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form role="form" action="do/doadd_order.php" method="post">
          <div class="card-body">
            <!-- First Row -->
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label> <?= $lang_choiceemployee ?></label>
                  <select name='employee' class="custom-select">
                    <?php
                    $sqlemp = "select * from employees order by name";
                    $resemp = $conn->query($sqlemp);
                    while ($rowemp = $resemp->fetch_assoc()) { ?>
                      <option value='<?= $rowemp['id'] ?>'> <?= $rowemp['name'] ?></option>
                    <?php } ?>

                  </select>
                </div>
              </div>
              <div class="col">
                <div class="form-group">
                  <label> <?= $lang_typeOrder ?></label>
                  <select name='tybe' class="custom-select">
                    <?php
                    $sqltype = "select * from order_types order by name";
                    $restype = $conn->query($sqltype);
                    while ($rowtype = $restype->fetch_assoc()) { ?>
                      <option value='<?= $rowtype['id'] ?>'> <?= $rowtype['name'] ?></option>
                    <?php } ?>

                  </select>
                </div>
              </div>
              <div class="col">

                <div class="form-group">
                  <label> <?= $lang_StatusOrder ?></label>
                  <select name='status' class="custom-select">
                    <?php
                    $sqlstatus = "select * from order_status order by name";
                    $resstatus = $conn->query($sqlstatus);
                    while ($rowstatus = $resstatus->fetch_assoc()) { ?>
                      <option value='<?= $rowstatus['id'] ?>'> <?= $rowstatus['name'] ?></option>
                    <?php } ?>

                  </select>
                </div>

              </div>
            </div>
            <!-- Second Row -->
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="applyingdate"> <?= $lang_Submissiondate ?></label>
                  <input type="date" class="form-control" name="applyingdate" id="applyingdate" placeholder=" ">
                </div>
              </div>
              <div class="col">
                <div class="form-group">
                  <label for="curdate"><?= $lang_Startingdate ?></label>
                  <input type="date" class="form-control" name="curdate" id="curdate" placeholder=" ">
                </div>
              </div>
            </div>


          </div>
          <!-- /.card-body -->

          <div class="card-footer">
            <button type="submit" class="btn btn-warning btn-block"><?= $lang_publicconfirm ?></button>
          </div>
        </form>
      </div>
    </div>
    <!-- Content Header (Page header) -->
  </section>
</div>



<?php include('includes/footer.php') ?>