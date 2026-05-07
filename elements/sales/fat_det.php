<div class="row ">
    <div class="col">
 <div class="table-responsive itemtable " style="height: 300px">
        <table id="fatTable" class="table table-hover table-striped table-bordered">
            <thead class="bg-light">
                <tr class="bg- border">
                    <th>م</th>
                    <th class="col-5">اسم الصنف</th>
                    <th>الوحدة</th>
                    <th>كمية</th>
                    <th>سعر</th>
                    <th>خصم</th>
                    <th>القيمة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="itmrow" >


            
              <?php
              if (isset($_GET['e'])) { 
                $x=0;   
              $resdet = $conn->query("SELECT * FROM fat_details where pro_id = $opid AND isdeleted = 0;");
              while ($rowdet = $resdet->fetch_assoc()){
                $x++;
                $itemid = $rowdet['item_id'];
                $rowitemname = $conn->query("SELECT iname from myitems where id = $itemid")->fetch_assoc();
                ?>
            <tr>
              <td class="col-1"><?= $x?>
            <input type="text" name="det_id[]" id="" hidden value="<?= $rowdet['id'] ?>">
            <input type="text" name="detcrtime[]" id="" hidden value="<?= $rowdet['crtime'] ?>">
            </td>
              <!-- الصنف -->
              <td id="itmTd" class="col-lg-5">

                <p><?= $rowitemname['iname']?></p>
                <input id="itmprice2" type="number" name="itmname[]" hidden onclick=sT(this) value="<?= $rowdet['item_id']?>">
              </td>
        
              <!-- الوحدة -->
              <td>
               
              <select name="u_val[]" id="" class="form-control form-control-sm" style="width:100px;">
                <?php
                $itm = $rowdet['item_id']; 
                $resunt = $conn->query("SELECT * FROM item_units WHERE item_id = $itm");
                while ($rowunt = $resunt->fetch_assoc()) {
                    $unit = $conn->query("SELECT id,uname FROM myunits WHERE id = '{$rowunt['unit_id']}'")->fetch_assoc();
                    $unitName = $unit ? $unit['uname'] : '';
                    ?>
                    <option value="<?= $rowunt['u_val'] ?>" <?php if($rowunt['unit_id'] == $unit['id']){echo "selected";} ?>><?= $unitName ?></option>
                <?php } ?>
              </select>
              </td>


              <!-- الكمية -->
              <td>
              <input id="itmqty" value="<?php echo abs($rowdet['qty_in'] - $rowdet['qty_out']) / $rowdet['u_val']; ?>" type="number" name="itmqty[]" onclick="sT(this)" class="itmqty form-control form-control-sm" style="width:90px;">
              </td>



              <!-- السعر -->
              <td>
                <input id="itmprice" type="number" name="itmprice[]" onclick=sT(this) class="itmprice form-control form-control-sm" style="width:90px;" value="<?php if(isset($_GET['e'])){echo $rowdet['price']*$rowdet['u_val'];}else{echo 0.00;}?>">
              </td>

              <!-- الخصم -->
              <td>
                <input id="itmdisc" value="<?php if(isset($_GET['e'])){echo $rowdet['discount'];}else{echo 0.00;}?>" type="number" name="itmdisc[]" onclick=sT(this) class="itmdisc form-control form-control-sm" style="width:120px;">
              </td>


              <!-- القيمة -->
              <td>
                <input readonly id="itmval" value="<?php if(isset($_GET['e'])){echo $rowdet['det_value'];}else{echo 0.00;}?>" type="number" name="itmval[]" class="itmval bg-light form-control form-control-sm" style="width:150px;">
              </td>


              
              <td>
              <input id="itmprofit" name="itmprofit" hidden>
              <button class="deleteRow btn btn-danger">X</button>
              </td>
            </tr>

              <?php }}?>





<!--                ttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt
                    tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt

-->













            </tbody>
        </table>
        </div>
    </div>
</div>