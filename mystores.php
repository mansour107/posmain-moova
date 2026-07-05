<?php
require_once __DIR__ . '/includes/auth_guard.php';
include __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/page_guard.php';
page_guard('inventory.edit', $conn);
?>
<?php require_once __DIR__ . '/includes/pos_operational_store.php';
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
$mystoresSingleStore = posmain_single_store_mode_enabled();
?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">


      <div class="card card-light">

      <div class="card-header">
        <div class="row">
        <div class="col-lg-4"><h3>ادارة المخازن</h3></div>
        <div class="col-lg-4"></div>
        <div class="col-lg-4 text-right">
           <?php if (!$mystoresSingleStore): ?>
           <a href="add_store.php" id="addNewElement"><p class="btn btn-large btn-dark"  >جديد(f3)</p></a>
           <?php endif; ?>
        </div>
        </div>
      </div>


      <div class="card-body">
        <div class="table">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>عمليات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>1</td>
                        <td>
                            <a href="" class="btn btn-warning">تعديل</a>
                            <a href="" class="btn btn-danger">حذف</a>
                        </td>
                    </tr>
                </tbody>
            </table>

        </div>
      </div>



      </div>





</div>
</section>
</div>
<?php include('includes/footer.php'); ?>