<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>

<style>
.content-wrapper {
    background: #f8f9fa;
}

.card {
    border: none;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border-radius: 8px;
    margin-bottom: 20px;
}

.card-header {
    background: #fff;
    border-bottom: 1px solid #e9ecef;
    padding: 1rem 1.25rem;
}

.card-header h3, .card-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
}

.profile-user-img {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border: 3px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.profile-username {
    font-size: 1.25rem;
    font-weight: 600;
    color: #2c3e50;
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #f1f3f5;
    padding: 0.875rem 1.25rem;
    background: transparent;
}

.list-group-item:last-child {
    border-bottom: none;
}

.list-group-item b {
    color: #6c757d;
    font-weight: 500;
}

.nav-pills .nav-link {
    color: #6c757d;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    margin: 0 0.25rem;
    font-weight: 500;
}

.nav-pills .nav-link.active {
    background: #007bff;
    color: #fff;
}

.table {
    margin-bottom: 0;
}

.table thead th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #495057;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 0.875rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: #f8f9fa;
}

#totalSum {
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 0.375rem 0.5rem;
    text-align: center;
    width: 70px;
    font-weight: 600;
    background: #f8f9fa;
}

.btn {
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-weight: 500;
}

.btn-warning {
    background: #ffc107;
    border-color: #ffc107;
    color: #000;
}

.btn-warning:hover {
    background: #e0a800;
    border-color: #d39e00;
}

.alert {
    border-radius: 6px;
    border: none;
}

.text-muted {
    color: #6c757d !important;
}

strong {
    color: #495057;
    font-weight: 600;
}

hr {
    border-top: 1px solid #e9ecef;
}
</style>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">
        
      <div class="bg-danger">
            <?php 
$id = $_GET['id'];

