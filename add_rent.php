<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<style>
.msg-success {
      
        position:fixed;
        bottom:50px;
        left:50px;
        z-index: 90;
        opacity: 1;
        transition: opacity 2s ease-in-out;
    }
    .hide {
        opacity: 0;
    }
 
    </style>
<?php  
if (isset($_GET['c'])) {
             if ($_GET['c'] == "edit" ) {
                if (isset($_GET['id'])) {
                    $id= $_GET['id'];
                    $action = "do/doedit_rent.php?id=$id";   
                }    
}   }else {
    $action = "do/doadd_rent.php";
} ?>
<div class="content-wrapper">
  <section class="content-header">
    
    <div class="container-fluid">


        <?php 
        if (isset($_GET['res'])) {
        if ($_GET['res'] == 's'){?>
            <h4 id="successMessage" class='bg-success hadi-alert hazaz'>تم تسجيل عقد الايجار بنجاح</h4>
            <?php }} ?>
    
    
            <form autocomplete="off"  action="<?= $action ?>" method="post" enctype="multipart/form-data" class='validate_form' autocomplete="off" id="myForm">
    <div class="card <?php if(isset($_GET['id'])){echo 'card-warning';}else{echo 'card-primary';} ?>">
<div class="card-header">            
                <div class="row">
                <div class="col hazaz"><h2>عقد ايجار</h2></div>
                </div>
            </div>
            <div class="card-body bg-light">
                



                    
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">اسم المستأجر</label><span class="text-danger text-lg">*</span>
                    </div>
                        <div class="col-lg-3">
                         
                        <select required name="cl_id" id="" class="form-control frst">
                        <?php
                        $rescl = $conn->query("SELECT * FROM acc_head where code like '122%' and is_basic = 0");
                        while ($rowcl = $rescl->fetch_assoc()) {
                        ?>
                        <option value="<?= $rowcl['id']?>"><?= $rowcl['aname']?></option>
                        <?php }?>
                        </select>
                    </div>
                    </div>

                    
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">العين المستأجرة</label><span class="text-danger text-lg">*</span></div>
                        <div class="col-lg-3">
                            <select required name="rent_id" id="" class="form-control frst">
                            <?php 
                            $resrnt = $conn->query("SELECT * FROM acc_head where is_basic = 0 And rentable = 1 AND code like '112%' ");
                            while ($rowrnt = $resrnt->fetch_assoc()) {
                            ?>
                            <option value="<?= $rowrnt['id']?>"><?= $rowrnt['aname']?></option>    
                        <?php } ?>    
                        </select>
                            </div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">تليفون المستأجر</label></div>
                        <div class="col-lg-3">
                            
                        <input type="text" name="phone" id="" class=" form-control" data-parsley-trigger="keyup"  required  minlength="6"></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">رقم الهوية</label></div>
                        <div class="col-lg-3"><input type="text" name="idintity" id="" class=" form-control" required></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">مده الايجار</label></div>
                        <div class="col-lg-2">
                            <input type="date" name="start_date" id="" class=" form-control" required>
                        </div>
                        <div class="col-lg-2">
                            <input type="date" name="end_date" id="" class=" form-control" required>
                        </div>


                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">استحقاق الايجار</label></div>
                        <div class="col-sm-4">
                            <div class="radio-group bg-light text-lg">            
                                <label><input type="radio" name="pay_tybe" value="0" data-parsley-mincheck="2">يومي</label>
                                <label><input type="radio" name="pay_tybe" value="1" checked >شهري</label>
                                <label><input type="radio" name="pay_tybe" value="2" >ربع سنوي</label>
                            </div>
                        </div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">قيمة الايجار</label></div>
                        <div class="col-sm-2"><input value="" type="number"  name="r_value" id="r_value" class=" form-control " required></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">بند 1 <span><input type="checkbox" id="chk1"> </span></label></div>
                        <div id="bnd1" class="col-md-12" style="display:none;"><input type="text" name="bnd1" class="form-control"></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">بند 2 <span><input type="checkbox" id="chk2"></span></label></div>
                        <div id="bnd2" class="col-md-12" style="display:none;"><input type="text" name="bnd2" class="form-control"></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">بند 3 <span><input type="checkbox" id="chk3"></span></label></div>
                        <div id="bnd3" class="col-md-12" style="display:none;"><input type="text" name="bnd3" class="form-control"></div>
                    </div>
                            
                    <div class="row">
                        <div class="col-lg-2 " style="font-size:20px;"><label  for="">بند 4 <span><input type="checkbox" id="chk4"></span></label></div>
                        <div id="bnd4" class="col-md-12" style="display:none;"><input type="text" name="bnd4" class="form-control"></div>
                    </div>
                    
                    </div>
                    <div class="card-footer">
                    <div class="row">
                        <div class="col">
                            <button type="submit" class="btn btn-primary btn-lg btn-block" id="subBtn">حفظ (f12)</button>
                        </div>
                        <div class="col"></div>
                    </div>
                    
                    </div>        
            
            </div>
                        
        </div>
                        </form>




    </div>
  </section>
</div>


<input type="text" data-parsley-trigger=" keyup" required="" minlength="6" data-parsley-length="[6, 50]" autocomplete="off" class="form-control form-control-sm parsley-error" id="name" name="name" placeholder="أدخل الاسم" data-parsley-id="5" aria-describedby="parsley-id-5">


<script>
    // Wait for the DOM content to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Get the element
        var successMessage = document.getElementById('successMessage');
        
        // Wait for 2 seconds, then hide the element
        setTimeout(function() {
            successMessage.classList.add('hide');
        }, 2000);
    });
</script>
<script>
    $(document).ready(function() {
        // Handle checkbox toggle for bnd inputs
        $('#chk1').change(function() {
            $('#bnd1').toggle(this.checked);
        });
        
        $('#chk2').change(function() {
            $('#bnd2').toggle(this.checked);
        });
        
        $('#chk3').change(function() {
            $('#bnd3').toggle(this.checked);
        });
        
        $('#chk4').change(function() {
            $('#bnd4').toggle(this.checked);
        });
        
        $('#subBtn').on('click', function() {
            $r_value = $("#r_value").val();
            if ($r_value === "0" || $r_value === "0.00") {
                
                $("#errorsAlert").html("يجب ان تكون قيمة الايجار أكبر من صفر");
                $("#r_value").focus();
            }
        });
    });
</script>
<?php include('includes/footer.php') ?>