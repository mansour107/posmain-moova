<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- إضافة CSS التحسينات -->
<link href="dist/css/shift_notifications.css" rel="stylesheet">

<div class="content-wrapper">

<!-- عرض رسالة إغلاق الشيفت -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <strong><?= htmlspecialchars($_SESSION['success_message']) ?></strong>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php 
    unset($_SESSION['success_message']);
endif; 
?>

<!-- عرض رسائل الخطأ -->
<?php if (isset($_SESSION['error_message'])): ?>
<div class="container-fluid mt-3">
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error_message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php 
    unset($_SESSION['error_message']);
endif; 
?>
  <!-- Content Header (Page header) -->
  <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <div class="row">
                        <div class="col">
                        <h3>الشيفتات المغلقة</h3>
                        </div>
                    </div>
                </div>
                <div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover table-striped table-bordered table-sm">
            <thead class="thead-dark">
                <tr>
                    <th>الشيفت</th>
                    <th>التاريخ</th>
                    <th>المستخدم</th>
                    <th>وقت الانهاء</th>
                    <th>اجمالي المبيعات</th>
                    <th>المصاريف</th>
                    <th>بيان المصاريف</th>
                    <th>تسليم الكاش</th>
                    <th>نهاية الدرج</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // إعدادات الترقيم
                $records_per_page = 20;
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $page = max(1, $page);
                $offset = ($page - 1) * $records_per_page;
                
                // حساب إجمالي السجلات
                $total_count = $conn->query("SELECT COUNT(*) as count FROM closed_orders")->fetch_assoc()['count'];
                $total_pages = ceil($total_count / $records_per_page);
                
                // جلب السجلات للصفحة الحالية
                $x = $total_count - $offset;
                $res_closed = $conn->query("SELECT * FROM closed_orders ORDER BY id DESC LIMIT $offset, $records_per_page");
                
                while ($rowcl = $res_closed->fetch_assoc()) {
                ?> 
                <tr>
                    <td><?= $x ?></td>
                    <td><?= $rowcl['date'] ?></td>
                    <td class="bg-primary text-white"><?= $rowcl['user'] ?></td>
                    <td><?= $rowcl['endtime'] ?></td>
                    <td class="bg-success text-white"><?= $rowcl['total_sales'] ?></td>
                    <td class="bg-danger text-white"><?= $rowcl['expenses'] ?></td>
                    <td><?= $rowcl['exp_notes'] ?></td>
                    <td class="bg-secondary text-white"><?= $rowcl['cash'] ?></td>
                    <td class="bg-light"><?= $rowcl['fund_after'] ?></td>
                    <td><?= $rowcl['info'] ?></td>
                </tr>
                <?php $x--; } ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center mb-0">
                <!-- زر الصفحة السابقة -->
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?>" aria-label="السابق">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                
                <?php
                // عرض أرقام الصفحات
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                    if ($start_page > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    $active = ($i == $page) ? 'active' : '';
                    echo '<li class="page-item ' . $active . '"><a class="page-link" href="?page=' . $i . '">' . $i . '</a></li>';
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '">' . $total_pages . '</a></li>';
                }
                ?>
                
                <!-- زر الصفحة التالية -->
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?>" aria-label="التالي">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="text-center mt-2">
            <small class="text-muted">
                الصفحة <?= $page ?> من <?= $total_pages ?> (إجمالي <?= $total_count ?> شيفت)
            </small>
        </div>
    </div>
    <?php endif; ?>
</div>

          
            </div>





        </div>
    </section>
</div>




<?php include('includes/footer.php') ?>