$sqlemp = "SELECT * FROM `employees`  where id = '$id' ";
$resemp = $conn->query($sqlemp);
$rowemp = $resemp->fetch_assoc();
if (!isset($rowemp['id'])) {
  ?>
<h2>لقد دخلت هذه الصفحه من مكان غير المكان المخصص .. من فضلك عدم التلاعب بالعنوان..ارجع الي 
  
<a href="dashboard.php" class="btn btn-success"><h2>الرئيسية</h2></a></h2>
<?php die; } ?>
</div>
        <div class="row mb-2">
          <div class="col-sm-6">

          </div>
          <div class="col-sm-6">
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="dashboard.php"><?= $lang_main ?></a></li>
              <li class="breadcrumb-item active"><a href="employees.php"></a></li>
            </ol>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-md-3">

            <!-- Profile Image -->
            <div class="card">
              <div class="card-body text-center">
                <img onerror="this.src='assets/alt/altemprofile.png';" class="profile-user-img img-fluid img-circle mb-3"
                     src="assets/<?= $rowemp['imgs']?>"
                     alt="User profile picture">

                <h3 class="profile-username"><?= $rowemp['name'] ?></h3>
                <p class="text-muted small"><?= $rowemp['info'] ?></p>

                <ul class="list-group list-group-flush mt-3">
                  <li class="list-group-item d-flex justify-content-between">
                    <b>الوظيفه</b>
                    <span><?php
                    $jopid = $rowemp['jop'];
                    $rowjop = $conn->query("SELECT * FROM `jops` where id = $jopid ")->fetch_assoc();
                     echo $rowjop['name'] ?></span>
                  </li>
                  <li class="list-group-item d-flex justify-content-between">
                    <b>الادراه</b>
                    <span><?php
                    $dprtid = $rowemp['department'];
                    $rowdprt = $conn->query("SELECT * FROM `departments` where id = $dprtid ")->fetch_assoc();
                     echo $rowdprt['name'] ?></span>
                  </li>
                  <li class="list-group-item d-flex justify-content-between">
                    <b>المرتب</b>
                    <span><?= number_format($rowemp['salary']) ?></span>
                  </li>
                  <li class="list-group-item d-flex justify-content-between">
                    <b>التقييم العام</b>
                    <input type="text" id="totalSum" readonly>
                  </li>
                </ul>

                <a href="edit_employee.php?id=<?= $id?>" class="btn btn-warning btn-block mt-3">تعديل البيانات</a>
              </div>
            </div>
            <!-- /.card -->

            <!-- About Me Box -->
            <div class="card">
              <div class="card-header">
                <h3>نبذة عني</h3>
              </div>
              <div class="card-body">
                <strong>التعليم</strong>
                <p class="text-muted mb-3"><?= $rowemp['education']?></p>

                <strong>الموقع</strong>
                <p class="text-muted mb-3"><?= $rowemp['town']?>, <?= $rowemp['address']?></p>

                <strong>المهارات</strong>
                <p class="text-muted mb-3"><?= $rowemp['skills'] ?></p>

                <strong>معلومات</strong>
                <p class="text-muted mb-0"><?= $rowemp['info'] ?></p>
              </div>
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
          <div class="col-md-9">
            <div class="card">
              <div class="card-header p-2">
                <ul class="nav nav-pills">
                  <li class="nav-item"><a class="nav-link active" href="#activity" data-toggle="tab"><?= $lang_emprofilemainentry ?></a></li>
                  <li class="nav-item"><a class="nav-link" href="#emprofilejop" data-toggle="tab"><?= $lang_emprofilejopentry ?></a></li>
                  <li class="nav-item"><a class="nav-link" href="#timeline" data-toggle="tab">التقييم (KBI)</a></li>
                </ul>
              </div><!-- /.card-header -->
              <div class="card-body">
                <div class="tab-content">

                  <div class="active tab-pane" id="activity">
                    <div class="card">
                      <div class="card-header">
                        <h5><?=$lang_addemployee_personalinfo?></h5>
                      </div>
                      <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_publicname?></b>
                             <span><?= $rowemp['name'] ?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_email?></b>
                             <span><?= $rowemp['email']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_phone?></b>
                             <span><?= $rowemp['number']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_dateofbirth?></b>
                             <span><?= $rowemp['dateofbirth']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_gender?></b>
                             <span><?php echo $rowemp['gender'] == 0 ? 'ذكر' : 'انثي'; ?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_info?></b>
                             <span><?= $rowemp['info']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_address1?></b>
                             <span><?= $rowemp['address']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_address2?></b>
                             <span><?= $rowemp['address2']?></span>
                         </li>
                         <li class="list-group-item d-flex justify-content-between">
                             <b><?=$lang_addemployee_country?></b>
                             <span><?php  $twnid = $rowemp['town'];
                             $rowtwn = $conn->query("SELECT * FROM towns where id = '$twnid'")->fetch_assoc();
                             echo $rowtwn['name'];
                             ?></span>
                         </li>
                       </ul>
                      </div>
                    </div>
                    <!-- /.post -->

                    <!-- Post -->
                    <div class="post">
                      <!-- /.user-block -->
                      <div class="row mb-3">
                        <div class="col-sm-6">
                          
                        </div>
                        <!-- /.col -->
                        <div class="col-sm-6">
                          <div class="row">
                            <!-- /.col -->
                          </div>
                          <!-- /.row -->
                        </div>
                        <!-- /.col -->
                      </div>
                      <!-- /.row -->

                      <p>
                        
                        <span class="float-right">
                         
                        </span>
                      </p>

                    </div>
                    <!-- /.post -->
                  </div>
                  <div class=" tab-pane" id="emprofilejop">
                    <div class="card">
                      <div class="card-header">
                        <h5><?=$lang_emprofilejop?></h5>
                      </div>
                      <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_job?></b>
                              <span><?php $jopid = $rowemp['jop'];
                              $resjop = $conn->query("SELECT name from jops where id = '$jopid'");
                              $rowjop = $resjop?->fetch_assoc();
                              echo $rowjop ? $rowjop['name'] : 'N/A'; ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_jobdepart?></b>
                              <span><?php $dprtid = $rowemp['department'];
                              $resdprt = $conn->query("SELECT name from departments where id = '$dprtid'");
                              $rowdprt = $resdprt?->fetch_assoc();
                              echo $rowdprt ? $rowdprt['name'] : 'N/A'; ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_jobtype?></b>
                              <span><?php $tybid = $rowemp['joptybe'];
                              $restyb = $conn->query("SELECT name from joptybes where id = '$tybid'");
                              $rowtyb = $restyb?->fetch_assoc();
                              echo $rowtyb ? $rowtyb['name'] : 'N/A'; ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_jobstart?></b>
                              <span><?= $rowemp['dateofhire'] ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_jobend?></b>
                              <span><?= $rowemp['dateofend'] ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_salary?></b>
                              <span><?= number_format($rowemp['salary']) ?></span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between">
                              <b><?=$lang_addemployee_shift?></b>
                              <span><?php $shftid = $rowemp['shift'];
                              $rowshft = $conn->query("SELECT name from shifts where id = '$shftid'")->fetch_assoc();
                              echo $rowshft['name'] ?></span>
                          </li>
                        </ul>
                      </div>
                    </div>
                    <!-- /.post -->

                    <!-- Post -->
                    <div class="post">
                      <!-- /.user-block -->
                      <div class="row mb-3">
                        <div class="col-sm-6">
                        </div>
                        <!-- /.col -->
                        <div class="col-sm-6">
                          <div class="row">
                            <!-- /.col -->
                          </div>
                          <!-- /.row -->
                        </div>
                        <!-- /.col -->
                      </div>
                      <!-- /.row -->
                      <p>
                        <span class="float-right">
                        </span>
                      </p>
                    </div>
                    <!-- /.post -->
                  </div>

                  <!-- /.tab-pane -->
                  <div class="tab-pane" id="timeline">
                    <div class="card">
                      <div class="card-body">
                        <form id="kbiForm" action="" method="post">
                          <div class="table-responsive">
                            <table id="mytable" class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>المعدل</th>
                                                <th>الوزن</th>
                                                <th>التقييم</th>
                                                <th>القيمة</th>
                                                <th></th>
                                            </tr>
                                            </thead>

                                            <tbody>
                                                
                                                <?php 
                                                $resemkbi = $conn->query("SELECT * FROM `emp_kbis`  where emp_id = '$id'");
                                                while ($rowemkbi = $resemkbi->fetch_assoc()) {
                                                ?>
                                                <tr>
                                                    <th>
                                                      <?php
                                                      $kbi = $rowemkbi['kbi_id'];
                                                      $rowkname = $conn->query("SELECT * FROM kbis where id = $kbi")->fetch_assoc();?>
                                                    <p title="<?= $rowkname['info']?>"><?= $rowkname['kname']?></p>  
                                                    </th>
                                                    <th><input type="text" hidden value="<?= $rowemkbi['id']?>" name="kbi_id[]">
                                                      <input type="text" id="kbi_weight" class="form-control decimalInput" placeholder="" pattern="^\d+(\.\d{0,2})?$" title="exm: 0.15" name="kbi_weight[]"  required value="<?= $rowemkbi['kbi_weight']?>"></th>

                                                    <th><input type="text" id="kbi_rate" class="form-control decimalInput" placeholder="" pattern="^\d+(\.\d{0,2})?$" title="exm: 0.15" name="kbi_rate[]" required value="<?= $rowemkbi['kbi_rate']?>"></th>

                                                    <th><input readonly type="text" id="kbi_sum" class="form-control decimalInput" placeholder="" pattern="^\d+(\.\d{0,2})?$" title="exm: 0.15" name="kbi_sum[]" required value="<?= $rowemkbi['kbi_sum']?>"></th>
                                                    </th>
                                                </tr>
                                                
                                                <?php } ?>
                                            </tbody>
                                            <tfoot>
                                              <tr>
                                                <th>المعدل</th>
                                                <th>الوزن <p id="total_weight"></p></th>
                                                <th>التقييم</th>
                                                <th>القيمة</th>
                                                <th></th>
                                                
                                                </tr>
                                            </tfoot>
                                            </form>

                            </table>
                          </div>
                          <div class="mt-3">
                            <button type="submit" class="btn btn-success">حفظ التعديلات</button>
                          </div>
                        </form>
                        <div id="successMessage" style="display:none;" class="alert alert-success mt-3"></div>
                        <div id="errorMessage" style="display:none;" class="alert alert-danger mt-3"></div>
                      </div>
                    </div>
                  </div>
                    

                  <!-- /.tab-pane -->
                </div>
                <!-- /.tab-content -->
              </div><!-- /.card-body -->
            </div>
            <!-- /.nav-tabs-custom -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
  </div>

  <script>
