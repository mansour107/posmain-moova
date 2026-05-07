
<div class="row frst-row bg--200">
      <div class="col-lg-2">
        <div class="tool">
      <label for=""><?php if ($pro_tybe == '4' OR $pro_tybe == '10' OR $pro_tybe == '12') {
                                echo 'المورد<a class="btn bg-lime-200 btn-sm" href="add_account.php?parent_id=211" target="_blank">+</a>';
                            }elseif ($pro_tybe == '3' OR $pro_tybe == '11' OR $pro_tybe == '13') {
                                echo 'العميل<a class="btn bg-lime-200 btn-sm" href="add_account.php?parent_id=122" target="_blank">+</a>';
                           }?>
                            </label>
                            <div class="tooltext">
                              اضافه جديد
                            </div>
                            </div>
                            <select class="select2 form-control form-control-sm" name="acc2_id" id="mySelectEmp">
                            <?php
                            if ($pro_tybe == '4' OR $pro_tybe == '11' OR $pro_tybe == '12') {
                                $resclients = $conn->query("SELECT * FROM `acc_head` WHERE code like '211%'  AND is_basic = 0 AND isdeleted = 0 order by id ;");
                            }elseif ($pro_tybe == '3' OR $pro_tybe == '10' OR $pro_tybe == '13') {
                                $resclients = $conn->query("SELECT * FROM `acc_head` WHERE code like '122%'  AND is_basic = 0 AND isdeleted = 0 order by id ;");
                            }


                            while ($rowclients = $resclients->fetch_assoc()) { ?>
                            <option 
                            
                             <?php  
                            //  echo ($conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_cl'")->fetch_assoc()['cur_value'] == $rowclients['id']) ? " selected " : ""; 
                             ?>
                  

                             <?php if (isset($_GET['e']) && $rowedit['acc2'] == $rowclients['id'] ){echo "selected";}?>

                            
                             value="<?= $rowclients['id'] ?>"><?= $rowclients['aname'] ?></option>
                            <?php } ?>
                        </select>
      </div>




      <div class="col-md-2">
      <label for="">المخزن</label>
                         
                         <select name="store_id" class="form-control form-control-sm" id="">
                             <?php
                     $resstore = $conn->query("SELECT * FROM `acc_head` WHERE is_stock =1;");
                     while ($rowstore = $resstore->fetch_assoc()) { ?>
                     <option
                     <?php  echo ($conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_store'")->fetch_assoc()['cur_value'] == $rowstore['id']) ? " selected " : ""; ?>
                     <?php if (isset($_GET['e']) && $rowedit['store_id'] == $rowstore['id'] ){echo " selected ";}?>

                     value="<?= $rowstore['id'] ?>"><?= $rowstore['aname'] ?></option>
                     <?php } ?>
                         </select>
      </div>
      <div class="col-md-2">
        
      <label for="">الموظف</label>
                        <select class="form-control form-control-sm" name="emp_id" id="">
                        <?php
                            $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0;");
                            while ($rowemp = $resemp->fetch_assoc()) { ?>
                            <option
                            <?php  echo ($conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_emp'")->fetch_assoc()['cur_value'] == $rowemp['id']) ? " selected " : ""; ?>
                            <?php if (isset($_GET['e']) && $rowedit['emp_id'] == $rowemp['id'] ){echo "selected";}?>

                            value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?></option>
                            <?php } ?>
                        </select>
                            </div>

                              <div class="col-md-2">
                                <label for="">التاريخ</label>
                                <input type="date" class="form-control bg-secondary" name="pro_date" id="pro_date" value="<?php if(isset($_GET['e'])){echo $rowedit['pro_date'];}else {echo date('Y-m-d');}?>">
                              </div>
                              <div class="col-md-2">
                              <label for="">تاريخ الاستحقاق</label><input type="date" class="form-control" name="accural_date" id="" value="<?php if(isset($_GET['e'])){echo $rowedit['accural_date'];}?>">
                              </div>


                              <div class="col-md-1">
                              <label for="">رقم الفاتورة</label>
                          <input name="pro_id"  type="text" class="form-control form-control-sm" 
                          value="<?php if(isset($_GET['e'])){echo $rowedit['pro_id'];}else{
                            $resnum = $conn->query("SELECT * FROM ot_head where pro_tybe = $pro_tybe");
                            $rownum = $resnum->fetch_assoc();

                            
                            }?>"  readonly>
                              </div>
                              <div class="col-md-1">
                              <label for="">S.N</label>
                                <input type="text"  name="pro_serial" class="form-control form-control-sm" placeholder="" value="<?php if(isset($_GET['e'])){echo $rowedit['pro_serial'];}?>">
                              </div>
                            </div>
