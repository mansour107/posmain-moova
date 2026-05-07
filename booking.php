<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6 container">
                    <div class="card card-info">
                        <div class="card-header">
                            <h3>الحجز باستخدام الباركود</h3>
                        </div>
                        <div class="card-body">
                                <div class="form-group">
                                    <label>كود العميل</label>
                                    <input type="text" name="code" id="barcode" class="form-control focus:bg-orange-200 frst" placeholder="بحث">
                                </div>

                            <input type="hidden" id="codeid">

                            <button type="button" id="reduce-btn" class="btn btn-primary btn-block" disabled>تخفيض حصة</button>

                            <br>
                            <div class="bg-secondary text-center" id="result-area" style="font-size: 30px; display: none;">
                                <strong>العميل:</strong> <span id="client-name"></span><br><br>
                                <b>عدد الوحدات المتبقية:</b>
                                <div class="bg-warning">
                                    <i id="remain-count"></i>
                                </div>
                                <br>
                                <div class="bg-warning">
                                    <b>صالح ل</b>
                                    <input class="text-lg" type="date" id="valid-date" readonly class="form-control">
                                </div>
                            </div>
                            <br>
                        </div> 
                    </div> 
                </div> 
            </div> 
        </div> 
    </section> 
</div> 

<script>
    $(document).ready(function () {
    const $barcode = $('#barcode');
    const $reduceBtn = $('#reduce-btn');
    const $resultArea = $('#result-area');
    const $clientName = $('#client-name');
    const $remainCount = $('#remain-count');
    const $validDate = $('#valid-date');
    const $hiddenId = $('#codeid');

    function fetchBarcodeData() {
        const code = $barcode.val().trim();
        if (code.length < 3) return hideResult();

        $.post('get/get_barcode_info.php', { code }, function (data) {
            console.log("بيانات الباركود:", data);
            if (data.success) {
                $clientName.text(data.client);
                $remainCount.text(data.remain);
                $validDate.val(data.todate);
                $hiddenId.val(data.id);
                $resultArea.show();
                $reduceBtn.prop('disabled', false);
            } else {
                hideResult();
            }
        }, 'json').fail(() => alert('حدث خطأ في الاتصال بالسيرفر'));
    }

    function hideResult() {
        $resultArea.hide();
        $reduceBtn.prop('disabled', true);
    }

    $barcode.on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            fetchBarcodeData();
        }
    });

    $hiddenId.on('blur', fetchBarcodeData);

    $reduceBtn.on('click', function () {
        const code = $hiddenId.val().trim();
        if (!code) {
            console.warn('لا يوجد كود لتمريره');
            return;
        }

        $.getJSON('get/reduce_remain.php', { code }, function (response) {
            console.log("الاستجابة:", response);
            if (response.success) {
                $remainCount.text(response.remain);
                alert('تم تقليل الحصة بنجاح');
            } else {
                alert('خطأ: ' + response.message);
            }
        }).fail((xhr) => {
            console.error("خطأ:", xhr.responseText);
            alert('حدث خطأ أثناء الاتصال بالسيرفر');
        });
    });
});

</script>

<?php include('includes/footer.php') ?>
