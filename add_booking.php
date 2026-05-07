<?php


 include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
<section class="content-header">
<div class="container-fluid">
    <div class="row text-center">


          
        <!-- الحجز اليدوي -->
        <div class="container col-md-7">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="cake cake-zoomIn">ادخال كارت جديد</h3>
                </div>
                <div class="card-body">
                    <form action="do/doadd_booking.php" method="post">
                        <div class="form-group">
                            <label class="cake cake-zoomIn">اسم العميل</label>
                            <input list="customerList" name="cname" id="cname" class="form-control" placeholder="ابحث عن العميل هنا" required>
                            <datalist id="customerList">
                                <?php
                                $ressrch = $conn->query("SELECT name FROM clients");
                                while ($rowcname = $ressrch->fetch_assoc()) {
                                    echo '<option value="' . htmlspecialchars($rowcname['name']) . '">';
                                }
                                ?>
                            </datalist>
                            <small id="cname_status" class="form-text text-muted"></small>
                        </div>


                        <div class="form-group">
                            <label class="cake cake-zoomIn">كود الحجز</label>
                            <input type="text" name="barcode" id="barcode" class="form-control" placeholder="" required>
                            <small id="barcode_status" class="form-text text-muted"></small>
                        </div>



                        <div class="row">
                            <div class="col">
                                <div class="form-group">
                                    <label class="cake cake-zoomIn">نوع الحجز</label>
                                    <select name="rtybe" id="bmethod" class="form-control" required>
                                        <option value="">-- اختر --</option>
                                        <?php 
                                        $resbtybe = $conn->query("SELECT * FROM book_tybes WHERE isdeleted = 0");
                                        while ($rowbtybe = $resbtybe->fetch_assoc()) {
                                        ?>
                                        <option value="<?= $rowbtybe['id']?>"><?= $rowbtybe['name']?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col">
                            <div class="form-group">
                                <label class="cake cake-zoomIn">قيمة الحجز</label>
                                <input type="number" name="rcost" id="rcost" class="form-control" required>
                            </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col"></div>
                            <div class="col">
                            <div class="form-group">
                                <label class="cake cake-zoomIn">عدد</label>
                                <input type="number" name="qty" id="qty" class="form-control" required>
                            </div>
                            </div>
                        </div>
                        
                        



                        <div class="row">
                            <div class="col">
                                <label class="cake cake-zoomIn">من</label>
                                <input type="date" name="fromdate" value="<?= date('Y-m-d') ?>" class="form-control" placeholder="" required>
                            </div>
                            <div class="col">
                                <label class="cake cake-zoomIn">إلى</label>
                                <input type="date" name="todate" class="form-control" placeholder="" required>
                            </div>
                        </div>
                        <br>
                        <button type="submit" class="btn btn-success btn-success">حجز</button>
                    </form>
                </div>
            </div>
        </div>



    </div>
</div>
</section>
</div>






<script>
$(document).ready(function(){
    $('#cname').on('blur', function(){
        var cname = $(this).val();
        if(cname != ''){
            $.ajax({
                url: 'get/chk_client.php',
                method: 'POST',
                data: { cname: cname },
                success: function(response){
                    $('#cname_status').text(response);
                }
            });
        }
    });
});
</script>
<script>
$(document).ready(function(){
    $('#barcode').on('blur', function(){
        var barcode = $(this).val();
        if(barcode != ''){
            $.ajax({
                url: 'get/check_barcode.php',
                method: 'POST',
                data: { barcode: barcode },
                success: function(response){
                    $('#barcode_status').html(response);
                }
            });
        }
    });
});
</script>

<script>
$(document).ready(function(){
    $('#bmethod').on('change', function(){
        var bookingTypeId = $(this).val();
        if (bookingTypeId != '') {
            $.ajax({
                url: 'get/get_bvalue.php',
                method: 'POST',
                data: { id: bookingTypeId },
                dataType: 'json', // مهم لفهم JSON تلقائيًا
                success: function(data){
                    $('#rcost').val(data.value);
                    $('#qty').val(data.qty);
                },
                error: function(xhr, status, error){
                    alert("حدث خطأ في تحميل البيانات");
                }
            });
        } else {
            $('#rcost').val('');
            $('#qty').val('');
        }
    });
});

</script>


<?php include('includes/footer.php') ?>
