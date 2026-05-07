<?php include('includes/header.php');?>
<style>
    
    #upRight{
        height: 550px;
        position: relative;
        display: flex;
        flex-direction: column;

    }
   
    #downRight{
        height: 400px;
    }
    .cat{
        width:100px;
    }
    .cashInput{
        width:60px;

    }
    #right2 input{
        border:3px !important;
        background-color:white;
        color:black;

    }
    #upRight2 .row{
        width:100%;             
    }
</style>

<nav class="navbar navbar-expand font-xs font-light p-0 bg-slate-200" >
  <ul class="navbar-nav">   
    </li>
    <li class="nav-item d-none d-sm-inline-block" >
      <a href="index.php" class="nav-link"><?=$lang_sidemain?></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="do/do_logout.php" class="nav-link"><?=$lang_navlogout?></a>
    </li>
     
  </ul>

</nav>




<?php include('elements/pos/tables.php');?>



<div class="row" id="pos">




<!-- 
    ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
                                                                  left 
    |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
-->

<div class="col-md-4 bg-slate-200" id="right">
<button class="btn btn-light float-left"><i class="fas fa-vector-square"></i></button>
  <!-- Right navbar links -->
   <script>
    document.addEventListener('DOMContentLoaded', function() {
  var fullscreenButton = document.querySelector('.btn.btn-light.float-left');
  
  fullscreenButton.addEventListener('click', function() {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen();
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      }
    }
  });
});
   </script>
  
    <form action="do/doadd_invoice.php" method="post" id="myForm">
        <div class="row bg-slate-50 " id="upRight0">
            <input type="text" name="pro_tybe" value="9" hidden>
            <input type="text" name="pro_serial" value="0" hidden>
            <input type="text" name="pro_id" value="1" hidden>
            <input type="date" name="pro_date" value="<?php echo date('Y-m-d'); ?>">
            <input type="date" name="accural_date" value="<?php echo date('Y-m-d'); ?>">
            <select name="store_id" class="" id="">
                <?php
                $resstore = $conn->query("SELECT * FROM `acc_head` WHERE is_stock =1 AND isdeleted = 0;");
                while ($rowstore = $resstore->fetch_assoc()) { ?>
                <option <?php if($rowstg['def_pos_store'] == $rowstore['id']){echo "selected";} ?> value="<?= $rowstore['id'] ?>"><?= $rowstore['aname'] ?></option>
                <?php } ?>
            </select>

            <select name="emp_id" class="" id="">
                <?php
                $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0;");
                while ($rowemp = $resemp->fetch_assoc()) { ?>
                <option <?php if($rowstg['def_pos_employee'] == $rowemp['id']){echo "selected";} ?> value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?></option>
                <?php } ?>
            </select>

            <select name="acc2_id" class="" id="">
                <?php
                $resclient = $conn->query("SELECT * FROM `acc_head` WHERE code like '122%'  AND is_basic = 0 AND isdeleted = 0;");
                while ($rowclient = $resclient->fetch_assoc()) { ?>
                <option <?php if($rowstg['def_pos_client'] == $rowclient['id']){echo "selected";} ?> value="<?= $rowclient['id'] ?>"><?= $rowclient['aname'] ?></option>
                <?php } ?>
            </select>

            <select name="fund_id" class="" id="">
                <?php
                $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                while ($rowfund = $resfund->fetch_assoc()) { ?>
                <option <?php if($rowstg['def_pos_fund'] == $rowfund['id']){echo "selected";} ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                <?php } ?>
            </select>
            <br>
            <div class="row">
                <div class="col"><div class="btn btn-secondary" id="tableSec">الطاولة</div></div>
                <div class="col"><input type="text" readonly class="bg-sky-200 focus:text-slate-950" placeholder="لا يوجد طاولة افتراضية" id="tableInput"></div>
            </div>
            
            <input type="text" class="form-control form-control-sm bg-slate-100 focus:bg-orange-200 focus:text-slate-950 frst" placeholder="برجاء قراءة الباركود" id="barcodeInput">

        
        </div>
        

        <div class="row bg-slate-50 d-flex flex-column" id="upRight">
            <div class="row bg-slate-50 " id="upRight1">
                <div class="table font-lg">    
                    <table class="table bg-light shadow">
                        <thead>
                            <tr>
                                <td>الاسم</td>
                                <td>عدد</td>
                                <td>سعر</td>
                                <td>قيمه</td>
                            </tr>
                        </thead>
                        <tbody id="itemData">
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row bg-orange-50 mt-auto text-lg" id="upRight2" style="bottom:0px;">
                <div class="row" style="width:100%;">
                    <div class="col-12">
                        <input type="text" class="form-control bg-light border-3" name="info" id="info" placeholder="اكتب ملاحظة">
                    </div>
                    <div class="col-md-2"><p for="" class="font-bold ">اجمالي</p></div>
                    <div class="col-md-4">
                        <input class="nozero form-control form-control-sm" type="text" readonly name="headtotal" id="total" value="0.00">
                        <input name="headplus" hidden>
                    </div>
                    <div class="col-md-2">
                        <p class="font-bold" for="">(F6)خصم</p>
                    </div>
                    <div class="col-md-4">
                        <input class="nozero form-control form-control-sm" type="text" name="headdisc" id="discount" value="0">
                    </div>
                </div>
                
                <div class="row" style="width:100%;">
                    <div class="col-md-4"><p class="font-bold" for="">صافي</p></div>
                    <div class="col-md-8 p-0 m-0">    
                        <input class="form-control form-control-sm text-lg font-normal" type="text" name="headnet" id="net_val" value="0">
                    </div>
                </div>

                <div class="row" style="width:100%;">
                    <div class="col-md-2">المدفوع</div>
                    <div class="col-md-4">
                        <input class="nozero form-control form-control-sm" type="text" id="paid" value="0.00">
                    </div>
                    <div class="col-md-2">الباقي</div>
                    <div class="col-md-4">
                        <input class="nozero form-control form-control-sm" type="text" id="change" value="0.00">
                    </div>
                </div>
            </div>
        </div>

        <div class="row" id="downRight2">
            <div class="col-md-12">
                <button name="submit" tybe="submit" value="save" class="btn btn-block dis text-lg bg-green-600 hover:bg-pink-600 text-white min-h-10 p-0 m-1" onclick=dis()>حفظ</button>
                <button name="submit" tybe="submit" value="cash" class="btn btn-block dis text-lg bg-green-600 hover:bg-pink-600 text-white min-h-10 p-0 m-1" onclick=dis()>حفظ و طباعه</button>
            </div>
        </div>
    </form>
