<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
      <form  enctype='multipart/form-data' role="form" action="do/doadd_hiringcontract.php" method="POST">




        <div class=" card card-primary">
          <div class="card-header">
            <h3 class="card-title"> <?= $lang_addhicont_newcont ?></h3>
          </div>
          <!-- /.card-header -->
          <!-- form start -->
          <div class="card-body">
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="name"> <?= $lang_addhicont_name ?></label>
                  <input name="name" data-parsley-trigger="keyup" required id="name" type="text" class="form-control" placeholder="<?= $lang_pbholder_refname ?>">
                </div>
                </div>
                <div class="col">

                <div class="form-group">
                  <label for="employee"><?= $lang_addhicont_employee ?></label>
                  <select class="form-control" id="employee" name="employee">
                    <?php
                    $sqlemp = "SELECT * FROM `employees`;";
                    $resemp = $conn->query($sqlemp);
                    while ($rowemp = $resemp->fetch_assoc()) { ?>
                      <option value='<?= $rowemp['id'] ?>'> <?= $rowemp['name'] ?></option>
                    <?php } ?>
                  </select>
                </div>
                </div>
              </div>
            
              <div class="row">

              <div class="col">
              <div class="form-group">
                <label for="jop"><?= $lang_addhicont_jop ?></label>
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

              <div class="col">
              <div class="form-group">
                <label for="jopdescription"><?= $lang_addhicont_jopdescription ?></label>
                <textarea name="jopdescription" data-parsley-trigger="keyup" required id="jopdescription" class="form-control" rows="5"></textarea>
              </div>
              </div>
              </div>



              <div class="row">
                <div class="col">
                <div class="form-group">
                <label for="startcontract"><?= $lang_addhicont_startcont ?></label>
                <input name="startcontract" data-parsley-trigger="keyup" required type="date" id="start" class="form-control" placeholder="<?= $lang_pbholder_enddate ?>">
              </div>  
                  
            </div>


              <div class="col">
              <div class="form-group">
                <label for="endcontract"><?= $lang_addhicont_endcont ?></label>
                <input name="endcontract" data-parsley-trigger="keyup" required type="date" id="endcontract" class="form-control" placeholder="<?= $lang_pbholder_enddate ?>">
              </div>
              </div>
              </div>

              <div class="row">
              <div class="col">
              <div class="form-group">
                <label for="workhours"><?= $lang_addhicont_workhours ?> </label>
                <input name="workhours" data-parsley-trigger="keyup" required type="number" id="workhours" class="form-control" placeholder="<?= $lang_pbholder_workhours ?>">
              </div>
              </div>
                <div class="col">
                  <div class="form-group">
                <label for="inorderhours"><?= $lang_addhicont_inorderhours ?></label>
                <input name="inorderhours" data-parsley-trigger="keyup" required type="number" id="inorderhours" class="form-control" placeholder="<?= $lang_pbholder_whuo ?>">
              </div>
              </div>
              </div>
              <div class="row">
              <div class="col">
                <div class="form-group">
                <label for="workdaysoff"><?= $lang_addhicont_workdaysoff ?></label>
                <input name="workdaysoff" data-parsley-trigger="keyup" required type="number" id="workdaysoff" class="form-control" placeholder=" <?= $lang_pbholder_holi ?>">
              </div>
              </div>
              </div>




              <div class="form-group">
                <label for="info"><?= $lang_addhicont_info ?></label>
                <textarea class="form-control" data-parsley-trigger="keyup" required name="info" id="info" rows="5"></textarea>
              </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <h3>بنود الراتب</h3>
          </div>
          <div class="card-body">
            <div  class="row itemrow bg-warning " id="salary">
             <div class="col">
             <input value="ثابت" class='form-control' type="text" disabled name="" id="salaryinput">

            </div>
            <div class="col">
              <input  class='form-control' type="number" name="salary" id="salaryinput">

            </div>
            </div>
            <div id="allow-fotter"></div>
             

            </div>
            <div class="card-footer">
            <button class="btn btn-info" id="addrow" type="button">             
              <div >اضف بند جديد</div>
              </button>
              </div>
              </div>

                  





          <div class="card card-primary">
            <div class="card-header">
             
            <h4>
               بنود التعاقد 
              </h4>

            </div>
            <div class="card-body">
              
          <div class="row">
            <div class="col">
              <div class="form-group">
                <label for="joprule1"> <?= $lang_addhicont_rule ?> 1</label>
                <input name="joprule1" data-parsley-trigger="keyup" required id="joprule1" type="text" class="form-control" placeholder="<?= $lang_pbholder_rule ?>1">
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
          </div>

          
        </div>
        <!-- /.card-body -->

        <div class=" card-footer">
          <button type="submit" class="btn btn-primary btn-block"><?= $lang_addhicont_confirm ?></button>
        </div>
      </form>
<!-- Content Header (Page header) -->
</section>
</div>
<script>
$("#addrow").click(function(){
  $("#allow-fotter").before(`<div  class="row itemrow " id="itmrow">
             <div class="col">
              <select name="allow[]" id="" class='form-control'>
             <?php
             $sqlallow ="select * from allowances";
             $resallow = $conn->query($sqlallow);
            while ($rowallow = $resallow->fetch_assoc()) {
             ?> 
             <option value="<?= $rowallow['id'] ?>"><?= $rowallow['name'] ?><?php if ($rowallow['tybe'] == 0) {echo " ## استقطاع ##";}else{echo "## بدلات ##";}?>
             
                 </option>

             <?php } ?>
             </select>
            </div>
            <div class="col">
              <input class='form-control' type="number" name="value[]" id="">
            </div>
            
            <div class="col col-1">
            <button class="btn btn-danger btn-danger-row btn-sm" id="delrow" type="button">             
              <div >X</div>
              </button>
            </div>
           
            </div>`);
});
$("#salaryinput").focusout(function(){
  $("#addrow").focus()});
        </script>


        <script>
  $(".btn-danger-row").click(function() {
      $(this).closest(".itemrow").remove(); // Remove the entire row
    });
</script>


<?php include('includes/footer.php') ?>