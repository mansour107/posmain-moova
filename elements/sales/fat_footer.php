<div class="row full bg--200 border">
  
<input type="text" name="pro_tybe" hidden value="<?php if (isset($_GET)) {
                    if (isset($_GET['q'])) {
                        if ($_GET['q'] == "sale") {$pro_tybe = '4';echo $pro_tybe;
                        }elseif ($_GET['q']== "buy") {$pro_tybe = '3';echo $pro_tybe;
                        }elseif ($_GET['q']== "resale"){$pro_tybe = '10';echo $pro_tybe;
                        }elseif ($_GET['q']== "rebuy"){$pro_tybe = '11';echo $pro_tybe;
                        }elseif ($_GET['q']== "po"){$pro_tybe = '12';echo $pro_tybe;
                        }elseif ($_GET['q']== "so"){$pro_tybe = '13';echo $pro_tybe;
                        }
                      }
                }elseif($_GET['e']){$pro_tybe =$rowop['pro_tybe'];}else{
                    $pro_tybe =0; 
                }  ?>
                ">
                <?php 
                if ($pro_tybe == 0) {
                    echo "<h1> يبدو انك دخلت الفاتورة من مكان غير المخصص</h2>";
                    die;
                }
                ?>
<div class="col-md-3">

<div class="row">
                    <div class="col bg-light"> الكمية </div>
                    <div class="col border border-light">
                    <h6 id="storeqty"></h6> 
                </div>
            </div>

            
            <div class="row">
                    <div class="col bg-light"> سعر البيع</div>


                    <div class="col border border-light" id="cost_price_div">
                    <h6 id="price1" class=""></h6>
                </div>

            </div>
            <div class="row">
                    <div class="col bg-light"> سعر السوق</div>
                    <div class="col border border-light" id="cost_price_div">
                    <h6 id="market_price" class=""></h6>
                </div>
            </div>

            
            <div class="row">
                    <div class="col bg-light"> آخر وقت للتعديل</div>
                    <div class="col border border-light" id="cost_price_div">
                        <h6 id="storemdtime" class=""></h6>
                </div>
            </div>
                </div>
                <div class="col-md-3">

              <div class="row">
                    <div class="col bg-light"> سعر الشراءالمتوسط</div>
                    <div class="col border border-light" id="cost_price_div">
                    <h6 id="cost_price" class="text-white hover:bg-slate-400"></h6>
                </div>
            </div>


            
            <div class="row">
                    <div class="col bg-light"> سعر الشراءالاخير</div>
                    <div class="col border border-light" id="">
                   <h6 id="last_price" class="text-white hover:bg-slate-400" ></h6>
                </div>
            </div>

</div>
<div class="col-md-3">
<div class="row">
                            <div class="col col-md-4">
                                <label for="">الاجمالي</label>
                            </div>
                                <div class="col-md-8 ">
                                    
                                <input
                                id="headtotal" name="headtotal"  type="text" class="form-control form-control-sm" value="<?php if(isset($_GET['e'])){echo $rowedit['fat_total'];}?>" readonly >
                            </div>
                            </div>
                            <div class="row">
                                <div class="col col-md-4">
                            <label for="">الخصم<span class="text-orange-600">(F6)</span></label>
                            </div>
                                <div class="col "><input id="headdisc" name="headdisc" type="text" class="form-control form-control-sm mid select-all hover:select-all nozero" value="<?php if(isset($_GET['e'])){echo $rowedit['fat_disc'];}else {echo 0;}?>" ></div>
                            </div>
                            <div class="row">
                            <div class="col col-md-4">
                            <label for="">الاضافي</label>
                            </div>
                                <div class="col "><input id="headplus" name="headplus" type="text" class="form-control form-control-sm " value="<?php if(isset($_GET['e'])){echo $rowedit['fat_plus'];}else {echo 0;}?>" ></div>
                            </div>
                            <div class="row">
                            <div class="col col-md-4">
                                
                            <label for=""  >الصافي</label>
                            </div>
                                <div class="col-md-8"><input 
                                id="headnet" name="headnet" type="text" class="form-control"  readonly style="font-size:30px;" value="<?php if(isset($_GET['e'])){echo $rowedit['pro_value'];}else {echo 0;}?>"></div>
                            </div>

