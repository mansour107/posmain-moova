<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>


  <div class="content-wrapper">
    <section class="content-header">
      <div class="container-fluid">

      <?php if($role['show_users'] == 1){ ?>
      
        <div class="card shadow-sm ">
            <div class="card-headerr p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0 text-dark"><i class="fas fa-users-cog me-2"></i> ادوار المستخدمين</h4>
                    <a href="add_role.php" class="btn btn-light btn-sm">
                        <i class="fas fa-plus me-1"></i> إضافة دور جديد
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-center" style="width: 80px;">#</th>
                                <th>اسم الدور</th>
                                <th>الوصف</th>
                                <th class="text-center" style="width: 150px;">العمليات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sqlshowrole = "SELECT id,rollname,info FROM usr_pwrs ORDER BY id";
                            $resshowrole = $conn->query($sqlshowrole);
                            $counter = 1;
                            while ($rawrole = $resshowrole->fetch_assoc()) { 
                            ?>
                            <tr>
                                <td class="text-center font-weight-bold text-muted"><?= $counter++ ?></td>
                                <td class="font-weight-bold"><?= htmlspecialchars($rawrole['rollname']) ?></td>
                                <td class="text-muted"><?= htmlspecialchars($rawrole['info']) ?></td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <a href="edit_role.php?id=<?= md5($rawrole['id']) ?>&no=<?= $rawrole['id'] ?>&name=<?= urlencode($rawrole['rollname']) ?>" 
                                           class="btn btn-sm" title="تعديل">
                                            <i class="fas fa-edit " style="color:rgb(255, 184, 51);"></i>
                                        </a>
                                       
                                        <a href="do/dodel_role.php?id=<?= $rawrole['id'] ?>" 
                                           class="btn btn-sm" 
                                           onclick="return confirm('هل أنت متأكد من حذف هذا الدور؟')" 
                                           title="حذف">
                                            <i class="fas fa-trash" style="color: rgb(173, 71, 71);"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>





      <?php }else{echo $userErrorMassage;} ?>
      </div>
    </section>
  </div>
<?php include('includes/footer.php') ?>
