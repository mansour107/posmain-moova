<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php
$id = $_GET['id'];
$sql = "SELECT * FROM hr_operations WHERE id = $id";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
?>

<div class="content-wrapper">
  <!-- Content Header -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><?= $lang_edit_operation ?></h1>
        </div>
      </div>
    </div>
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <!-- Left Column: Edit Operation Details -->
        <div class="col-md-6">
          <div class="card card-primary">
            <div class="card-header">
              <h3 class="card-title"><?= $lang_edit_operation ?></h3>
            </div>
            <form role="form" action="DO/doedit_hr_operation.php" method="post">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <div class="card-body">
                <div class="form-group">
                  <label><?= $lang_operation_name ?></label>
                  <input name="name" type="text" class="form-control" value="<?= $row['name'] ?>" required>
                </div>
                <div class="form-group">
                  <label><?= $lang_parent_operation ?></label>
                  <select name="parent_id" class="form-control select2">
                      <option value=""><?= $lang_parent_operation ?></option>
                      <?php
                        $ops_sql = "SELECT * FROM hr_operations WHERE id != $id"; // Prevent selecting self as parent
                        $ops_res = $conn->query($ops_sql);
                        while($op = $ops_res->fetch_assoc()){
                            $selected = ($op['id'] == $row['parent_id']) ? 'selected' : '';
                            echo "<option value='".$op['id']."' $selected>".$op['name']."</option>";
                        }
                      ?>
                  </select>
                </div>
                <div class="form-group">
                    <label><?= $lang_description ?></label>
                    <textarea class="form-control" name="description" rows="3"><?= $row['description'] ?></textarea>
                </div>
              </div>
              <div class="card-footer">
                <button type="submit" class="btn btn-primary"><?= $lang_save ?></button>
              </div>
            </form>
          </div>
        </div>

        <!-- Right Column: Operation Steps -->
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title"><?= $lang_operation_steps ?></h3>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th style="width: 10px">#</th>
                                <th><?= $lang_step_description ?></th>
                                <th style="width: 40px"><?= $lang_delete ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $steps_sql = "SELECT * FROM hr_operation_steps WHERE operation_id = $id ORDER BY step_order ASC";
                            $steps_res = $conn->query($steps_sql);
                            while($step = $steps_res->fetch_assoc()){
                            ?>
                            <tr>
                                <td><?= $step['step_order'] ?></td>
                                <td><?= $step['description'] ?></td>
                                <td>
                                    <a href="DO/dodel_operation_step.php?id=<?= $step['id'] ?>&op_id=<?= $id ?>" class="btn btn-danger btn-sm" onclick="return confirm('<?= $lang_confirm ?>')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Add Step Form -->
                <div class="card-footer">
                    <form action="DO/doadd_operation_step.php" method="POST">
                        <input type="hidden" name="operation_id" value="<?= $id ?>">
                        <div class="input-group">
                            <input type="text" name="description" class="form-control" placeholder="<?= $lang_step_description ?>" required>
                            <input type="number" name="step_order" class="form-control" placeholder="<?= $lang_step_order ?>" style="max-width: 80px;" value="1">
                            <span class="input-group-append">
                                <button type="submit" class="btn btn-info"><?= $lang_add ?></button>
                            </span>
                        </div>
                    </form>
                </div>

            </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>
