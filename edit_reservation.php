<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php
$id = $_GET['id'];
$rowrsv = $conn->query("SELECT * FROM reservations WHERE id= $id")->fetch_assoc();
?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">

 

      <div class=" card card-warning">
        <div class="card-header">
          <h2>  تعديل الحجز </h2>
        </div>
   

        <form id="validate_form" action="do/doedit_reservation.php?id=<?= $rowrsv['id']?>" autocomplete="off"  method="POST">

       
          <div class="card-body">
          <div class="row">
                  <div class="col">
                    <label for="">الاسم</label>
                  <div class="input-group mb-3">
                  <?php
                    $cl = $rowrsv['client'];
                    $rowcl = $conn->query("SELECT name FROM clients WHERE id= $cl")->fetch_assoc();
                    ?> 

                  
                  <input name="client"  required data-parsley-trigger="keyup" list="clientslist" required id="client" onkeyup="showHint(this.value)"  type="text" class="form-control" value="<?= $rowcl['name']?>">
                  <datalist  id="clientslist">
                

                
                  </datalist>


                  
                  <div class="input-group-append">
                    <a class="btn btn-warning" href="add_client.php" target="_blank">جديد</a>
                    </div>
                </div>
                  </div>
                    </div>
                    <div class="row">
                  <div class="col">

                  <div class="bg-info " >
                    <h4 id="clinfo">

                    </h4>
                    
                  </div>
                  </div>
                  </div>
                  

                    </div>
                    <div class="row">  
                    <div class="col">              
                <div class="form-group">
                  <label for="address">التاريخ</label>
                  <input name="date"   type="date" id="curdate" class="form-control"  value="<?= $rowrsv['date'] ?>">
                </div>
                </div>
        

              <div class="col">  
            
                <div class="form-group">
                <label for="address">الوقت</label>
                  <select name="time" id="" class="form-control">
                  <?php
                    for ($h = 10; $h < 24; $h++) { 
                        for ($m = 0; $m < 60; $m += 15) {
                            $hour_display = ($h % 12 == 0) ? 12 : $h % 12; // Convert 24-hour to 12-hour format
                            $am_pm = ($h < 12) ? "AM" : "PM"; // Determine if it's AM or PM
                            
                    ?>
                      <option value="<?= sprintf("%02d:%02d", $h, $m) ?>" class="<?php 
                        $time1 = sprintf("%02d:%02d:00", $h, $m); // Construct time string for the query
                        $resvst = $conn->query("SELECT * FROM reservations WHERE time = '$time1'");
                        if ($resvst->num_rows > 0) {
                            echo 'bg-warning';
                        }
                    ?>" ><?= sprintf("%02d:%02d %s", $hour_display, $m, $am_pm) ?></option>
                    <?php 
                        }
                    }
                    ?>
                  </select> </div>
                </div>
                </div>

                <div class="row">
                  <div class="col">
                    <label for="">نوع الزيارة</label>
                  <select class="form-control" name="visittybe" id="visittybe">
                    <?php 
                    $resvt = $conn->query("SELECT * FROM `visittybes`");
                    while ($rowvt = $resvt->fetch_assoc()) {
                    ?>
                    <option value="<?=$rowvt['id']?>" <?php ($rowrsv['tybe'] = $rowvt['id']) ?  "selected"  : "" ?> value2="<?=$rowvt['value']?>"><?=$rowvt['name']?></option>


                    <?php } ?>
                  </select>
                  </div>
                  <div class="col">
                  <div class="form-group">
                  <label for="address">المدفوع</label>
                  <input name="paid" data-parsley-trigger="keyup" required type="number" id="paid" class="form-control" value="<?= $rowrsv['paid']?>">
                </div>
                  
                </div>
                </div>

                  <div class="row">
                    <div class="col">
                      
                 <div class="form-group">
                  <label for="info">ملاحظات</label>
                  <textarea class="form-control" data-parsley-trigger="keyup"  name="info" id="info" rows="2"><?= $rowrsv['info']?></textarea>
                </div>

                    </div>
                  </div>
                


          <!-- /.card-body -->

          <div class=" card-footer">
            <button type="submit" class="btn btn-warning btn-block"><?= $lang_addhicont_confirm ?></button>
          </div>
        </form>
        </div>
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
<script>
$('#client').focusout(function () {
        const xhttp = new XMLHttpRequest();
        $iname = $("#client").val();
    xhttp.onload = function() {
      
      if (xhttp.readyState == 2) {
        $("#clinfo").html("loading .............");

      }
      if (xhttp.readyState == 4) {
document.getElementById("clinfo").innerHTML = xhttp.responseText;        
      }
    }
    xhttp.open("GET", "js/ajax/getclientinfo.php?iname="+$iname,true);
    xhttp.send();
    });
</script>

                <script>
                  document.getElementById('curdate').valueAsDate = new Date();
                </script>
<script>
$(document).ready(function() {
    $('#visittybe').change(function() {
        var selectedOption = $(this).find('option:selected'); // Get the selected option
        var value2 = selectedOption.attr('value2'); // Get the value from the value2 attribute
        $('#paid').val(value2); // Update the value of the "المدفوع" input field
    });
});
</script>



<script>
  $('#client').keyup(function() {
    var clientValue = $(this).val(); // Get the value of the input field
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function() {
      if (this.readyState == 4 && this.status == 200) {
        document.getElementById("clientslist").innerHTML = this.responseText;
      }
    };
    xmlhttp.open("GET", "js/ajax/search_clients.php?q=" + clientValue, true);
    xmlhttp.send();
  });
</script>

<?php include('includes/footer.php') ?>