<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h2>معالجة البصمة لموظف واحد</h2>
            </div>
            <div class="card-body">
                <form action="do/doadd_calcsalary.php" method="post">
                <div class="row">
                    <div class="col">
                        
            <div class="form-group">
                <label for="">اسم الموظف</label>
                <select required class="form-control" name="employee" id="">
                    <?php
                    $resemp = $conn->query("SELECT * FROM `employees` WHERE `isdeleted` != 1 OR `isdeleted` IS NULL;
                    ");
                    while ($rowemp = $resemp->fetch_assoc()) { ?>
                    <option value="<?= $rowemp['id'] ?>"> <?= $rowemp['name'] ?></option>
                   <?php } ?>
                </select>
            </div>
                    </div>
                    <div class="col">
                        
            <div class="form-group">
                <label for="">من</label>
                <input required class="form-control" type="date" name="startdate" id="">
            </div>
                    </div>
                    <div class="col">
                              
            <div class="form-group">
                <label for="">الي</label>
                <input required class="form-control" type="date" name="enddate" id="">
            </div>

                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">معالجة</button>
            </div>
            </form>
        </div>



        <div class="card">
            <div class="card-header"></div>
            <div class="card-body">
                <form action="do/doadd_calcgroup.php" method="post">
                <div class="row">
                    <div class="col">
                        
            <div class="form-group">
                <label for="">الادارة</label>
                <select required class="form-control" name="department" id="">
                    <?php
                    $resdprt = $conn->query("SELECT * FROM `departments` WHERE `isdeleted` != 1 OR `isdeleted` IS NULL;
                    ");
                    while ($rowdprt = $resdprt->fetch_assoc()) { ?>
                    <option value="<?= $rowdprt['id'] ?>"> <?= $rowdprt['name'] ?></option>
                   <?php } ?>
                </select>
            </div>
                    </div>
                    <div class="col">
                        
            <div class="form-group">
                <label for="">من</label>
                <input required class="form-control" type="date" name="startdate" id="">
            </div>
                    </div>
                    <div class="col">
                              
            <div class="form-group">
                <label for="">الي</label>
                <input required class="form-control" type="date" name="enddate" id="">
            </div>

                    </div>
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">معالجة</button>
            </div>
            </form>
        </div>



<!-- /.col -->
</div>
<!-- /.row -->
</section>
<!-- /.content -->
</div>


<?php include('includes/footer.php') ?>