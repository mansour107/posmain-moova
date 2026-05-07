<div class="modal fade" id="modal-xl" style="display: none;" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h4 class="modal-title">اضافة صنف جديد</h4>
              



                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                                </button>
                                </div>
          
            <div class="modal-body">
              <p id="msgitem" class="text-lime-400"></p>
              <?php if ($role['add_items'] == 1){?>
                <div class="card card-primary">
                <form action="" method="post" id="addItemForm">
                  <?php 
              $rowlstitm = $conn->query("SELECT max(code) FROM myitems ")->fetch_assoc();
              if ($rowlstitm['max(code)'] == null) {
                $itmid = 1;
              } else {
                $itmid = ($rowlstitm['max(code)']+1);
              }
              ?>

              
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="code">الكود</label>
                      <input readonly value="<?= $itmid ?>" class="form-control form-control-sm col-4" type="text" name="code" id="code" >
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="barcode">الباركود</label>
                      <input required value="<?= $itmid ?>" class="form-control form-control-sm" type="text" name="barcode" id="barcode" >
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="iname">اسم الصنف</label>
                      <datalist id="inamelist">
                        <?php 
                        $resname = $conn->query("SELECT iname , name2 from myitems order by iname"); 
                        while ($rowname = $resname->fetch_assoc()){?>    
                          <option value="<?= $rowname['iname']?>"><?= $rowname['iname'] ?></option>
                        <?php } ?>
                      </datalist>
                      <input list="inamelist" required class=" form-control form-control-sm" type="text" name="iname" id="iname" value="">
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="name2">اسم ثاني</label>
                      <input class="form-control form-control-sm" type="text" name="name2" id="name2" value="">
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="info">تفاصيل</label>
                      <input class="form-control form-control-sm" type="text" name="info" id="info" value="">
                    </div>
                  </div>
                </div>

                <div class="col-md-12 bg-light">
                  <b>الوحدات</b>
                  <p id="addUnit" class="btn btn-success">اضافه وحده</p>
                  <div class="row urow">
                    <div class="col-md-4">
                      <label for="">الوحدة</label>
                      <select name="unit_id[]" id="" class="form-control">
                        <?php
                        $resunit = $conn->query('SELECT * from myunits');
                        while ($rowunit = $resunit->fetch_assoc()) {?>
                          <option value="<?= $rowunit['id']?>"><?= $rowunit['uname']?></option>
                        <?php } ?>
                      </select>
                    </div>

                    <div class="col-md-2">
                      <label for="">م التحويل</label>
                      <input class="form-control" type="number" readonly name="u_val[]" id="" value="1">
                    </div>

                    <div class="col-md-5">
                      <label for="">باركود</label>
                      <input class="form-control" type="text" name="unit_barcode[]" id="unitCode" value="<?= $itmid ?>">
                    </div>

                    <div class="col-md-1">
                      <p class="btn btn-danger deleteRow">X</p>
                    </div>
                  </div>
                </div>
              </div>

              
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label for="group1">مجموعه</label>
                      <select name="group1" id="" class="form-control form-control-sm float-right">
                      <?php
                      $resgroup1 = $conn->query("SELECT * FROM item_group where isdeleted = 0");
                      while($rowgroup1 = $resgroup1->fetch_assoc()){ ?>  
                      
                      <option value="<?= $rowgroup1['id']?>"><?= $rowgroup1['gname']?></option>
                      <?php } ?>
                      </select>
                    </div>
                  </div>

                  <div class="col-md-4">
                    <div class="form-group">
                      <label for="group2">تصنيف</label>
                      <select name="group2" id="" class="form-control form-control-sm float-right">
                      <?php
                      $resgroup2 = $conn->query("SELECT * FROM item_group2 where isdeleted = 0");
                      while($rowgroup2 = $resgroup2->fetch_assoc()){ ?>  
                      
                      <option value="<?= $rowgroup2['id']?>"><?= $rowgroup2['gname']?></option>
                      <?php } ?>
                      </select>
                    </div>
                  </div>

                </div>
              

                <div class="table-responsive bg-light col-md-12">
                  <table class="table table-hovered table-responsive table-bordered horsTable">
                    <thead>
                      <tr>
                        <th>سعر التكلفه</th>
                        <th>سعر البيع</th>
                        <th>سعر البيع 2</th>
                        <th>سعر السوق</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td><input type="number" value="0.00" name="cost_price" class="form-control form-control-sm nozero" step="any"></td>
                        <td><input type="number" value="0.00" name="price1" class="form-control form-control-sm nozero" step="any"></td>
                        <td><input type="number" value="0.00" name="price2" class="form-control form-control-sm nozero" step="any"></td>
                        <td><input type="number" value="0.00" name="market_price" class="form-control form-control-sm nozero" step="any"></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              
              <div class="card-footer">
                <div class="row">
                  <div class="col">
                    <button type="submit" id="addItemBtn" class="btn btn-success btn-lg float-right btn-block">حفظ</button>
                  </div>
                  <div class="col"></div>
                </div>
              </div>
            </form>
          </div>
        <?php } else { 
          echo $userErrorMassage; 
        } ?>
      </div>
    </div>
  </div>
</div>