document.addEventListener('DOMContentLoaded', function() {
    // Function to update the sum for each row
    function updateRowSum(row) {
        const kbiWeight = parseFloat(row.querySelector('[id^="kbi_weight"]').value) || 0;
        const kbiRate = parseFloat(row.querySelector('[id^="kbi_rate"]').value) || 0;
        const kbiSum = row.querySelector('[id^="kbi_sum"]');
        const sum = kbiWeight * (kbiRate / 100);
        kbiSum.value = sum.toFixed(2);
    }

    // Function to update the total sum
    function updateTotalSum() {
        let total = 0;
        document.querySelectorAll('input[name^="kbi_sum"]').forEach(function(input) {
            total += parseFloat(input.value) || 0;
        });
        document.getElementById('totalSum').value = total.toFixed(2);
    }
    



    document.querySelectorAll('.decimalInput').forEach(function(input) {
        input.addEventListener('input', function() {
            const row = this.closest('tr');
            updateRowSum(row);
            updateTotalSum();
        });
    });

    updateTotalSum();

    document.getElementById('kbiForm').addEventListener('submit', function(e) {
        e.preventDefault();
        console.log('Form submitted');
        fetch('js/ajax/update_kbi.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(this))
        })
        .then(response => response.text())
        .then(data => {
            console.log('AJAX request successful. Response:', data);
            document.getElementById('successMessage').textContent = "تم التعديل بنجاح";
            document.getElementById('successMessage').style.display = 'block';
            setTimeout(() => {
                document.getElementById('successMessage').style.display = 'none';
            }, 2000);
            updateTotalSum();
        })
        .catch(error => {
            console.error('AJAX request failed:', error);
            document.getElementById('errorMessage').textContent = 'تأكد من البيانات و الاتصال';
            document.getElementById('errorMessage').style.display = 'block';
            setTimeout(() => {
                document.getElementById('errorMessage').style.display = 'none';
            }, 2000);
        });
    });
});
</script>
<script>
  $(document).ready(function() {
    function updateTotal() {
        var sum = $('[name="kbi_weight[]"]').get().reduce((s, el) => s + (parseFloat($(el).val()) || 0), 0);
        $('#total_weight').text(sum.toFixed(2));
    }
    $('[name="kbi_weight[]"]').on('input', updateTotal);
    updateTotal();
});
</script>

<?php include('includes/footer.php') ?>