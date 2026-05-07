<?php include('includes/header.php') ?>

    <div class="card-header bg-gradient-to-r from-zinc-50 to-sky-100">
        <h3 class="cake cake-headShake text-center "> عرض سعر الصنف بالباركود</h3>

    </div>
    <div class="card-body">
        
    <!-- داخل card-body -->
    <div class="min-h-screen bg-gradient-to-b from-zinc-50 to-sky-100">
    <center class="pt-20">
        <input type="text" name="iname" class="form form-control blocked frst focus:bg-orange-200 text-navey-400 selected" id="searchItem" placeholder="امسح الباركود هنا">
        <br>
        <p id="itemName" style="font-size: 4vw" class="text-red-500">اسم الصنف</p>
        <br>
        <p id="itemPrice" style="font-size: 17vw" class="text-red-500">0000</p>
    </center>
</div>

<script>$(document).ready(function () {
    $('#searchItem').on('change', function () {
        let barcode = $(this).val();

        $.getJSON('get/get_price1.php', { barcode: barcode }, function (data) {
            $('#itemName').text(data.iname || "غير موجود");
            let $price = $('#itemPrice');
            let $iname = $('#itemName');
            $iname
                .removeClass('cake cake-headShake')
            $price
                .removeClass('cake cake-bounce')     // إزالة الكلاسات
                .text(data.price1|| "_____");        // تحديث السعر
            setTimeout(function () {
                $price.addClass('cake cake-bounce');
                $iname.addClass('cake cake-headShake');
            }, 10);
        });
        $(this).val('').focus();
    });
});

</script>
    </div>


<?php include('includes/footer.php') ?>
