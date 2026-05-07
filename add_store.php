<?php 
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">

 
      <div class="card card-primary">

      <div class="card-header">
        <div class="row">
        <div class="col-lg-4"><h3>مخزن جديد</h3></div>
        <div class="col-lg-4"></div>
        <div class="col-lg-4 text-right">
        </div>
        </div>
      </div>


      <div class="card-body">
<form action="do/doadd_store.php" method="post" id="myForm">

      <div class="row">
            <div class="col-lg-2 bg-light">
                اسم المخزن
            </div>
            <div class="col-lg-4">
                <input  type="text" name="store" id="store" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                حساب اول المدة
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accbegin" id="accbegin" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                حساب المبيعات
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accsale" id="accsale" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                مردود المبيعات
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accresale" id="accresale" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                المشتريات
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accbuy" id="accbuy" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                مردود المشتريات
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accrebuy" id="accrebuy" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>


        <div class="row">
            <div class="col-lg-2  bg-light">
                المخزون الحالي ميزانية
            </div>
            <div class="col-lg-4">
                <input readonly type="text" name="accend" id="accend" class="form-control form-control-sm"> 
                <br>
            </div>
        </div>
        <div class="row">
            <div class="col-lg-2">
                <button class="btn btn-primary" type="submit"> حفظ f12</button>
            </div>
        </div>

</form>



      </div>





</div>
</section>
</div>



<script>
    $(document).ready(function() {
        $("#store").on("keyup", function() {
            var storeValue = $(this).val(); // Get the value of #store input readonly field
            // Set the value of #accbegin input readonly field
            $("#accbegin").val(storeValue + " - أول المدة");
            $("#accsale").val(storeValue + " - مبيعات");
            $("#accresale").val(storeValue + " - مردود مبيعات");
            $("#accbuy").val(storeValue + " - مشتريات");
            $("#accrebuy").val(storeValue + " - مردود مشتريات");
            $("#accend").val(storeValue + " - مخزون حالي ميزانية");
            

        });
    });
</script>
<?php include('includes/footer.php'); ?>