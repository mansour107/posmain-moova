<?php include('includes/header.php'); ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body" id="horsReport">
                    <h1>تقرير المبيعات أصناف</h1>

                    <!-- ✅ نموذج الفلترة -->
                    <form method="GET" class="row mb-4">
                        <div class="col-md-4">
                            <label>من تاريخ:</label>
                            <input type="date" name="from" class="form-control" value="<?= $_GET['from'] ?? '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label>إلى تاريخ:</label>
                            <input type="date" name="to" class="form-control" value="<?= $_GET['to'] ?? '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label style="visibility: hidden;">عرض</label>
                            <button type="submit" class="btn btn-primary btn-block">فلتر</button>
                        </div>
                    </form>
                    <div class="row mb-3">
    <div class="col-md-4">
        <label>فلتر الكمية (JS):</label>
        <select id="qtyFilter" class="form-control">
            <option value="all">عرض الكل</option>
            <option value="greater">أكبر من صفر</option>
            <option value="less">أقل من أو يساوي صفر</option>
            <option value="equal">يساوي صفر</option>
        </select>
    </div>
</div>

                    <!-- ✅ جدول البيانات -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="itemsSummaryTable" data-page-length="100">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الكود</th>
                                    <th>اسم الصنف</th>
                                    <th>ك المبيعات</th>
                                    <th>ق المبيعات</th>
                                    <th>متوسط البيع</th>
                                    <th>س البيع</th>
                                    <th>س ش متوسط</th>
                                    <th>الربح</th>
                                    <th>الربح/ المبيعات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = 0;

                                $from = $_GET['from'] ?? null;
                                $to = $_GET['to'] ?? null;

                                $dateFilter = "";
                                if ($from && $to) {
                                    $dateFilter = "AND crtime BETWEEN '$from 00:00:00' AND '$to 23:59:59'";
                                } elseif ($from) {
                                    $dateFilter = "AND crtime >= '$from 00:00:00'";
                                } elseif ($to) {
                                    $dateFilter = "AND crtime <= '$to 23:59:59'";
                                }

                                $resitm = $conn->query("SELECT * FROM myitems WHERE isdeleted = 0");
                                while ($rowitm = $resitm->fetch_assoc()) {
                                    $x++;
                                    $item_id = $rowitm['id'];

                                    $sumqty = $conn->query("SELECT SUM(qty_out) AS total_qty FROM fat_details WHERE isdeleted = 0 AND item_id = $item_id AND (fat_tybe = 9 OR fat_tybe = 3) $dateFilter")->fetch_assoc();
                                    $sumvalue = $conn->query("SELECT SUM(det_value) AS total_value FROM fat_details WHERE isdeleted = 0 AND item_id = $item_id AND (fat_tybe = 9 OR fat_tybe = 3) $dateFilter")->fetch_assoc();
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $x ?></td>
                                        <td class="text-center"><?= $rowitm['code'] ?></td>
                                        <td class="text-center"><a class="btn btn-light btn-block" href="item_summery.php?id=<?= $rowitm['id']?>"><?= $rowitm['iname'] ?></a></td>
                                        <td class="text-center qty"><?= $sumqty['total_qty'] ?? 0 ?></td>
                                        <td class="text-center val"><?= $sumvalue['total_value'] ?? 0 ?></td>
                                        <td class="text-center price">0</td> <!-- يحسب لاحقًا -->
                                        <td class="text-center price1"><?= $rowitm['price1'] ?></td>
                                        <td class="text-center cost_price"><?= $rowitm['cost_price'] ?></td>
                                        <td class="text-center profit">0</td>
                                        <td class="text-center salesprofit">0%</td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer">
                    <!-- يمكن إضافة ملاحظات أو روابط هنا -->
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>

<script>
    $(document).ready(function() {
        // حساب القيم أولاً قبل تفعيل DataTables
        $('#itemsSummaryTable tbody tr').each(function() {
            var $row = $(this);
            var val = parseFloat($row.find('.val').text()) || 0;
            var qty = parseFloat($row.find('.qty').text()) || 0;
            var costPrice = parseFloat($row.find('.cost_price').text()) || 0;
            var price1 = parseFloat($row.find('.price1').text()) || 0;

            if (qty > 0) {
                var price = val / qty;
                $row.find('.price').text(price.toFixed(2));

                // تغيير لون حسب مقارنة متوسط البيع مع السعر الأساسي
                $row.find('.price').removeClass('bg-red-100 bg-green-100');
                if (price > price1) {
                    $row.find('.price').addClass('bg-green-100'); // ربح
                } else {
                    $row.find('.price').addClass('bg-red-100'); // خسارة
                }

                // حساب الربح
                var profit = (price - costPrice) * qty;
                $row.find('.profit').text(profit.toFixed(2));

                // نسبة الربح إلى المبيعات
                var salesProfit = (val > 0) ? (profit / val) * 100 : 0;
                $row.find('.salesprofit').text(salesProfit.toFixed(2) + '%');
            } else {
                $row.find('.price').text('0');
                $row.find('.profit').text('0');
                $row.find('.salesprofit').text('0%');
            }
        });

        // تحقق إذا كان الجدول متهيأ مسبقاً
        if ($.fn.DataTable.isDataTable('#itemsSummaryTable')) {
            // إذا كان متهيأ، دمره أولاً
            $('#itemsSummaryTable').DataTable().destroy();
        }

        // تفعيل DataTables بعد حساب القيم
        var table = $('#itemsSummaryTable').DataTable({
            "pageLength": 100,
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/Arabic.json",
                "search": "بحث في جميع الأصناف:",
                "lengthMenu": "عرض _MENU_ صنف",
                "info": "عرض _START_ إلى _END_ من _TOTAL_ صنف",
                "infoFiltered": "(تم الفلترة من _MAX_ صنف)",
                "zeroRecords": "لا توجد نتائج",
                "emptyTable": "لا توجد بيانات"
            },
            "order": [[3, "desc"]], // ترتيب حسب كمية المبيعات (تنازلي)
            "columnDefs": [
                { "orderable": false, "targets": 0 } // تعطيل الترتيب للعمود الأول (#)
            ]
        });

        // فلتر الكمية
        $('#qtyFilter').on('change', function() {
            var filter = $(this).val();
            
            // إزالة الفلتر السابق
            $.fn.dataTable.ext.search.pop();
            
            if (filter !== 'all') {
                // إضافة فلتر مخصص
                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        var qty = parseFloat(data[3]) || 0; // العمود الرابع (الكمية)
                        
                        if (filter === 'greater') return qty > 0;
                        if (filter === 'less') return qty <= 0;
                        if (filter === 'equal') return qty == 0;
                        
                        return true;
                    }
                );
            }
            
            // إعادة رسم الجدول
            table.draw();
        });
    });
</script>


<?php include('includes/footer.php'); ?>
