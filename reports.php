<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php 
$t = "الكل";
if (isset($_GET['t'])) {
    if ($_GET['t'] == 'rents') {
        $r = 1;
        $t = 'التأجير';
    } elseif ($_GET['t'] == 'acc') {
        $r = 2;
        $t = 'الحسابات';
    }
}
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white text-center">
                    <h3 class="mb-0">📊 تقارير <?php echo $t; ?></h3>
                </div>

                <div class="card-body">
                    <!-- إضافة خانة البحث -->
                    <div class="mb-3">
                        <input type="text" id="reportSearch" class="form-control" placeholder="بحث في التقارير..." />
                    </div>

                    <!-- التقارير العقارية -->
                    <h5 class="text-primary mb-3">
                        <button class="btn btn-link text-primary" data-bs-toggle="" data-bs-target="#rentalReports" aria-expanded="false" aria-controls="rentalReports">
                            🛠️ تقارير عقارية
                        </button>
                    </h5>
                    <div id="rentalReports" class=" show">
                        <div class="row g-3 justify-content-center" id="rentalReportsContent">
                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-primary btn-block btn-sm w-100" href="rentables.php">
                                    🏢 تقرير الوحدات الإيجارية
                                </a>
                            </div>

                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-secondary btn-block btn-sm w-100" href="rentcontracts.php?del=0">
                                    📄 قائمة العقود
                                </a>
                            </div>

                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-danger btn-block btn-sm w-100" href="rentcontracts.php?del=1">
                                    ❌ العقود المنتهية
                                </a>
                            </div>

                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-warning btn-block btn-sm w-100" href="myrentables.php">
                                    💰 الأقساط المستحقة
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- التقارير المالية -->
                    <h5 class="text-success mb-3">
                        <button class="btn btn-link text-success" data-bs-toggle="" data-bs-target="#financialReports" aria-expanded="false" aria-controls="financialReports">
                            💰 تقارير مالية
                        </button>
                    </h5>
                    <div id="financialReports" class=" show">
                        <div class="row g-3 justify-content-center" id="financialReportsContent">
                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-success btn-block btn-sm w-100" href="acc_report.php?acc=clients">
                                    👥 تقرير العملاء
                                </a>
                            </div>
                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-info btn-block btn-sm w-100" href="top_products_report.php">
                                    📊 تقرير الأصناف الأكثر مبيعًا
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- التقارير الإدارية -->
                    <h5 class="text-info mb-3">
                        <button class="btn btn-link text-info" data-bs-toggle="" data-bs-target="#adminReports" aria-expanded="false" aria-controls="adminReports">
                            📋 تقارير إدارية
                        </button>
                    </h5>
                    <div id="adminReports" class=" show">
                        <div class="row g-3 justify-content-center" id="adminReportsContent">
                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-info btn-block btn-sm w-100" href="attendance_report.php">
                                    🕒 تقرير الحضور والانصراف
                                </a>
                            </div>
                            <div class="col-md-4 col-lg-3 report-item">
                                <a class="btn btn-outline-dark btn-block btn-sm w-100" href="staff_report.php">
                                    👨‍💼 تقرير الموظفين
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer text-muted text-center">
                    <small>آخر تحديث: <?= date("Y-m-d H:i") ?></small>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>

<!-- JavaScript للتصفية حسب البحث -->
<script>
    document.getElementById('reportSearch').addEventListener('input', function(e) {
        var searchQuery = e.target.value.toLowerCase();
        document.querySelectorAll('.report-item').forEach(item => {
            item.style.display = item.innerText.toLowerCase().includes(searchQuery) ? 'block' : 'none';
        });
    });
</script>
