<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<style>
    input{
        margin:0px;
        padding:0px;
        border:0px;
    }
</style>




<?php  if (isset($_GET['i'])) {
    $copy = $_GET['i'];
    $copyname = $conn->query("SELECT name from employees where id = $copy ")->fetch_assoc()['name']; } ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title float-left">
                    اضافة معدلات التقييم  <?= isset($_GET['i']) ? "[ نسخ : ".$copyname." ]" : "" ?>
                </h2>
               

            </div>
            <div class="card-body">
                <form  onsubmit="return validateTotalKBI()" action="do/doadd_empkbi.php" method="POST">   
                
                    <button class="btn btn-lg btn-block bg-green-600 text-slate-50 float-right col-sm-2" tybe="submit">حفظ </button>
                    <div class="form-group">
                        <label for="exampleInputEmail1">اسم الموظف</label>

                        <select name="emp_id" class="form-control col-lg-4" required>
                            <option value="">اختر موظف</option>
                            <?php
                            $sql = "SELECT e.* 
                            FROM `employees` e
                            LEFT JOIN `emp_kbis` k ON e.id = k.emp_id
                            WHERE e.isdeleted != 1 AND k.emp_id IS NULL
                            ORDER BY e.id DESC
                            ";
                            $res = $conn->query($sql);
                            while ($row = $res->fetch_assoc()) {
                            ?>
                                <option value="<?= $row['id'] ?>"><?= $row['name'] ?> --  {<?php $jop = $row['jop'];$rowjop = $conn->query("SELECT name FROM jops where id = $jop")->fetch_assoc();echo $rowjop['name']; ?>}</option>
                            <?php } ?>
                            </select>
                            </div>
                            <br>

                            <?php if (isset($_GET['i'])) { 
                                $sql = "SELECT * FROM emp_kbis where emp_id = $copy"; 
                                $res = $conn->query($sql);
                                while ($row = $res->fetch_assoc()) {
                                ?>
                                <div class="row kbi_div">
                                <select id="" name="kbi_id[]" class="form-control col-lg-4" required>
                                <?php 
                                                $kbi_id = $row['kbi_id'];
                                                $resemkbi = $conn->query("SELECT * FROM `kbis` WHERE id = $kbi_id  AND `isdeleted` != 1");
                                                while ($rowemkbi = $resemkbi->fetch_assoc()) {
                                                ?>
                                                <option value="<?= $rowemkbi['id'] ?>" title="<?= $rowemkbi['info'] ?>" alt="<?= $rowemkbi['info'] ?>"><?= $rowemkbi['kname'] ?></option>
                                                <?php } ?>
                                </select>

                                <input type="text" name="kbi_weight[]" id="" class="form-control col-lg-2 weight" placeholder="" value="<?= $row['kbi_weight'] ?>" required>
                                <button class="btn btn-danger delete-kbi">Delete</button>
                                </div>


                               
                        
                            <?php } }else{ ?>


                                <div class="row kbi_div">

                                <select id="" name="kbi_id[]" class="form-control col-lg-4" required>
                                <?php 
                                                $resemkbi = $conn->query("SELECT * FROM `kbis` WHERE `isdeleted` != 1");
                                                while ($rowemkbi = $resemkbi->fetch_assoc()) {
                                                ?>
                                                <option value="<?= $rowemkbi['id'] ?>" title="<?= $rowemkbi['info'] ?>" alt="<?= $rowemkbi['info'] ?>"><?= $rowemkbi['kname'] ?></option>
                                                <?php } ?>
                                </select>

                                <input type="text" name="kbi_weight[]" id="" class="form-control col-lg-2 weight" placeholder="" value="0.00" required>
                                <button disabled class="btn btn-danger delete-kbi">Delete</button>
                                </div>

                                <?php } ?>
                    <button class="btn bg-sky-300" id="addkbi" tybe="add">+</button>

                    <br>
                    <label for="exampleInputEmail1">المجموع الكلي</label>
                    <input type="text" name="total_kbi" id="total_kbi" class="form-control col-lg-2" placeholder="" value="0.00" required >



                </form>
                
            </div>
            
        </div>








    </div>
    </section>
    </div>




<script>
$("#addkbi").click(function(e) {
    e.preventDefault();
    var kbi_div = $(".kbi_div:last");
    var new_kbi_div = kbi_div.clone();
    new_kbi_div.find('input').val('0.00');
    new_kbi_div.find('.delete-kbi').prop('disabled', false);
    kbi_div.after(new_kbi_div);
});

$(document).on('click', '.delete-kbi', function() {
    if ($('.kbi_div').length > 1) {
        $(this).closest('.kbi_div').remove();
    }
});
</script>
<script>
$(document).ready(function() {
    function updateTotalWeight() {
        var total = 0;
        $('input[name="kbi_weight[]"]').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#total_kbi').val(total.toFixed(2));
    }

    updateTotalWeight();


    $(document).on('input', 'input[name="kbi_weight[]"]', updateTotalWeight);
    $('#addkbi').click(updateTotalWeight);
    $(document).on('click', '.delete-kbi', updateTotalWeight);
});
</script>
<script>
function validateTotalKBI() {
    var totalKBI = document.getElementById('total_kbi').value;
    if (parseFloat(totalKBI) !== 100) {
        alert('Total KBI must be equal to 100');
        return false;
    }
    return true;
}

</script>



<?php include('includes/footer.php') ?>
