<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><?= $lang_employee_operations ?></h1>
        </div>
      </div>
    </div>
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      
      <!-- Assignment Form -->
      <div class="card card-default">
          <div class="card-header">
              <h3 class="card-title"><?= $lang_assign_operation ?></h3>
              <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
              </div>
          </div>
          <form action="DO/doassign_operation.php" method="POST">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label><?= $lang_sideemployees ?></label>
                            <select class="form-control select2" name="employee_id" required>
                                <option value=""><?= $lang_sideemployees ?></option>
                                <?php
                                $emp_sql = "SELECT id, name FROM employees WHERE isdeleted != 1 ORDER BY name ASC";
                                $emp_res = $conn->query($emp_sql);
                                while($emp = $emp_res->fetch_assoc()){
                                    echo "<option value='".$emp['id']."'>".$emp['name']."</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label><?= $lang_operations ?></label>
                            <select class="form-control select2" name="operation_id" required>
                                <option value=""><?= $lang_operations ?></option>
                                <?php
                                $op_sql = "SELECT id, name FROM hr_operations ORDER BY name ASC";
                                $op_res = $conn->query($op_sql);
                                while($op = $op_res->fetch_assoc()){
                                    echo "<option value='".$op['id']."'>".$op['name']."</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block"><?= $lang_add ?></button>
                    </div>
                </div>
            </div>
          </form>
      </div>

      <!-- Assignments List -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><?= $lang_employee_operations ?></h3>
        </div>
        <div class="card-body">
          <table id="example1" class="table table-bordered table-striped">
            <thead>
              <tr>
                <th>#</th>
                <th><?= $lang_name ?></th>
                <th><?= $lang_operation_name ?></th>
                <th><?= $lang_status ?></th>
                <th><?= $lang_date ?></th>
                <th><?= $lang_publicoperations ?></th>
              </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT eo.*, e.name as emp_name, o.name as op_name 
                        FROM employee_operations eo 
                        JOIN employees e ON eo.employee_id = e.id 
                        JOIN hr_operations o ON eo.operation_id = o.id 
                        ORDER BY eo.id DESC";
                $res = $conn->query($sql);
                $x = 0;
                while($row = $res->fetch_assoc()){
                    $x++;
                    $status_badge = ($row['status'] == 'completed') ? 'badge-success' : 'badge-warning';
                ?>
                <tr>
                    <td><?= $x ?></td>
                    <td><?= $row['emp_name'] ?></td>
                    <td><?= $row['op_name'] ?></td>
                    <td><span class="badge <?= $status_badge ?>"><?= $row['status'] ?></span></td>
                    <td><?= $row['assigned_at'] ?></td>
                    <td>
                        <?php if($row['status'] != 'completed'): ?>
                        <a href="DO/doupdate_operation_status.php?id=<?= $row['id'] ?>&status=completed" class="btn btn-success btn-xs">
                           <i class="fas fa-check"></i> Complete
                        </a>
                        <?php endif; ?>
                        
                        <a href="DO/dodel_employee_operation.php?id=<?= $row['id'] ?>" class="btn btn-danger btn-xs" onclick="return confirm('<?= $lang_confirm ?>')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
          </table>
        </div>
      </div>
      
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>
