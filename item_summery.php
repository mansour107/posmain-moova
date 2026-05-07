<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <?php
                if (isset($_GET['id'])) {
                    $itmid = intval($_GET['id']);
                    $sqlitm = "SELECT * FROM myitems WHERE id = '$itmid'";
                    $resitm = mysqli_query($conn, $sqlitm);
                    $rowitm = mysqli_fetch_assoc($resitm);
                    
                    // التحقق من وجود الصنف
                    if (!$rowitm) {
                        echo '<div class="alert alert-danger">الصنف غير موجود أو تم حذفه</div>';
                        echo '<a href="items_summery.php" class="btn btn-primary">العودة لقائمة الأصناف</a>';
                        echo '</div></div></div></section></div>';
                        include('includes/footer.php');
                        exit;
                    }
                } else {
                    echo '<div class="alert alert-warning">يرجى تحديد رقم الصنف</div>';
                    echo '<a href="items_summery.php" class="btn btn-primary">العودة لقائمة الأصناف</a>';
                    echo '</div></div></div></section></div>';
                    include('includes/footer.php');
                    exit;
                }
                ?>
                <div class="card-header">
                    <h2 class="hors-head hazaz"> حركة صنف [ <?= htmlspecialchars($rowitm['iname']) ?> ]</h2>
                </div>

                <div class="card-body">
                    <!-- ✅ نموذج الفلاتر -->
                    <form method="GET" class="row mb-4">
                        <input type="hidden" name="id" value="<?= $itmid ?>">
                        <div class="col-md-3">
                            <label>من تاريخ:</label>
                            <input type="date" name="from" class="form-control" value="<?= $_GET['from'] ?? '' ?>">
                        </div>
                        <div class="col-md-3">
                            <label>إلى تاريخ:</label>
                            <input type="date" name="to" class="form-control" value="<?= $_GET['to'] ?? '' ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label style="visibility: hidden;">عرض</label>
                            <button type="submit" class="btn btn-primary btn-block">فلتر</button>
                        </div>
                    </form>

                    <!-- ✅ جدول البيانات -->
                    <div class="table-responsive">
                        <table class="table table-bordered" id="myTable" data-page-length="50">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>التاريخ</th>
                                    <th>نوع العملية</th>
                                    <th>المخزن</th>
                                    <th>رقم الصنف</th>
                                    <th class="td5">كميه واردة</th>
                                    <th class="td6">كمية منصرفة</th>
                                    <th class="td7">رصيد الصنف بعد</th>
                                    <th>سعر</th>
                                    <th>تكلفة الصنف</th>
                                    <th>الربح</th>
                                    <th>id</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (isset($_GET['id'])) {
                                    $where = "item_id = $itmid AND isdeleted = 0";

                                    if (!empty($_GET['from'])) {
                                        $from = $_GET['from'];
                                        $where .= " AND crtime >= '$from 00:00:00'";
                                    }
                                    if (!empty($_GET['to'])) {
                                        $to = $_GET['to'];
                                        $where .= " AND crtime <= '$to 23:59:59'";
                                    }
                                    
                                    // Pagination
                                    $limit = 100;
                                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                                    $offset = ($page - 1) * $limit;

                                    $resdet = $conn->query("SELECT * FROM fat_details WHERE $where ORDER BY crtime LIMIT $limit OFFSET $offset");
                                    if ($resdet->num_rows > 0) {
                                        $hash = $offset; // بدء العد من الـ offset
                                        while ($rowdet = $resdet->fetch_assoc()) {
                                            $hash++;
                                            $itmid = $rowdet['item_id'];
                                            $storeid = $rowdet['det_store'];
                                            $protybe_id = $rowdet['pro_tybe'];
                                            $datetime = $rowdet['crtime'];

                                            // جلب اسم نوع العملية
                                            $protybe = $conn->query("SELECT pname FROM pro_tybes WHERE id = $protybe_id")->fetch_assoc();
                                            // جلب اسم المخزن
                                            $store = $conn->query("SELECT aname FROM acc_head WHERE id = $storeid")->fetch_assoc();
                                            // جلب الباركود
                                            $iname = $conn->query("SELECT barcode FROM myitems WHERE id = $itmid")->fetch_assoc();
                                ?>
                                            <tr>
                                                <td><?= $hash ?></td>
                                                <td><?= $datetime ?></td>
                                                <td>
                                                    <a href="<?php
                                                                $pro_id = $rowdet['pro_id'];
                                                                if ($protybe_id == 3 || $protybe_id == 4) {
                                                                    echo "sales.php?e=$pro_id";
                                                                }
                                                                ?>">
                                                        <?= $protybe['pname'] ?>
                                                    </a>
                                                </td>
                                                <td><?= $store['aname'] ?></td>
                                                <td><?= $iname['barcode'] ?></td>
                                                <td class="td5"><?= $rowdet['qty_in'] ?></td>
                                                <td class="td6"><?= $rowdet['qty_out'] ?></td>
                                                <td class="td7"></td>
                                                <td><?= $rowdet['price'] ?></td>
                                                <td><?= $rowdet['cost_price'] ?></td>
                                                <td><?= $rowdet['profit'] ?></td>
                                                <td></td>
                                            </tr>
                                <?php
                                        }
                                    }
                                    
                                    // حساب الإجماليات لكل الصفحات
                                    $totals_query = $conn->query("SELECT 
                                        SUM(qty_in) as total_in, 
                                        SUM(qty_out) as total_out 
                                        FROM fat_details 
                                        WHERE $where");
                                    $totals = $totals_query->fetch_assoc();
                                    $total_in = $totals['total_in'] ?? 0;
                                    $total_out = $totals['total_out'] ?? 0;
                                    $total_balance = $total_in - $total_out;
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-50">
                                    <th colspan="5">إجمالي الوارد (كل الصفحات):</th>
                                    <th><b id="sum_in"></b></th>
                                    <th>إجمالي المنصرف:</th>
                                    <th><b id="sum_out"></b></th>
                                    <th colspan="2">الرصيد الحالي:</th>
                                    <th class="bg-sky-200"><b id="sum_all"></b></th>
                                </tr>
                            </tfoot>
                        </table>
                        
                        <?php if (isset($_GET['id'])): ?>
                        
                        <script>
                            // عرض الإجماليات الكلية
                            document.getElementById('sum_in').textContent = <?= $total_in ?? 0 ?>;
                            document.getElementById('sum_out').textContent = <?= $total_out ?? 0 ?>;
                            document.getElementById('sum_all').textContent = <?= $total_balance ?? 0 ?>;
                        </script>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="card-footer">
                    <?php if (isset($_GET['id'])): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php
                            // حساب إجمالي السجلات
                            $count_query = $conn->query("SELECT COUNT(*) as total FROM fat_details WHERE $where");
                            $total_items = $count_query->fetch_assoc()['total'];
                            $total_pages = ceil($total_items / $limit);
                            
                            // بناء query string للفلاتر
                            $filter_params = "id=$itmid";
                            if (!empty($_GET['from'])) $filter_params .= "&from=" . $_GET['from'];
                            if (!empty($_GET['to'])) $filter_params .= "&to=" . $_GET['to'];
                            
                            // زر السابق
                            if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="item_summery.php?<?= $filter_params ?>&page=<?= $page - 1 ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">&laquo;</span>
                                </li>
                            <?php endif;
                            
                            // عرض أرقام الصفحات
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            // الصفحة الأولى
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="item_summery.php?<?= $filter_params ?>&page=1">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif;
                            endif;
                            
                            // الصفحات المحيطة
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="item_summery.php?<?= $filter_params ?>&page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor;
                            
                            // الصفحة الأخيرة
                            if ($end_page < $total_pages): 
                                if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="item_summery.php?<?= $filter_params ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a>
                                </li>
                            <?php endif;
                            
                            // زر التالي
                            if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="item_summery.php?<?= $filter_params ?>&page=<?= $page + 1 ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">&raquo;</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                عرض <?= ($offset + 1) ?> - <?= min($offset + $limit, $total_items) ?> من <?= $total_items ?> حركة
                            </small>
                        </div>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {
        <?php if (isset($_GET['id'])): ?>
        let cumulativeSum = 0; // البدء من صفر لكل صفحة

        $('#myTable tbody tr').each(function() {
            let qtyIn = parseFloat($(this).find('.td5').text()) || 0;
            let qtyOut = parseFloat($(this).find('.td6').text()) || 0;
            cumulativeSum += qtyIn - qtyOut;
            $(this).find('.td7').text(cumulativeSum.toFixed(2));
        });

        // تلوين الخلايا السالبة
        $('.td7').each(function() {
            if (parseFloat($(this).text()) < 0) {
                $(this).addClass('bg-danger text-white');
            }
        });
        <?php endif; ?>
    });
</script>

<?php include('includes/footer.php') ?>
