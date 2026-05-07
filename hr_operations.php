<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><?= $lang_operations_list ?></h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="index.php"><?= $lang_dashboard ?></a></li>
            <li class="breadcrumb-item active"><?= $lang_operations ?></li>
          </ol>
        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="row">
      <div class="col-12">
        <div class="card">
            
          <div class="card-header">
            <h3 class="card-title"><a href="add_hr_operation.php" class="btn btn-large btn-primary"><?= $lang_add_operation ?> </a></h3>
          </div>
          
          <!-- /.card-header -->
          <div class="card-body">
            <table id="example2" class="table table-bordered table-hover">
              <thead>
                <tr>
                  <th><?= $lang_seq ?></th>
                  <th><?= $lang_operation_name ?></th>
                  <th><?= $lang_parent_operation ?></th>
                  <th><?= $lang_description ?></th>
                  <th><?= $lang_publicoperations ?></th>
                </tr>
              </thead>
              <tbody>
              <?php
              // Fetch operations with their parent names and count of children
              $sql = "SELECT t1.*, t2.name as parent_name, 
                      (SELECT COUNT(*) FROM hr_operations WHERE parent_id = t1.id) as children_count
                      FROM hr_operations t1 
                      LEFT JOIN hr_operations t2 ON t1.parent_id = t2.id 
                      ORDER BY t1.parent_id ASC, t1.id ASC";
              
              $res = $conn->query($sql);
              $x = 0;
              while ($row = $res->fetch_assoc()) {
                $x++;
                $parent_display = $row['parent_name'] ? $row['parent_name'] : '-';
                $delete_msg = ($row['children_count'] > 0) ? $lang_delete_parent_warning : ($lang_confirm . " " . $lang_delete . "?");
                $modal_class = ($row['children_count'] > 0) ? "bg-danger" : "bg-warning"; // Red for high risk
              ?>
                  <tr>
                    <td><?php echo $x ?></td>
                    <td><strong><?= $row['name'] ?></strong></td>
                    <td><?= $parent_display ?></td>
                    <td><?= $row['description'] ?></td>
                    <td>
                        <a class="btn btn-info btn-sm" href="edit_hr_operation.php?id=<?= $row['id'] ?>">
                            <i class="fas fa-pencil-alt"></i> <?= $lang_edit ?>
                        </a>
                        
                        <a href="#" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#modal-danger<?= $row['id'] ?>">
                            <i class="fas fa-trash"></i> <?= $lang_delete ?>
                        </a>

                      <div class="modal fade" id="modal-danger<?= $row['id'] ?>">
                        <div class="modal-dialog">
                          <div class="modal-content <?= $modal_class ?>">
                            <div class="modal-header">
                              <h4 class="modal-title"><?= $lang_warning ?></h4>
                              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                              </button>
                            </div>
                            <form action="DO/dodel_hr_operation.php?id=<?= $row['id'] ?>" method="POST">
                            <div class="modal-body">
                                   <p><?= $delete_msg ?></p>
                                  </div>
                                  <div class="modal-footer justify-content-between">
                                   <button type="button" class="btn btn-outline-light" data-dismiss="modal"><?= $lang_cancel ?></button>
                                     <button type="submit" class="btn btn-outline-light"><?= $lang_delete ?></button>
                               </div>
                            </form>
                          </div>
                        </div>
                      </div>

                    </td>
                  </tr>
                <?php } ?>
                </tbody>
            </table>
          </div>
          <!-- /.card-body -->
        </div>
        <!-- /.card -->
      </div>
      <!-- /.card-body -->
    </div>
    <!-- /.card -->
</div>
<!-- /.col -->
</div>
<!-- /.row -->
</section>
<!-- /.content -->
</div>

<?php include('includes/footer.php') ?>