</div>



<!-- 
    ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
                                                            left 
    |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
-->





        <div class="col-md-8 bg-slate-200" id="left">
    <div class="row bg-yellow-50" id="searchRow">
        <input type="text" class="scnd form-control form-control-sm bg-slate-50 focus:bg-orange-200" id="itemSearch" placeholder="خانة البحث">
    </div>

    <div class="row bg-slate-100" id="categories">
        <ul>
            <li class="float-left text-center">
                <button class="cat border-2 border-red shadow align-middle p-0 m-0 min-h-20 rounded bg-slate-100 transition duration-700 ease-in-out hover:bg-pink-600 hover:text-slate-50" onclick="filterItemsByCategory(null)">
                    الكل
                </button>
            </li>
            <?php 
                $rescat = $conn->query("SELECT * from item_group where isdeleted = 0");
                while($rowcat = $rescat->fetch_assoc()) {
            ?>
            <li class="float-left text-center">
                <button class="cat border-2 border-red shadow align-middle p-0 m-0 min-h-20 rounded bg-slate-100 transition duration-700 ease-in-out hover:bg-pink-600 hover:text-slate-50" onclick="filterItemsByCategory(<?= $rowcat['id']?>)">
                    <input hidden type="text" value="<?= $rowcat['id']?>">
                    <?= $rowcat['gname']?>
                </button>
            </li>
            <?php } ?>
        </ul>
    </div>
    <div class="row bg-slate-100" id="items">
    <?php
        $resitem = $conn->query("SELECT * FROM myitems where isdeleted = 0");
        while ($rowitem = $resitem->fetch_assoc()) {
    ?>
    <button title="<?= $rowitem['info']?>" class="itemButton cat border-2 border-red shadow align-middle p-0 m-0 min-h-20 rounded bg-slate-100 transition duration-300 ease-in-out hover:bg-pink-600 hover:text-slate-50" itemid="<?= $rowitem['barcode']?>" data-category="<?= $rowitem['group1']?>">
        <div class="itemlogo">
            <center>
                <img class="max-h-10 max-w-10" src="assets/logo/hors.png" alt="" onerror="this.onerror=null;this.src='assets/logo/hors.png';">
            </center>
        </div>
        <div class="itemname">
            <input type="text" id="itemCat<?= $rowitem['id']?>" value="<?= $rowitem['group1']?>" hidden>
            <input type="text" id="itemId<?= $rowitem['barcode']?>" value="<?= $rowitem['barcode']?>" hidden>
            <p class="font-normal text-lg text-navy"><?= $rowitem['iname']?></p>
            <p class="text-lg"><?= $rowitem['price1']?> ج</p>
        </div>
    </button>
    <?php } ?>
</div>
</div>
</div>











<div class="modal fade" id="addclmodal" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">اضافه عميل جديد في قاعدة البيانات</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addClientForm" >
                    <div class="form-group">
                        <label for="clname">اسم العميل</label>
                        <input type="text" class="form-control" id="clname" name="clname" placeholder="name" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">تليفون</label>
                        <input type="text" class="form-control" id="phone" name="phone" placeholder="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="phone2">تليفون2</label>
                        <input type="text" class="form-control" id="phone2" name="phone2" placeholder="phone2">
                    </div>
                    <div class="form-group">
                        <label for="address">عنوان</label>
                        <input type="text" class="form-control" id="address" name="address" placeholder="address">
                    </div>
                    <div class="form-group">
                        <label for="address2">عنوان 2</label>
                        <input type="text" class="form-control" id="address2" name="address2" placeholder="address2">
                    </div>
                    <div class="form-group">
                        <label for="address3">عنوان 3</label>
                        <input type="text" class="form-control" id="address3" name="address3" placeholder="address3">
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="submit" class="btn btn-success btn-block" onclick=" dis();">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>







<script>
// Toggle visibility of #tables when #tableSec is clicked
$('#tableSec').click(function() {
  $('#tables').show();
});

// Hide #tables when the close button is clicked
$('#closeTables').click(function() {
  $('#tables').hide();
});

</script>

<script>
    // When any button with the class 'tab' is clicked
$('.tab').click(function() {
  // Get the value inside the <p> tag inside the clicked button
  var tableValue = $(this).find('p').text();
  
  // Set the value of the input with id 'tableInput'
  $('#tableInput').val(tableValue);
  $('#tables').hide();
});

</script>
<script src="js/pos.js"></script>


</div>






<?php include('includes/footer.php');?>