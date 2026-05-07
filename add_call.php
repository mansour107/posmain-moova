<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
    <section class="content-header">
            <div class="container-fluid">
                <form action="do/doadd_call.php" method="post">
                <div class="card">
                    <div class="card-header">
                        <h3 class="hadi-wonder">
                            مكالمة جديدة
                        </h3>
                    </div>
                    <div class="card-body">

                        <div class="row">
                        
                            <div class="form-group col-lg-3">
                            <label for="">الموضوع</label>
                            <input type="text" class="form-control" name="" placeholder="اكتب اي اسم مرجعي">
                            </div>
                                
                            <div class="form-group col-lg-3">
                            <label for="">العميل</label>
                            <select name="" id="" class="form-control"> 
                                <option value="" >اختار العميل</option>
                                <?php
                                 $sqlcli="SELECT * from acc_head where is_basic = 0 AND code LIKE '122%' AND isdeleted = 0 order by aname " ;
                                 $rescli = $conn->query($sqlcli);
                                 $x = 0;
                                 while ($rowcli = $rescli->fetch_assoc()) {
                                   $x++
                                 ?>
                                 <option value="<?= $rowcli['id'] ?>"><?= $rowcli['aname'] ?></option>


                            <?php } ?>
                            </select>
                        </div>
                        </div>


                        <div class="row">
                        <div class="form-group col-lg-2">
                            <label for="">نوع المكالمة</label>
                          <select name="" id="" class="form-control">
                            <option value="1">اختر</option>
                          </select>
                        </div>

                        <div class="form-group col-lg-2">
                            <label for="">تاريخ المكالمة</label>
                            <input type="date" class="form-control" name="" placeholder="اكتب اي اسم مرجعي">
                        </div>

                        <div class="form-group col-lg-2">
                            <label for="">وقت المكالمة</label>
                            <input type="time" class="form-control" name="" placeholder="اكتب اي اسم مرجعي">
                        </div>

                        <div class="form-group col-lg-1">
                            <label for="">مدة المكالمة</label>
                            <input type="text" class="form-control bg-light" name="" placeholder="00:00">
                        </div>

                        </div>


                        <div class="row">

                        <div class="form-group col-lg-4">
                            <label for="">تعليق الموظف</label>
                            <input type="text" class="form-control" name="" placeholder="تعليق الموظف">
                        </div>

                        <div class="form-group col-lg-4">
                            <label for="">محتوي المكالمة</label>
                            <textarea name="" id="" cols="50" rows="5" class="form-control"></textarea>
                        </div>
                        </div>

                        <div class="row">
                        <div class="form-group col-lg-2">
                            <label for="">تاريخ المكالمة القادمة</label>
                            <input type="date" class="form-control" name="" placeholder="اكتب اي اسم مرجعي">
                        </div>

                        <div class="form-group col-lg-2">
                            <label for="">وقت المكالمة القادمة</label>
                            <input type="time" class="form-control" name="" placeholder="اكتب اي اسم مرجعي">
                        </div>
                        </div>



                        <div class="row">

                        <div class="form-group col-lg-4">
                            <label for="">تعليق المراجع للمكالمات</label>
                            <textarea name="" id="" cols="50" rows="5" class="form-control"></textarea>
                        </div>

                        <div class="form-group col-lg-1">
                            <label for="">تقييم المراجع</label>
                            <select name="" id="" class="form-control">
                                <option value="9">9</option>
                                <option value="8">8</option>
                                <option value="7">7</option>
                                <option value="6">6</option>
                                <option value="5">5</option>
                                <option value="4">4</option>
                                <option value="3">3</option>
                                <option value="2">2</option>
                                <option value="1">1</option>
                                <option value="0">0</option>
                            </select>
                        </div>
                        </div>


                        
                        

                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary btn-block">حفظ</button>

                    </div>
                </div>
                </form>
             </div>
    </section>
</div>
<?php include('includes/footer.php') ?>
