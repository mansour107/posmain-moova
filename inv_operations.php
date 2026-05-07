<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<style>
    #msg{
        display: none;
        position: absolute;
        bottom: 20px;
        left: 20px;
        width: 350px;
        height: 70px;
        padding: 20px;
        font-size: 25px;
        background: #00000080;
        color: white;
        text-align: center;
        line-height: 30px;
        z-index: 1000;
        
    }
</style>
<div id="msg" class="btn btn-xl btn-light border"></div>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">

            <?php

                if (!(isset($_GET['q']) && isset($_GET['h']))) {
                    echo $userErrorMassage;
                }elseif (isset($_GET['q']) && isset($_GET['h'])) {
                    $id = $_GET['q'];
                    $hash = md5($id);
                    $h = $_GET['h'];
                    if ($hash != $h ) {
                        echo $userErrorMassage;
                    }else{
                        $q = $_GET['q'];
                        $resop = $conn->query("SELECT * FROM fat_details where fatid = $q AND isdeleted = 0");
                ?>

                <div class="card-header">
                    <h3>العمليات علي الفاتورة</h3>

                </div>
                <div class="card-body">
                    <div class="table">


                    <form action="print/br2538.php" method="post" target="_blank">    
    <div class="btn btn-success"><button type="submit">طباعه الباركود</button></div>
    <table class="font-thin table table-hover table-bordered">
        <thead>
            <tr>
                <th>#</th>
                <th>كود الصنف</th>
                <th>barcode</th>
                <th>اسم الصنف</th>
                <th>سعر الشراء الاخير</th>
                <th>سعر البيع <span class="text-red-500"></span><span class="text-slate-500 font-thin text-sm">(قابل للتغيير)</span></th>
                <th>العدد المطلوب طباعته</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $x = 0;
            while ($rowop = $resop->fetch_assoc()) {
                $itm = $rowop['item_id'];
                $resop2 = $conn->query("SELECT * FROM myitems WHERE id = $itm");
                $rowop2 = $resop2->fetch_assoc();
                $x++;
            ?>
            <tr id="item-<?= $rowop2['id'] ?>">
                <th><?= $x ?></th>
                <th><input readonly type="text" value="<?php $id= $rowop2['id']; $rowunt = $conn->query("SELECT unit_barcode from item_units where item_id= $id ")->fetch_assoc(); echo !empty($rowunt['unit_barcode']) ? $rowunt['unit_barcode'] : $rowop2['barcode']; ?>" name="code[]">
                </th>
                <th><input readonly type="text" value="<?php echo ($rowop2['barcode']); ?>" name="barcode[]"></th>

                <th><input readonly type="text" value="<?= $rowop2['iname'] ?>" name="iname[]"></th>
                <th><input readonly type="text" value="<?= $rowop2['last_price'] ?>" name="last_price[]"></th>
                <th><input type="number" step="0.01" value="<?= $rowop2['price1'] ?>" name="price[]" onchange="updatePrice(<?= $rowop2['id'] ?>, this.value)" class="price"></th>
                <th><input type="number" value="<?= $rowop['qty_in'] ?>" name="qty[]"></th>
                <input type="hidden" value="<?php echo !empty($rowunt['unit_barcode']) ? $rowunt['unit_barcode'] : $rowop2['barcode']; ?>" name="barcode[]">
            </tr>
            <?php } ?>
        </tbody>
    </table>
</form>



                    </div>
                </div>

                <?php }} ?>



            
        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>


<script>
    function updatePrice(itemId, newPrice) {
    $.ajax({
        url: 'js/ajax/update_price.php',
        method: 'POST',
        data: { id: itemId, price: newPrice },
        success: function(response) {
            console.log('Price updated successfully');
            $('#msg').html("تم تغيير السعر بنجاح").show();
            setTimeout(function() {
            $('#msg').hide();}, 3000);


        },
        error: function(xhr, status, error) {
            console.error('Error updating price:', error);
        }
    });
}
$(document).ready(function() {
    $('input[name^="price"]').on('keydown', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            var nextRow = $(this).closest('tr').next('tr');
            if (nextRow.length) {
                nextRow.find('input[name^="price"]').focus();
            }
        }
    });
});

</script>