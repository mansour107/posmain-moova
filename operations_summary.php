<?php 
include('includes/header.php'); 
include('includes/navbar.php'); 
include('includes/sidebar.php'); 


$q = isset($_GET['q']) ? $_GET['q'] : "all";  // استقبال قيمة q من GET
$strtdate = isset($_GET['strtdate']) ? $_GET['strtdate'] : (isset($_POST['strtdate']) ? $_POST['strtdate'] : null);
$enddate = isset($_GET['enddate']) ? $_GET['enddate'] : (isset($_POST['enddate']) ? $_POST['enddate'] : null);

$dateFilter = "";
if ($strtdate && $enddate) {
    $dateFilter = "AND pro_date BETWEEN '$strtdate' AND '$enddate'";
} else {
    $dateFilter = "AND pro_date = '$today'";
}

// Pagination
$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

switch ($q) {
    case "sale":
        $report_name = "مشتريات";
        $where_clause = "pro_tybe = 4 AND isdeleted != 1 $dateFilter";
        $resop = $conn->query("SELECT * FROM ot_head WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset");
        break;
    case "buy":
        $report_name = "مبيعات";
        $where_clause = "(pro_tybe = 2 OR pro_tybe = 3 OR pro_tybe = 9) AND isdeleted != 1 $dateFilter";
        $resop = $conn->query("SELECT * FROM ot_head WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset");
        break;
    default:
        $report_name = "التقرير الشامل";
        $where_clause = "isdeleted != 1 $dateFilter";
        $resop = $conn->query("SELECT * FROM ot_head WHERE $where_clause ORDER BY id DESC LIMIT $limit OFFSET $offset");
}
?>