</div>
<div class="col-md-3">
<?php 
// إخفاء جزء الدفع لأوامر الشراء والبيع وعروض الأسعار
// Debug: عرض قيمة pro_tybe
echo "<!-- pro_tybe = " . (isset($pro_tybe) ? $pro_tybe : 'NOT SET') . " -->";
$hide_payment = (isset($pro_tybe) && (intval($pro_tybe) == 12 || intval($pro_tybe) == 13 || intval($pro_tybe) == 14));
echo "<!-- hide_payment = " . ($hide_payment ? 'true' : 'false') . " -->";
if (!$hide_payment): 
?>
                            <div class="row">
                              <?php
                              if(isset($_GET['e'])){
                              $rowpaid = $conn->query("SELECT * FROM ot_head where op2 = $opid AND pro_tybe = 1 or pro_tybe = 2 ")->fetch_assoc();
                              if (isset($rowpaid)) {
                                $paid = $rowpaid['pro_value'];
                              }else {
                                $paid = 0.00;
                              }
                              }?>





                              <div class="col-md-4">
                              <label for=""  >المدفوع <span class="text-orange-600">(F7)</span></label>
                              </div>
                                <div class="col-md-8">
                                    <input id="paid" name="paid" type="number" class="form-control form-control-lg bg-light last" style="font-size:30px;" value="<?php if(isset($_GET['e'])){echo $paid ;}else{echo 0;} ?>" >
                                </div>
                                </div>

                                <div class="row">
                              <div class="col-md-4">
                              <label for=""  >الباقي</label>
                              </div>
                                <div class="col-md-8">
                                    <input id="change" type="text" class="form-control form-control-sm" id=""  readonly value="0.00">
                                </div>
                            </div>
                            
                            
                            <div class="row">
                        <div class="col col-4">
                          <label for="">الصندوق</label>
                        </div>    
                         <div class="col col-md-8">
                                <select name="fund_id" class="form-control form-control-sm" id="">
                                    <?php
                            $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1;");
                            while ($rowfund = $resfund->fetch_assoc()) { ?>
                            <option
                            <?php  echo ($conn->query("SELECT cur_value FROM myoptions WHERE oname = 'def_fund'")->fetch_assoc()['cur_value'] == $rowfund['id']) ? " selected " : ""; ?>
                            value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                            <?php } ?>
                        
                        
                                </select>
                            </div>
                            </div>
<?php endif; // نهاية إخفاء جزء الدفع ?>
                            
                          </div>
</div>




<div class="row">
                            <div class=" col-md-4">
                              <button id="submit" class="btn <?php if(isset($_GET['q'])){echo  'bg-teal-500';}else{echo 'bg-red-500';}?> btn-block btn-lg dis" type="submit" name="submit" value="save" onclick="this.form.submit_action.value='save';">حفظ (F12) </button>
                              <button id="submit2" class="btn <?php if(isset($_GET['q'])){echo  'bg-teal-500';}else{echo 'bg-red-500';}?> btn-block btn-lg dis" type="submit" name="submit" value="print" onclick="this.form.submit_action.value='print';">حفظ و طباعه (F11) </button>
                              <input type="hidden" name="submit_action" value="save">
                          </div>                            
                        <div class="col-md-6">
                        <input type="text" class="form-control bg-orange-300" name="info" id="info" placeholder="ملاحظات">
                        </div>
                        <div class="col-md-2" id="showOps">
                            <div class="btn" >اظهار الفواتير السابقة</div>
                        </div>
        </div>