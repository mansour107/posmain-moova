<?php include('includes/header.php');?>
<link rel="stylesheet" href="dist/css/pos.css">

<?php
// إنشاء جدول الطاولات إذا لم يكن موجوداً
$sql = "CREATE TABLE IF NOT EXISTS tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tname VARCHAR(255) NOT NULL,
    table_case INT NOT NULL DEFAULT 0,
    current_order_id INT DEFAULT NULL,
    crtime DATETIME DEFAULT CURRENT_TIMESTAMP,
    mdtime DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    isdeleted TINYINT(1) NOT NULL DEFAULT 0,
    branch VARCHAR(255) DEFAULT NULL,
    tatnet VARCHAR(255) DEFAULT NULL
)";
$conn->query($sql);

// إضافة طاولات تجريبية إذا لم تكن موجودة
$check_tables = $conn->query("SELECT COUNT(*) as count FROM tables WHERE isdeleted = 0");
if ($check_tables) {
    $tables_count = $check_tables->fetch_assoc()['count'];
    if ($tables_count == 0) {
        for ($i = 1; $i <= 12; $i++) {
            $table_name = "طاولة " . $i;
            $conn->query("INSERT INTO tables (tname, table_case) VALUES ('$table_name', 0)");
        }
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-cash-register"></i> نظام نقاط البيع
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-home"></i> الرئيسية
                    </a>
                </li>
                <li class="nav-item">
                    <a href="tables.php" class="nav-link">
                        <i class="fas fa-th-large"></i> الطاولات
                    </a>
                </li>
                <li class="nav-item">
                    <?php include('elements/pos/close_modal.php')?>
                </li>
            </ul>
            
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a href="do/do_logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<?php if(isset($_GET['edit'])){
    $id = $_GET['edit'];
    $rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();
} ?>




<div class="container-fluid mt-3">
    <div class="row">
        <!-- القسم الأيمن - معلومات الطلب -->
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-shopping-cart"></i> معلومات الطلب
                    </h6>
                    <button class="btn btn-light btn-sm" id="fullscreenBtn" title="ملء الشاشة">
                        <i class="fas fa-expand"></i>
                    </button>
                </div>
                <div class="card-body p-3">
                    <form action="do/doadd_invoice.php" method="post" id="myForm">
                        <?php include('elements/pos/right0.php') ?> 
                        <?php include('elements/pos/right1.php') ?> 
                        <?php include('elements/pos/right2.php') ?> 
                        <?php include('elements/pos/right3.php') ?> 
                    </form>
                </div>
            </div>
        </div>
        
        <!-- القسم الأيسر - الأصناف -->
        <div class="col-md-8">
            <?php include('elements/pos/left1.php') ?>
        </div>
    </div>
</div>
    <?php include('elements/pos/clientsmodal.php') ?>
    
<!-- Modal اختيار الطاولة -->
<div class="modal fade" id="tablesModal" tabindex="-1" aria-labelledby="tablesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="tablesModalLabel">
                    <i class="fas fa-chair"></i> اختر الطاولة
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="tables-grid" class="row g-3">
                    <div class="col-12 text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">جاري التحميل...</span>
                        </div>
                        <p class="text-muted mt-2">جاري تحميل الطاولات...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="selected_table_id" name="table_id">
<input type="hidden" id="current_order_id" name="order_id">

<!-- زر عائم للذهاب للطاولات -->
<a href="tables.php" class="btn btn-primary position-fixed" 
   style="bottom: 20px; right: 20px; z-index: 1000; border-radius: 50px; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.3);" 
   title="عرض الطاولات">
    <i class="fas fa-th-large fa-lg"></i>
</a>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var fullscreenButton = document.getElementById('fullscreenBtn');
    
    if (fullscreenButton) {
        fullscreenButton.addEventListener('click', function() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen();
                this.innerHTML = '<i class="fas fa-compress"></i>';
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
                this.innerHTML = '<i class="fas fa-expand"></i>';
            }
        });
    }
});
</script>


<script>
    const myModal = document.getElementById('closed')
    const myInput = document.getElementById('myInput')

    myModal.addEventListener('shown.bs.modal', () => {
  myInput.focus()
    })
</script>

<script src="js/pos.js"></script>


<?php include('includes/footer.php');?>