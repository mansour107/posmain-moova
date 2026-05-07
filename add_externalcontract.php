<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');









?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">



      <div class=" card card-primary">
        <div class="card-header">
          <h3 class="card-title"> <?= $lang_addhicont_newout ?></h3>
        </div>
        <!-- /.card-header -->
        <!-- form start -->
        <form id="validate_form" role="form" action="do/doadd_hiringcontract.php" method="POST">


          <div class="card-body">


            <div class="row">


              <div class="col">

                <div class="form-group">
                  <label for="name"> <?= $lang_addhicont_name ?></label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" placeholder="<?= $lang_pbholder_refname ?>">
                </div>



                <div class="form-group">
                  <label for="jop"><?= $lang_addhicont_jop ?></label>
                  <div class="form-group">
                    <select class="form-control" id="jop" name="jop">
                      <?php
                      $sqljop = "SELECT * FROM `jops`;";
                      $resjop = $conn->query($sqljop);
                      while ($rowjop = $resjop->fetch_assoc()) { ?>
                        <option value='<?= $rowjop['id'] ?>'> <?= $rowjop['name'] ?></option>
                      <?php } ?>
                    </select>
                  </div>
                </div>


                <div class="form-group">
                  <label for="jopdescription"><?= $lang_addhicont_jopdescription ?></label>
                  <textarea name="jopdescription" data-parsley-trigger="keyup" required id="jopdescription" class="form-control" rows="5"></textarea>
                </div>


                <div class="form-group">
                  <label for="salary"><?= $lang_addhicont_salaryagreement ?></label>
                  <input name="salary" data-parsley-trigger="keyup" required type="number" id="salary" class="form-control" placeholder="<?= $lang_pbholder_amount ?>">
                </div>


                <div class="form-group">
                  <label for="endcontract"><?= $lang_addhicont_endcont ?></label>
                  <input name="endcontract" data-parsley-trigger="keyup" required type="date" id="endcontract" class="form-control" placeholder="<?= $lang_pbholder_enddate ?>">
                </div>

              </div>
              <div class="col">

                <div class="form-group">
                  <label for="workhours"><?= $lang_addhicont_workhours ?> </label>
                  <input name="workhours" data-parsley-trigger="keyup" required type="number" id="workhours" class="form-control" placeholder="<?= $lang_pbholder_workhours ?>">
                </div>


                <div class="form-group">
                  <label for="inorderhours"><?= $lang_addhicont_inorderhours ?></label>
                  <input name="inorderhours" data-parsley-trigger="keyup" required type="number" id="inorderhours" class="form-control" placeholder="<?= $lang_pbholder_whuo ?>">
                </div>


                <div class="form-group">
                  <label for="workdaysoff"><?= $lang_addhicont_workdaysoff ?></label>
                  <input name="workdaysoff" data-parsley-trigger="keyup" required type="number" id="workdaysoff" class="form-control" placeholder=" <?= $lang_pbholder_holi ?>">
                </div>


                <div class="form-group">
                  <label for="info"><?= $lang_addhicont_info ?></label>
                  <textarea class="form-control" data-parsley-trigger="keyup" required name="info" id="info" rows="5"></textarea>
                </div>




                <div class="form-group">
                  <label for="user"><?= $lang_addhicont_user ?></label>
                  <input name="user" data-parsley-trigger="keyup" required type="number" id="user" class="form-control" placeholder="<?= $lang_pbholder_user ?>">
                </div>

                <div class="form-group">
                  <label for="startcontract"><?= $lang_addhicont_startcont ?></label>
                  <input name="startcontract" data-parsley-trigger="keyup" required type="date" id="startcontract" class="form-control" placeholder=" <?= $lang_pbholder_startdate ?>">
                </div>

              </div>

            </div>
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="joprule1"> <?= $lang_addhicont_rule ?> 1</label>
                  <input name="joprule1" data-parsley-trigger="keyup" requiredid="joprule1" type="text" class="form-control" placeholder="<?= $lang_pbholder_rule ?>1">
                </div>
                <div class="form-group">
                  <label for="joprule2"> <?= $lang_addhicont_rule ?> 2</label>
                  <input name="joprule2" id="joprule2" type="text" class="form-control" placeholder="<?= $lang_pbholder_rule ?>2">
                </div>
                <div class="form-group">
                  <label for="joprule3"> <?= $lang_addhicont_rule ?> 3</label>
                  <input name="joprule3" id="joprule3" type="text" class="form-control" placeholder="<?= $lang_pbholder_rule ?>3">
                </div>
                <div class="form-group">
                  <label for="joprule4"> <?= $lang_addhicont_rule ?> 4</label>
                  <input name="joprule4" id="joprule4" type="text" class="form-control" placeholder="<?= $lang_pbholder_rule ?>4">
                </div>
              </div>
            </div>
          </div>
          <!-- /.card-body -->

          <div class=" card-footer">
            <button type="submit" class="btn btn-primary btn-block"><?= $lang_addhicont_confirm ?></button>
          </div>
        </form>
      </div>
    </div>
    <!-- Content Header (Page header) -->
  </section>
</div>

<script>
  $(document).ready(function() {
    $("#validate_form").parsley();
  })
</script>


<?php include('includes/footer.php') ?>