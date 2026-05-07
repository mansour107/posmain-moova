<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
<?php
$rowjournalid = $conn->query("SELECT max(journal_id) FROM journal_heads ")->fetch_assoc();
if ($rowjournalid['max(journal_id)'] == null ) {
  $jrnlid = 1;
}else {
  $jrnlid = ($rowjournalid['max(journal_id)']+1);
}
?>

<form action="do/doadd_journal.php" method="post">
    <div class="card">
            <div class="card-header">
         <div class="row">
          <div class="col col-3"><h1>قيد يومية _
          <?php
          $a="عام";
          if(isset($_GET[''])){
            if ($_GET['a']) {
              if($_GET['a'] == 1){echo "شراء اصل";}elseif 
              ($_GET['a'] == 2) {echo "بيع اصل";}
              else{echo "عام";}}}    ?>
          </h1></div>
          <div class="col">
            <div class="row">
            <div class="col">
                <div class="form-group">
                  <label for=""></label>
                </div>
              </div>

              <div class="col">
                <div class="form-group">
                  <label for="">رقم دفتري</label>
                  <input name="journal_id"   type="text" class="form-control" value="<?= $jrnlid ?>">
                </div>
              </div>

              <div class="col">
                <div class="form-group">
                  <label for="">تاريخ</label>
                  <input name="jdate"  type="date" class="form-control" >
                </div>
              </div>
              
            </div>
          </div>
         </div>
            
         
         
         
         
          </div>

            <form action="do/doadd_journal.php" method="post"></form>
            <div class="card-body">


            
  <datalist id="acclist">
    
  </datalist>


            <div class="table-responsive">
                        <table id="" class="table table-bordered table-hover">
             <thead>
                <tr>
                    <th class="col-1">من</th>
                    <th class="col-1">الي</th>
                    <th class="col-4">اسم الحساب</th>
                    <th class="col-4">ملاحظات</th>
                  </tr>
             </thead>
             <tbody>
             <tr>
                    <td ><input  required class="frst form form-control" type="text" name="rowdepit[]" id="depit"></td>
                  
                  
                    <td ></td>
                    
                    <td>
                      <select class="form-control" name="rowdepit[]" id="depitacc">
                      <option value="0">اختر حساب</option>
                      <?php
                      
                      if(isset($_GET['a'])){
                        if ($_GET['a'] == 1) {
                          $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND code like '11%'"); 
                         }elseif ($_GET['a'] == 2) {
                          $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND code NOT like '11%'"); 
                        }
                         }else{$resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 ");}
                        
                        while ($rowacc  = $resacc->fetch_assoc()) {
                        ?>
                        <option value="<?= $rowacc['id'] ?>"><?= $rowacc['code'] ?>_<?= $rowacc['aname'] ?></option>
                          <?php } ?>
                      </select>
                      </td>
                      <td>
                      <input type="text" name="rowdepit[]" class="form-control">
                      </td>
       
                </tr>
                <tr>
                    <td class=""></td>
                    <td class=""><input  required class="form form-control" type="text" name="creditrow[]" id="credit"></td>
                    
                    <td class="">
                    <select class="form-control" name="creditrow[]" id="creditacc" style="height:70px !important">
                      <option value="0">اختر حساب</option>
                      <?php
                    if(isset($_GET['a'])){
                      if ($_GET['a'] == 1) {
                        $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND code NOT like '11%'"); 
                      }elseif ($_GET['a'] == 2) {
                        $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND code like '11%'"); 
                      }
                      
                    }else{$resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 ");}               
                      while ($rowacc  = $resacc->fetch_assoc()) {
                        ?>
                        <option value="<?= $rowacc['id'] ?>"><?= $rowacc['code'] ?>_<?= $rowacc['aname'] ?></option>
                          <?php } ?>
                      </select>
                </td>
                <td>
                  <input type="text" name="creditrow[]" class="form-control">
                </td>
                    
                </tr>


             </tbody>
                </table>
            </div>
            <div class="row">
                
            
              <label for="details">بيان</label>
              <input  required type="text" name="details" class="form-control">
            
              
              </div>

            </div>
            <div class="card-footer">
              <div class="row">
                <div class="col">
                <div class="form-group">
                  <label for="">اجمالي مدين</label>
                  <input  name="total" required id="depit2" type="text" class="" disabled>
                </div>
                </div>
                <div class="col">
                  
                <div class="form-group">
                  <label for="">اجمالي دائن</label>
                  <input  required id="credit2" type="text" class="" disabled>
                </div>
                </div>
                <div class="col"> 

                <div class="form-group">
                  <label for="">الفرق</label>
                  <input  required id="balance" type="text" class="" disabled>
                </div>
                </div>
              </div>
              
              <div class="row">
                <div class="col"><button type="submit" class="btn btn-primary btn-block" id="confirm">حفظ</button></div>
                <div class="col"><button type="reset" class="btn btn-block">مسح</button></div>
              </div>
            </div>
        </div>
        </form>
    </div>
  </section>
</div>

<script>
  $("input").keyup(function(){
  $("#depit2").val($("#depit").val());
  $("#credit2").val($("#credit").val());
  $balance = $("#credit").val() - $("#depit").val();
  $("#balance").val($balance);

});



$(document).ready(function() {
  $('#depitacc').select2();
  $('#creditacc').select2();});

$(document).ready(function(){
  $("#confirm").hide()});  
  $("input").focusout(function(){
  if ($("#balance").val() == 0  & $("#depitacc").val() != 0 & $("#creditacc").val() != 0 ) {
    $("#confirm").show()}else{$("#confirm").hide()}});

    $("select").focusout(function(){
  if ($("#balance").val() == 0 & $("#depitacc").val() != 0 & $("#creditacc").val() != 0 ) {
    $("#confirm").show()}else{$("#confirm").hide()}});

</script>
<?php include('includes/footer.php') ?>
