<?php include('includes/header.php') ?>
<style>
    .right{
        background-color:#ECF4D6;
    }
    .form-control{
        background-color: whitesmoke;
    }
    .left{
        background-color: whitesmoke;
    }
</style>




    <section class="content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col col-7 right">
                    <div class="row">
                        <div class="col">

                        <div class="form-inline">
                            <div class="form-group">
                                <label for="fat_code">الفاتورة رقم </label>
                                <input name="fat_code" type="text" data-parsley-trigger="keyup" required id="fat_code" class="form-control form-control-sm" rows="5">
                            </div>

                            
                            <div class="form-group">
                                <label for="serial"> رقم مرجعي</label>
                                <input name="serial" type="text" data-parsley-trigger="keyup" required id="serial" class="form-control form-control-sm" rows="5">
                            </div>
                            </div>
                            </div>


                            <div class="col">
                            <div class="form-inline">
                            <div class="form-group">
                                <label for="fat_code">اسم العميل </label>
                                <input name="fat_code" type="text" data-parsley-trigger="keyup" required id="fat_code" class="form-control form-control-sm" rows="5">
                            </div>

                            
                            <div class="form-group">
                                <label for="serial"> اسم الموظف</label>
                                <input name="serial" type="text" data-parsley-trigger="keyup" required id="serial" class="form-control form-control-sm" rows="5">
                            </div>
                            </div>


                        </div>



                    </div>
                    
                    <div class="row">
                            <div class="col">
                                <div class="row">
                                    <div class="col col-7">اسم الصنف</div>
                                    <div class="col">الكميه</div>
                                    <div class="col">السعر</div>
                                    <div class="col">القيمه</div>
                                </div>
                                <div class="row">
                                    <div class="col col-7">
                                    <input name="iname" type="text" data-parsley-trigger="keyup" required id="" class="form-control form-control-sm">
                                    </div>
                                    <div class="col">
                                    <input name="qty" type="text" data-parsley-trigger="keyup" required id="" class="form-control form-control-sm">
                                    </div>
                                    <div class="col">
                                    <input name="" type="text" data-parsley-trigger="keyup" required id="" class="form-control form-control-sm">
                                    </div>
                                    <div class="col">
                                    <input name="" type="text" data-parsley-trigger="keyup" required id="" class="form-control form-control-sm">
                                    </div>
                                </div>

                                </div>
                            </div>
                        </div>
                <div class="col left">
                    bbbbbbbb
                </div>
            </div>





        </div>
    </section>
</div>



<?php include('includes/footer.php') ?>
