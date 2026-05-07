<?php include('includes/header.php');?>
<?php include('includes/navbar.php');?>
<?php include('includes/sidebar.php');?>

<style>
    .form-hors{
        width: 100%;
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        padding:5px;
        margin: 0px;
    }
</style>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">

    <?php
    $id = $_GET['edit'];
    $sql = "SELECT * FROM `productions` WHERE snd_id = '$id'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    ?>



    <div class="card card-warning">
        <div class="card-header">
        <div class="row">
            <div class="col"><h2 class="">تعديل انتاجية</h2></div>
            <div class="col"><a href="production.php" class="float-right">الانتاجيات</a></div>
        </div>    
        
            
        </div>
        <div class="card-body">
        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#deleteProductionModal"><i class="fa fa-trash"></i></button>

        <div class="modal fade" id="deleteProductionModal">
            <div class="modal-dialog">
            <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">حذف الانتاجية</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>هل انت متاكد من حذف الانتاجية</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">اغلاق</button>
                <a href="do/dodel_production.php?id=<?= $id ?>" class="btn btn-danger">حذف</a>
                        </div>
                    </div>
                </div>
            </div>

                        









            <form action="do/doedit_production.php?edit=<?= $id ?>" method="post" enctype="multipart/form-data">
            
            <div class="table">
                <div class="row">
                    <div class="form-group">
                        <label for="">التاريخ</label>
                        <input type="date" name="date" class="form-control" required value="<?= $row['date'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="">بيان</label>
                        <input type="text" name="info" class="form-control bg-orange-200" style="width: 300px;" value="<?= $row['info'] ?>">    
                    </div>
                    <div class="form-group">
                    <input type="text" value="<?php                          
                        $rowprod = $conn->query("SELECT MAX(snd_id) as max_id FROM productions")->fetch_assoc();
                        $next_id = ($rowprod['max_id'] == null) ? 1 : $rowprod['max_id'] + 1;
                        echo $next_id;
                        ?>"  name="snd_id" hidden class="form-control">
                    </div>
                </div>
                <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>م</th>
                    <th>اسم الموظف</th>
                    <th>الوحدات المنتجه</th>
                    <th>السعر</th>
                    <th>القيمة</th>
                    <th>ملاحظات</th>
                </tr>
                </thead>
                <tbody id="empRow">

                <?php
                $x=0;
                $respro = $conn->query("SELECT * FROM `productions` WHERE snd_id = '$id'");
                while($rowpro = $respro->fetch_assoc()){
                    $x++;
                ?>
                    <tr >
                        <td CLASS="mslsl"><?= $x ?></td>
                        <td>
                            <select autofocus name="emp_name[]" id="" class="form-hors">
                                <?php
                                $resemp = $conn->query("SELECT * FROM `employees` where isdeleted = 0 order by name");
                                while($rowemp = $resemp->fetch_assoc()){
                                ?>
                            <option <?php echo $rowemp['name'] == $rowpro['emp_name'] ? ' selected ' : '' ?> value="<?= $rowemp['name']?>"><?= $rowemp['name']?></option>
                            <?php }?>
                            </select>
                        </td>
                        <td><input type="text" class="form-hors qty" pattern="[0-9]*\.?[0-9]+" value="<?= $rowpro['qty'] ?>" name="qty[]" id=""></td>

                        <td><input type="text" class="form-hors price" pattern="[0-9]*\.?[0-9]+" value="<?= $rowpro['price'] ?>" name="price[]" id=""></td>
                        
                        <td><input type="text" class="form-hors value" pattern="[0-9]*\.?[0-9]+" value="<?= $rowpro['value'] ?>" name="val[]" id=""></td>
                        
                        <td><input type="text" class="form-hors info2" value="<?= $rowpro['info2'] ?>" name="info2[]" id=""></td>
                        
                        <td><button type="button" class="btn btn-danger delete-row">-</button></td>
                    </tr>
                    <?php }?>
                </tbody>
                
                </table>
                <div class="row">
                <div class="col"><button id="addRow" class="btn btn-primary" type="button">+</button></div>
                <div class="col"><button tybe="submit" class="btn bg-yellow-400 btn-block ">تأكيد</button></div>
                    <div class="col"></div>
                </div>
                
            </div>    
            

            </form>

        </div>


    </div>





    </div>
  </section>
</div>


<script>
    $(document).ready(function() {
    $('#addRow').click(function() {
        var $table = $('#empRow');
        var $firstRow = $table.find('tr:first');
        var $mslsl = $table.find('.mslsl:last');
        var $newRow = $firstRow.clone();
        $newRow.find('input').val('1');
        $newRow.find('.mslsl').html(Number($mslsl.html()) + 1);
        $table.append($newRow);
    });
});
</script>
<script>
    $(document).ready(function() {
    $('#empRow').on('input', '.qty, .price', function() {
        var $row = $(this).closest('tr');
        var qty = parseFloat($row.find('.qty').val()) || 0;
        var price = parseFloat($row.find('.price').val()) || 0;
        var value = qty * price;
        $row.find('.value').val(value.toFixed(2));
    });
});

</script>
<script>
$(document).ready(function() {
    $('#empRow').on('click', '.delete-row', function() {
        var $row = $(this).closest('tr');
        if ($row.index() !== 0) {
            $row.remove();
        }
    });
});
</script>




<?php include('includes/footer.php');?>
