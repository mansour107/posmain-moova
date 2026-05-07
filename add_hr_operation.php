<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1><?= $lang_add_operation ?></h1>
        </div>
      </div>
    </div>
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <form role="form" action="DO/doadd_hr_operation.php" method="post">
      <div class="row">
        <!-- Left Column: Add Operation Form -->
        <div class="col-md-6">
          <div class="card card-primary">
            <div class="card-header">
              <h3 class="card-title"><?= $lang_add_operation ?></h3>
            </div>
            
              <div class="card-body">
                
                <div class="form-group">
                  <label><?= $lang_operation_name ?></label>
                  <input name="name" type="text" class="form-control" required>
                </div>

                <div class="form-group">
                  <label><?= $lang_parent_operation ?></label>
                  <select name="parent_id" class="form-control select2">
                      <option value=""><?= $lang_parent_operation ?></option>
                      <?php
                        $sql = "SELECT * FROM hr_operations";
                        $res = $conn->query($sql);
                        while($row = $res->fetch_assoc()){
                            echo "<option value='".$row['id']."'>".$row['name']."</option>";
                        }
                      ?>
                  </select>
                </div>

                <div class="form-group">
                  <label><?= $lang_description ?></label>
                  <textarea class="form-control" name="description" rows="3"></textarea>
                </div>

              </div>
              
              <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-block"><?= $lang_save ?></button>
              </div>
            
          </div>
        </div>

        <!-- Right Column: Dynamic Steps -->
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title"><?= $lang_operation_steps ?></h3>
                </div>
                <div class="card-body">
                    <table class="table table-bordered" id="stepsTable">
                        <thead>
                            <tr>
                                <th style="width: 10px">#</th>
                                <th><?= $lang_step_description ?></th>
                                <th style="width: 40px"><?= $lang_delete ?></th>
                            </tr>
                        </thead>
                        <tbody id="stepsContainer">
                            <!-- Steps will be added here -->
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                     <div class="input-group">
                        <input type="text" id="newStepDesc" class="form-control" placeholder="<?= $lang_step_description ?>">
                        <input type="number" id="newStepOrder" class="form-control" value="1" style="max-width: 80px;" placeholder="#">
                        <span class="input-group-append">
                            <button type="button" class="btn btn-info" id="addStepBtn"><?= $lang_add ?></button>
                        </span>
                    </div>
                </div>
            </div>
        </div>

      </div>
      </form>
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>

<script>
$(document).ready(function() {
    let stepCount = 0;

    $('#addStepBtn').click(function() {
        let desc = $('#newStepDesc').val();
        let order = $('#newStepOrder').val();

        if(desc.trim() !== '') {
            stepCount++;
            let row = `
                <tr id="row_${stepCount}">
                    <td>
                        ${order}
                        <input type="hidden" name="steps_order[]" value="${order}">
                    </td>
                    <td>
                        ${desc}
                        <input type="hidden" name="steps_desc[]" value="${desc}">
                    </td>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeStep(${stepCount})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            $('#stepsContainer').append(row);
            
            // Reset inputs
            $('#newStepDesc').val('');
            $('#newStepOrder').val(parseInt(order) + 1);
        }
    });

    window.removeStep = function(id) {
        $('#row_' + id).remove();
    }
});
</script>