<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3> - محلل العمل اليومي <?= $report_name ?></h3>
                    
                    <?php if (isset($_GET['success']) && $_GET['success'] == 'deleted'): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fa fa-check-circle"></i> تم حذف الفاتورة بنجاح
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
              
                    <form action="" method="get">
                    <?php
                        $strtdate_display = $strtdate ?: date("Y-m-d");
                        $enddate_display = $enddate ?: date("Y-m-d");
                        ?>
                        <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <div class="row">
                            <div class="col-md-4 col-sm-6 col-12 mb-2">
                                <label>من</label>
                                <input class="form-control" type="date" value="<?= $strtdate_display ?>" name="strtdate">
                            </div>
                            <div class="col-md-4 col-sm-6 col-12 mb-2">
                                <label>إلى</label>
                                <input class="form-control" type="date" value="<?= $enddate_display ?>" name="enddate">
                            </div>
                            <div class="col-md-4 col-12 mb-2">
                                <label class="d-none d-md-block">&nbsp;</label>
                                <button class="btn btn-primary btn-block" type="submit">
                                    <i class="fa fa-search"></i> بحث
                                </button>
                            </div>
                        </div>

                    </form>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered table-sm" id="" data-page-length="10">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الوقت و التاريخ</th>
                                    <th>اسم العملية</th>
                                    <th>قيمة العملية</th>
                                    <th>صافي العملية</th>
                                    <th>الحساب</th>
                                    <th>الحساب المقابل</th>
                                    <th>المخزن</th>
                                    <th>الموظف</th>
                                    <th>الربح</th>
                                    <th>المستخدم</th>
                                    <th>معرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $x = $offset; // بدء العد من الـ offset
                                while ($rowop = $resop->fetch_assoc()) {
                                    $x++;
                                    $proid = $rowop['id'];
                                    $tybe = $rowop['pro_tybe'];
                                    ?>
                                    <tr>
                                        <td><?= $x ?></td>
                                        <td><?= $rowop['crtime'] ?></td>
                                        <td>
                                            <a class="btn btn-block btn-light border" href="print/<?= ($tybe == 4 || $tybe == 3) ? 'print_sales' : 'receipt' ?>.php?id=<?= $proid ?>" target="_blank">
                                                <?= $conn->query("SELECT pname FROM pro_tybes WHERE id = $tybe")->fetch_assoc()['pname'] ?>
                                            </a>
                                        </td>
                                         <td class="value"><?= $rowop['pro_value'] ?></td>
                                        <td class="fatnet <?php if($rowop['pro_value'] != $rowop['fat_net']){echo "bg-yellow-300";} ?>">
                                            <?= $rowop['fat_net'] - ($rowop['jal_amount'] ?? 0) ?>
                                            <?php if(($rowop['jal_amount'] ?? 0) > 0): ?>
                                                <small class="d-block text-muted" style="font-size: 0.65rem;">(أجل: <?= $rowop['jal_amount'] ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $conn->query("SELECT aname FROM acc_head WHERE id = {$rowop['acc1']}")->fetch_assoc()['aname'] ?></td>
                                        <td><?= $conn->query("SELECT aname FROM acc_head WHERE id = {$rowop['acc2']}")->fetch_assoc()['aname'] ?></td>
                                        <td><?= $rowop['store_id'] > 0 ? $conn->query("SELECT aname FROM acc_head WHERE id = {$rowop['store_id']}")->fetch_assoc()['aname'] : '' ?></td>
                                        <td><?= $rowop['emp_id'] > 0 ? $conn->query("SELECT aname FROM acc_head WHERE id = {$rowop['emp_id']}")->fetch_assoc()['aname'] : '' ?></td>
                                         <td class="prft"><?= $rowop['profit'] ?></td>
                                        <td><?= $conn->query("SELECT uname FROM users WHERE id = {$rowop['user']}")->fetch_assoc()['uname'] ?></td>
                                        <td>
                                            <?= $rowop['id'] ?>
                                            <a href="inv_operations.php?h=<?= md5($proid) ?>&q=<?= $proid ?>&t=<?= md5($tybe) ?>">
                                                <i class="fa fa-barcode"></i>
                                            </a>
                                            <?php $proid = $rowop['id']?>
                                            
                                            <!-- زر التعديل -->
                                            <?php if(in_array($tybe, [3, 4, 9])) { // مبيعات، مشتريات، كاشير ?>
                                            <a href="sales.php?edit_id=<?= $rowop['id'] ?>" class="btn btn-sm btn-warning" title="تعديل">
                                                <i class="fa fa-edit"></i>
                                            </a>
                                            <?php } ?>

                                            <!-- زر تفاصيل الأجل -->
                                            <?php if (!empty($rowop['jal_amount']) && $rowop['jal_amount'] > 0): ?>
                                            <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#jalModal<?= $rowop['id']?>" title="تفاصيل الأجل">
                                                <i class="fa fa-clock"></i>
                                            </button>

                                            <div class="modal fade" id="jalModal<?= $rowop['id']?>" tabindex="-1" role="dialog" aria-labelledby="jalModalLabel<?= $rowop['id']?>" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-info text-white">
                                                            <h5 class="modal-title" id="jalModalLabel<?= $rowop['id']?>">
                                                                <i class="fa fa-clock"></i> تفاصيل الأجل - فاتورة #<?= $rowop['id'] ?>
                                                            </h5>
                                                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body text-right">
                                                            <div class="form-group border-bottom pb-2">
                                                                <label class="font-weight-bold text-muted">اسم العميل (أجل):</label>
                                                                <p class="h5"><?= !empty($rowop['jal_name']) ? htmlspecialchars($rowop['jal_name']) : '<span class="text-muted">غير محدد</span>' ?></p>
                                                            </div>
                                                            <div class="form-group border-bottom pb-2">
                                                                <label class="font-weight-bold text-muted">قيمة الأجل:</label>
                                                                <p class="h4 text-danger"><?= number_format($rowop['jal_amount'], 2) ?> ج.م</p>
                                                            </div>
                                                            <div class="form-group">
                                                                <label class="font-weight-bold text-muted">ملاحظات:</label>
                                                                <p class="mb-0 bg-light p-2 rounded border"><?= !empty($rowop['jal_notes']) ? nl2br(htmlspecialchars($rowop['jal_notes'])) : 'لا توجد ملاحظات' ?></p>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">إغلاق</button>
                                                            
                                                            <form action="do/settle_credit.php" method="POST" onsubmit="return confirm('هل أنت متأكد من تسوية هذا المبلغ؟ سيتم تصفير الأجل واعتباره مدفوعاً.');">
                                                                <input type="hidden" name="id" value="<?= $rowop['id'] ?>">
                                                                <button type="submit" class="btn btn-success">
                                                                    <i class="fa fa-check me-1"></i> تم الدفع (تسوية)
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- زر الحذف -->
                                            <a href="#" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#deleteModal<?= $rowop['id']?>" data-id="<?= $id; ?>">
                                                <i class="fa fa-trash"></i>
                                            </a>

                                            <form action="do/dodel_invoice.php?id=<?= $rowop['id'] ?>" method="post">
                                                <input type="hidden" name="q" value="<?= $q ?>">
                                            
                                            <div class="modal fade" id="deleteModal<?= $rowop['id']?>" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
                                                <div class="modal-dialog" role="document">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="deleteModalLabel">تأكيد الحذف <?= $rowop['id']?></h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="إغلاق">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>هل أنت متأكد من أنك تريد حذف هذه الفاتورة؟</p>
                                                            <p><strong>رقم الفاتورة:</strong> <?= $rowop['id'] ?></p>
                                                            <p><strong>نوع العملية:</strong> <?= $conn->query("SELECT pname FROM pro_tybes WHERE id = $tybe")->fetch_assoc()['pname'] ?></p>
                                                            <label for="pass">كلمة المرور:</label>
                                                            <input type="password" name="pass" class="form-control" placeholder="أدخل كلمة مرور الحذف" required>
                                                            
                                                            <div class="form-check mt-3">
                                                                <input type="checkbox" class="form-check-input" id="forceDelete<?= $rowop['id']?>" name="force_delete" value="1">
                                                                <label class="form-check-label text-warning" for="forceDelete<?= $rowop['id']?>">
                                                                    <small>حذف قسري (تجاهل العمليات المرتبطة)</small>
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                                            <button type="submit" class="btn btn-danger">حذف</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            </form>

                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                        <table>
                        <tbody>
                                <tr>
                                    <td> اجمالي </td>
                                    <td class="bg-zinc-100" id="total"></td>
                                    <td> _       _ </td>
                                    <td></td>
                                    <td> صافي </td>
                                    <td class="" id="fatnet"></td>
                                    <td> _       _ </td>

                                    <td> ارباح </td>
                                    <td class="" id="profit"></td>
                                    <td></td>
                                </tr>
                            </tbody>
                       
                        </table>
                    </div>
                </div>
                
                <!-- Pagination -->
                <div class="card-footer">
                    <nav aria-label="Page navigation">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php
                            // حساب إجمالي السجلات
                            $count_query = $conn->query("SELECT COUNT(*) as total FROM ot_head WHERE $where_clause");
                            $total_items = $count_query->fetch_assoc()['total'];
                            $total_pages = ceil($total_items / $limit);
                            
                            // بناء query string للفلاتر
                            $filter_params = "q=$q";
                            if (!empty($strtdate)) $filter_params .= "&strtdate=" . urlencode($strtdate);
                            if (!empty($enddate)) $filter_params .= "&enddate=" . urlencode($enddate);
                            
                            // زر السابق
                            if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="operations_summary.php?<?= $filter_params ?>&page=<?= $page - 1 ?>" aria-label="Previous">
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
                                    <a class="page-link" href="operations_summary.php?<?= $filter_params ?>&page=1">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif;
                            endif;
                            
                            // الصفحات المحيطة
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="operations_summary.php?<?= $filter_params ?>&page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor;
                            
                            // الصفحة الأخيرة
                            if ($end_page < $total_pages): 
                                if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="operations_summary.php?<?= $filter_params ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a>
                                </li>
                            <?php endif;
                            
                            // زر التالي
                            if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="operations_summary.php?<?= $filter_params ?>&page=<?= $page + 1 ?>" aria-label="Next">
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
                                عرض <?= ($offset + 1) ?> - <?= min($offset + $limit, $total_items) ?> من <?= $total_items ?> عملية
                            </small>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    </section>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const total = Array.from(document.querySelectorAll(".value")).reduce((sum, el) => sum + parseFloat(el.textContent || 0), 0);
        const fatnet = Array.from(document.querySelectorAll(".fatnet")).reduce((sum, el) => sum + parseFloat(el.textContent || 0), 0);
        const profit = Array.from(document.querySelectorAll(".prft")).reduce((sum, el) => sum + parseFloat(el.textContent || 0), 0);
        document.getElementById("total").textContent = total.toFixed(2);
        document.getElementById("fatnet").textContent = fatnet.toFixed(2);
        document.getElementById("profit").textContent = profit.toFixed(2);
    });
</script>
<?php include('includes/footer.php'); ?>
