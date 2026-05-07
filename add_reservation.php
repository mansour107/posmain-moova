<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<style>
  #addClientForm input{
    width:100%;
    border-style: solid;
    border-width:0px 0px 1px 0px;
  }
  #addClientForm input:focus{
    width:100%;
    border-style: none;
    border-width:0px 0px 2px 0px;
    background-color: azure;
  }
</style>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">



      <div class="card card-primary">
      <div class="card-header">
        <div class="row">
          <div class="col"><h1 >حجز جديد</h1></div>
          <div class="col"><a href="reservations.php" class="text-sky-500 float-right">كل الحجوزات</a></div>
          </div>
        </div>
       
        <form id="validate_form" action="do/doadd_reservation.php" autocomplete="off" method="POST">
          <div class="card-body">
            <div class="row">
              <div class="col-lg-12">
                <label for="">الاسم</label>
                <div class="input-group">
                  <input name="client" required list="clientslist" id="client" type="text" class="form-control" >
                  <br>
                  <datalist id="clientslist"></datalist>
                  <br>
                  <div class="input-group-append">
                    <a class="btn btn-primary wewe" href="#" data-toggle="modal" data-target="#addClientModal">جديد</a>
                  </div>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="col">
                <div class="bg-lime-200">
                  <h4 id="clinfo"></h4>
                </div>
              </div>
            </div>
          
          <div class="row">
            <div class="col-md-4">
              <div class="form-group">
                <label for="address">التاريخ</label>
                <input name="date" type="date" id="curdate" class="form-control">
              </div>
            </div>
            <div class="col-md-3">
              <div class="form-group">
                <label for="address">الوقت</label>
                <select name="time" id="" class="form-control">
                  <?php
                  for ($h = 10; $h < 24; $h++) {
                      for ($m = 0; $m < 60; $m += 15) {
                          $hour_display = ($h % 12 == 0) ? 12 : $h % 12; // Convert 24-hour to 12-hour format
                          $am_pm = ($h < 12) ? "AM" : "PM"; // Determine if it's AM or PM
                  ?>
                    <option value="<?= sprintf("%02d:%02d", $h, $m) ?>" class="bg-green-100 <?php
                      $time1 = sprintf("%02d:%02d:00", $h, $m); // Construct time string for the query
                      $today = date('Y-m-d');
                      $sqlecho = "SELECT * FROM reservations WHERE time = '$time1' and date = '$today'";
                      $resvst = $conn->query("SELECT * FROM reservations WHERE time = '$time1' and date = '$today'");
                    
                      if ($resvst->num_rows > 0) {
                          echo ' bg-orange-300 ';
                      }
                      if ($resvst->num_rows > 1) {
                        echo ' bg-red-500 ';
                    }
                  ?>"><?= sprintf("%02d:%02d %s", $hour_display, $m, $am_pm) ?></option>
                  <?php
                      }
                  }
                  ?>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-3">
              <label for="">نوع الزيارة</label>
              <select class="form-control" name="visittybe" id="visittybe">
                <?php
                $resvt = $conn->query("SELECT * FROM `visittybes`");
                while ($rowvt = $resvt->fetch_assoc()) {
                ?>
                  <option value="<?= $rowvt['id'] ?>" value2="<?= $rowvt['value'] ?>"><?= $rowvt['name'] ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-2">
              <div class="form-group">
                <label for="address">المدفوع</label>
                <input name="paid" data-parsley-trigger="keyup" required type="number" id="paid" class="form-control" value="400">
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col">
              <div class="form-group">
                <label for="info">ملاحظات</label>
                <textarea class="form-control" data-parsley-trigger="keyup" name="info" id="info" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <button type="submit" class="btn btn-primary btn-block"><?= $lang_addhicont_confirm ?></button>
          </div>
          
        </form>
      </div>














      <!-- Modal structure -->
      <div class="modal fade" id="addClientModal" tabindex="-1" role="dialog" aria-labelledby="addClientModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
          <div class="modal-content">
            <div class="modal-header bg-sky-100">
              <h5 class="modal-title" id="addClientModalLabel">اضافة مريض جديد</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <form id="addClientForm" method="post">
              <div class="modal-body">
                <!-- Form content for adding a new client -->
                <div class="form-group">
                  <label for="name">الاسم</label>
                  <input placeholder="ادخل اسم العميل" class="col-lg-6" type="text" name="name" required id="clientModal" >
                  <datalist id="clientslistModal"></datalist>
                </div>
                <div class="form-group">
                  <label for="phone">phone</label>
                  <input placeholder="ادخل تليفون" class="col-lg-6" type="text" name="phone" id="phone" required>
                </div>
                <div class="form-group">
                  <label for="city">المدينه</label>
                  <select class="col-lg-6" name="city" id="city" >
                    <?php while ($rowtwn = $restwn->fetch_assoc()) { ?>
                      <option value="<?= $rowtwn['id'] ?>"><?= $rowtwn['name'] ?></option>
                    <?php } ?>
                  </select>
                </div>
                <div class="form-group">
                  <label for="address">العنوان</label>
                  <input placeholder="ادخل العنوان" class="col-lg-6" type="text" name="address" id="address">
                </div>
                <div class="form-group">
                  <label for="gender">gender</label>
                  <select class="col-lg-6" name="gender" id="gender" >
                    <option value="0">ذكر</option>
                    <option value="1">انثي</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="height">height</label>
                  <input placeholder="ادخل الطول" class="col-lg-6" type="number" name="height" id="height" >
                </div>
                <div class="form-group">
                  <label for="weight">weight</label>
                  <input placeholder="الوزن بالkg" class="col-lg-6" type="number" name="weight" id="weight" >
                </div>
                <div class="form-group">
                  <label for="dateofbirth">تاريخ الميلاد</label>
                  <input class="col-lg-6" type="date" name="dateofbirth" id="dateofbirth" >
                </div>
              </div>
              <div class="modal-footer">
                <button type="submit" class="btn btn-primary btn-block">Save changes</button>
              </div>
            </form>
          </div>
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
    $(document).ready(function() {
        $('#addClientForm').on('submit', function(e) {
            e.preventDefault(); // Prevent default form submission

            $.ajax({
                url: 'js/ajax/addcl.php', // The URL to submit the form data to
                type: 'POST',
                data: $(this).serialize(),
                success: function(data) {
                    alert("تم اضافه العميل بنجاح");
                    $('#addClientModal').modal('hide');
                    console.log(data);
                    $('#clientslist').empty();
                    $.each(data, function(index, clientName) {
                      $('#clientslist').append('<option value="' + clientName + '">');})
                     },
                error: function(xhr, status, error) {
                    alert('An error occurred: ' + error);
                }
            });
        });
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
    var clientValue = $(this).val();
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


<script>
        if (event.key === 'Enter') {
            event.preventDefault();}
</script>
<?php include('includes/footer.php') ?>