<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php

$id = $_GET['id'];
$sqlemp = "SELECT * FROM `employees` WHERE `id` = '$id'";
$rowemp = $conn->query($sqlemp)->fetch_assoc();

?>

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

.card-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
}

.form-control, .custom-select {
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.form-control:focus, .custom-select:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.btn {
    border-radius: 6px;
    font-weight: 500;
}

#editbtn {
    float: left;
    padding: 0.375rem 0.75rem;
}

label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}
</style>

<form class='validate_form' id="validate_form" action="do/doedit_employee.php?id=<?= $id ?>" method="post" enctype='multipart/form-data'>
    <div class="content-wrapper">
        <!-- Content Header (Page header) -->
        <section class="content-header">
            <div class="container-fluid">


<div class="row">
                <div class="col col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3><?= $lang_addemployee_personalinfo ?></h3>
                        <button type="button" id="editbtn" class="btn btn-primary btn-sm">
                            <i class="fa fa-pen"></i> تعديل
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- FIRST COLUMN -->
                            <div class="col">
                                <div class="form-group">
                                    <label for="name"><?= $lang_addemployee_name ?></label>
                                    <input type="text" data-parsley-trigger="keyup" value="<?= $rowemp['name'] ?>" class="form-control" autofocus id="name" name="name" placeholder="<?= $lang_pbholder_name ?>">
                                </div>
                                <div class="form-group">
                                    <label for="phone"><?= $lang_addemployee_phone ?></label>
                                    <input type="text" data-parsley-type="digits" data-parsley-trigger="keyup" value="<?= $rowemp['number'] ?>" class="form-control" name="number" id="phone" placeholder="<?= $lang_pbholder_phone ?>">
                                </div>
                            </div>

                            
                            <!-- SECOND COLUMN -->
                            <div class="col">
                                <div class="form-group">
                                    <label for="email"><?= $lang_addemployee_email ?></label>
                                    <input type="email" data-parsley-type="email" data-parsley-trigger="keyup" value="<?= $rowemp['email'] ?>" class="form-control" name="email" id="email" placeholder="<?= $lang_pbholder_email ?>">
                                </div>
                                <div class="form-group">
                                    <label for="exampleInputFile"><?= $lang_addemployee_image ?></label>
                                    <div class="input-group">
                                        <div class="custom-file">
                                            <input name="imgs" value="" type="file" class="custom-file-input" id="exampleInputFile">
                                            <label class="custom-file-label" for="exampleInputFile"><?= $lang_pbholder_file ?></label>
                                        </div>
                                        <div class="input-group-append">
                                            <span class="input-group-text" id=""><?= $lang_addemployee_upload ?></span>
                                        </div>
                                    </div>
                                </div>


                            </div>



                        </div>
                        <div class="row">
                            <div class="col">
                                <div class="form-group">
                                    <label for="date"><?= $lang_addemployee_dateofbirth ?></label>
                                    <input type="date" data-parsley-trigger="keyup" value="<?= $rowemp['dateofbirth'] ?>" class="form-control" name="dateofbirth" id="date" placeholder="">
                                </div>
                            </div>
                            <div class="col">
                                <div class="form-group">
                                    <label><?= $lang_addemployee_gender ?></label>
                                    <select name="gender" class="custom-select">
                                        <option <?php if ($rowemp['gender'] == 0) {
                                                    echo "selected";
                                                } ?> value="0"> <?= $lang_addemployee_male ?> </option>
                                        <option <?php if ($rowemp['gender'] == 1) {
                                                    echo "selected";
                                                } ?> value="1"> <?= $lang_addemployee_female ?> </option>
                                    </select>
                                </div>

                            </div>
                        </div>
                        <div class="form-group">
                            <label for="info"><?= $lang_addemployee_info ?></label>
                            <textarea name="info" class="form-control" data-parsley-trigger="keyup" rows="4" id="info"><?= $rowemp['info'] ?></textarea>
                        </div>
                        <div class="form-group">
                            <div class="form-check">
                                <input name="active" data-parsley-trigger="keyup" value="<?= $rowemp['active'] ?>" class="form-check-input" type="checkbox">
                                <label class="form-check-label"><?= $lang_addemployee_active ?></label>
                            </div>

                        </div>
                    </div>
                    </div>
                    </div>




                <div class="col col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3><?= $lang_addemployee_details ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="name"><?= $lang_addemployee_address1 ?></label>
                            <input type="text" data-parsley-trigger="keyup" value="<?= $rowemp['address'] ?>" class="form-control" id="name" name="address2" placeholder="<?= $lang_pbholder_address ?>">
                        </div>

                        <div class="form-group">
                            <label for="address_1"><?= $lang_addemployee_address2 ?></label>
                            <input type="text" data-parsley-trigger="keyup" value="<?= $rowemp['address2'] ?>" class="form-control" id="address" name="address" placeholder="<?= $lang_pbholder_address ?>">
                        </div>

                    </div>

                    <!-- SECOND COLUMN -->
                    <div class="col">
                        <div class="form-group">
                            <label><?= $lang_addemployee_country ?></label>

                            <select name="town" class="custom-select">
                                <?php
                                $sqltwn = "select * from towns order by name";
                                $restwn = $conn->query($sqltwn);
                                while ($rowtwn = $restwn->fetch_assoc()) { ?>
                                    <option <?php if ($rowtwn['id'] == $rowemp['town']) {
                                                echo "selected";
                                            } ?> value='<?= $rowtwn['id'] ?>'><?= $rowtwn['name'] ?></option>
                                <?php } ?>


                            </select>
                        </div>
                    </div>
                </div>
                </div>
 
 
 
 
 
                <div class="col col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3><?= $lang_addemployee_jobinfo ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col">
                                <div class="form-group">
                                    <label><?= $lang_addemployee_job ?></label>
                                    <select name='jop' class="custom-select">
                                        <?php
                                        $sqljop = "select * from jops order by name";
                                        $resjop = $conn->query($sqljop);
                                        while ($rowjop = $resjop->fetch_assoc()) { ?>
                                            <option value="<?= $rowjop['id'] ?>" <?php if ($rowjop['id'] == $rowemp['jop']) echo "selected"; ?>><?= $rowjop['name'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label><?= $lang_addemployee_jobdepart ?></label>
                                    <select name='department' class="custom-select">
                                        <?php
                                        $sqldprt = "select * from departments order by name";
                                        $resdprt = $conn->query($sqldprt);
                                        while ($rowdprt = $resdprt->fetch_assoc()) { ?>
                                            <option <?php if ($rowdprt['id'] == $rowemp['department']) echo "selected"; ?>   value='<?= $rowdprt['id'] ?><'><?= $rowdprt['name'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col">
                                <div class="form-group">
                                    <label><?= $lang_addemployee_joplevel ?></label>
                                    <select name='joplevel' class="custom-select">
                                        <?php
                                        $sqljplvl = "select * from joplevels order by name";
                                        $resjplvl = $conn->query($sqljplvl);
                                        while ($rowjplvl = $resjplvl->fetch_assoc()) { ?>
                                            <option <?php if ($rowjplvl['id'] == $rowemp['joplevel']) echo "selected"; ?>> <?= $rowjplvl['name'] ?></option>
                                        <?php } ?>

                                    </select>
                                </div>

                                <div class="form-group">
                                    <label><?= $lang_addemployee_jobtype ?> </label>
                                    <select name='joptybe' class="custom-select">
                                        <?php
                                        $sqltybe = "select * from joptybes order by name";
                                        $restybe = $conn->query($sqltybe);
                                        while ($rowtybe = $restybe->fetch_assoc()) { ?>
                                            <option <?php if ($rowtybe['id'] == $rowemp['joptybe']) echo "selected"; ?> value='<?= $rowtybe['id'] ?>'><?= $rowtybe['name'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>

                            </div>
                        </div>
                        <div class="row">
                            <div class="col">
                                <div class="form-group">
                                    <label for="start_date"><?= $lang_addemployee_jobstart ?></label>
                                    <input type="date" data-parsley-trigger="keyup" value="<?= $rowemp['dateofhire'] ?>" class="form-control" name="dateofhire" id="start_date" placeholder="<?= $lang_pbholder_enddate ?>">
                                </div>

                            </div>
                            <div class="col">
                                <div class="form-group">
                                    <label for="end_date"><?= $lang_addemployee_jobend ?></label>
                                    <input type="date" data-parsley-trigger="keyup" value="<?= $rowemp['dateofend'] ?>" class="form-control" name="dateofend" id="end_date" placeholder="<?= $lang_pbholder_startdate ?>">
                                </div>

                            </div>
                        </div>
                    </div>

                </div>
                </div>


                <div class="col col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3><?= $lang_addemployee_salaries ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- FIRST COLUMN -->
                            <div class="col">
                                <div class="form-group">
                                    <label for="salary"><?= $lang_addemployee_salary ?></label>
                                    <input type="text" data-parsley-trigger="keyup" data-parsley-type="digits" value="<?= (int) $rowemp['salary'] ?>" class="form-control" id="salary" name="salary" placeholder="<?= $lang_pbholder_salary ?>">
                                </div>
                            </div>
                            <div class="col">

                                <div class="form-group">
                                    <label><?= $lang_addemployee_shift ?></label>
                                    <select name="shift" class="custom-select">
                                        <?php
                                        $sqlshft = "select * from shifts order by name";
                                        $resshft = $conn->query($sqlshft);
                                        while ($rowshft = $resshft->fetch_assoc()) { ?>
                                            <option <?php if ($rowshft['id'] == $rowemp['shift']) echo "selected"; ?> value='<?= $rowshft['id'] ?>'><?= $rowshft['name'] ?></option>
                                        <?php } ?>
                                    </select>
                                </div>


                            </div>


                        </div>
                            
                        <div class="row">

                        <div class="col">
                        <div class="form-group">
                            <label for="">نوع الاستحقاق</label>
                            <select name="ent_tybe" id="" class="form-control">
                            <?php
                            $sqltit = "select * from entitles order by id";
                            $resentitle = $conn->query($sqltit);
                            while ($rowentitle = $resentitle->fetch_assoc()) { ?>
                            
                            <option <?php if ($rowentitle['id'] == $rowemp['ent_tybe']) echo "selected"; ?> value="<?= $rowentitle['id'] ?>" title="<?= $rowentitle['info'] ?>"><?= $rowentitle['tybe'] ?></option>
                            <?php } ?>
                            </select>
                            </div>  
                        </div>


                        <div class="col">
                        <div class="form-group">
                            <label for="hour_extra">الساعة الاضافي تحسب ك </label>
                            <input type="number" data-parsley-trigger="keyup" autocomplete="off" class="form-control" step=".01" id="hour_extra" name="hour_extra" placeholder="" value="<?= $rowemp['hour_extra'] ?>">
                        </div>
                        </div>


                        <div class="col" hidden>
                        <div class="form-group">
                            <label for="day_extra">اليوم الاضافي يحسب ك  </label>
                            <input type="number" data-parsley-trigger="keyup" data-parsley-type="digits"  autocomplete="off" class="form-control" id="day_extra" name="day_extra" placeholder="" value="<?= $rowemp['day_extra'] ?>">
                        </div>
                        </div>


                        </div>

                        <div class="row">
                            <div class="col">
                                <div class="form-group">
                                    <label for="basmaid"><?= $lang_addemployee_basmaid ?></label>
                                    <input type="text" data-parsley-trigger="keyup" data-parsley-type="integer" value="<?= $rowemp['basma_id'] ?>" autocomplete="off" class="form-control" name="basma_id" id="basmaid" placeholder="ادخل">
                                </div>
                            </div>
                            <div class="col">
                                <div class="form-group">
                                    <label for="basmaname"><?= $lang_addemployee_basmaname ?></label>
                                    <input type="text" data-parsley-trigger="keyup" autocomplete="off" class="form-control" value="<?= $rowemp['basma_name'] ?>" name="basma_name" id="basma_name" placeholder="ادخل">
                                </div>
                            </div>

                            <div class="col">
                                <div class="form-group">
                                    <label for="phone"><?= $lang_addemployee_password ?></label>
                                    <input type="password" data-parsley-trigger="keyup" autocomplete="off" class="form-control" value="<?= $rowemp['password'] ?>" name="password" id="password" placeholder="باسورد الهاتف">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                </div>
                </div>
                <div class="row">
                    <div class="col">
                        <button type="submit" id="submit" name="submit" class="btn btn-success btn-lg w-100">
                            <i class="fa fa-check"></i> <?= $lang_publicconfirm ?>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- /.card -->
</form>
<script>
$(document).ready(function() {
    // Configure Parsley with translated messages
    window.Parsley.addMessages('<?= $_SESSION['lang'] ?? 'ar' ?>', {
        defaultMessage: "<?= $lang_validation_required ?>",
        type: {
            email: "<?= $lang_validation_email ?>",
            url: "<?= $lang_validation_url ?>",
            number: "<?= $lang_validation_number ?>",
            integer: "<?= $lang_validation_integer ?>",
            digits: "<?= $lang_validation_digits ?>",
            alphanum: "<?= $lang_validation_pattern ?>"
        },
        notblank: "<?= $lang_validation_required ?>",
        required: "<?= $lang_validation_required ?>",
        pattern: "<?= $lang_validation_pattern ?>",
        min: "<?= sprintf($lang_validation_min, '%s') ?>",
        max: "<?= sprintf($lang_validation_max, '%s') ?>",
        range: "<?= sprintf($lang_validation_range, '%s', '%s') ?>",
        minlength: "<?= sprintf($lang_validation_minlength, '%s') ?>",
        maxlength: "<?= sprintf($lang_validation_maxlength, '%s') ?>",
        length: "<?= sprintf($lang_validation_length, '%s', '%s') ?>",
        equalto: "<?= $lang_validation_equalto ?>"
    });

    window.Parsley.setLocale('<?= $_SESSION['lang'] ?? 'ar' ?>');

    $("#validate_form").parsley();
});
</script>
<script>
$(function() {
    $('input, select, textarea').prop('disabled', true);
    $('#editbtn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('input, select, textarea').prop('disabled', false);
    });
});
</script>


<?php include 'includes/footer.php' ?>
