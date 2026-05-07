<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php');

$id = $_GET['id'];
$sqlshift = "SELECT * FROM shifts WHERE id = '$id'";
$resshift = $conn->query($sqlshift);
$rowshift = $resshift->fetch_assoc();

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

.form-control {
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.btn {
    border-radius: 6px;
    font-weight: 500;
}

label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-check-label {
    font-weight: 400;
}

hr {
    border-top: 1px solid #e9ecef;
    margin: 1.5rem 0;
}
</style>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3><?= $lang_infoshift ?></h3>
                </div>
                <form role="form" action="do/doedit_shift.php?id=<?= $id ?>" method="POST">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="name"><?= $lang_addhicont_name ?></label>
                                    <input name="name" value="<?= $rowshift['name'] ?>" id="name" type="text" class="form-control" placeholder="اسم الورديه" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_workday ?></label>
                                    <?php
                                    $workingdayssql = "SELECT workingdays FROM shifts WHERE id = '$id'";
                                    $wdresult = $conn->query($workingdayssql);
                                    $workingdaysrow = $wdresult->fetch_row();
                                    $workingdays = explode(',', $workingdaysrow[0]);
                                    ?>
                                    <div class="row">
                                        <div class="col-sm-6">
                                            <div class="form-check form-switch">
                                                <input name="sat" class="form-check-input" type="checkbox" role="switch" id="sat" <?php if (in_array(6, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="sat"><?= $lang_addsh_sat ?></label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input name="sun" class="form-check-input" type="checkbox" role="switch" id="sun" <?php if (in_array(7, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="sun"><?= $lang_addsh_sun ?></label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input name="mon" class="form-check-input" type="checkbox" role="switch" id="mon" <?php if (in_array(1, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="mon"><?= $lang_addsh_mon ?></label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input name="tus" class="form-check-input" type="checkbox" role="switch" id="tus" <?php if (in_array(2, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="tus"><?= $lang_addsh_tue ?></label>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <div class="form-check form-switch">
                                                <input name="wed" class="form-check-input" type="checkbox" role="switch" id="wed" <?php if (in_array(3, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="wed"><?= $lang_addsh_wed ?></label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input name="thur" class="form-check-input" type="checkbox" role="switch" id="thu" <?php if (in_array(4, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="thu"><?= $lang_addsh_thu ?></label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input name="fri" class="form-check-input" type="checkbox" role="switch" id="fri" <?php if (in_array(5, $workingdays)) echo "checked" ?>>
                                                <label class="form-check-label" for="fri"><?= $lang_addsh_fri ?></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <h5 class="mb-3"><?= $lang_Attendance_rules ?></h5>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_start ?></label>
                                    <input value="<?= $rowshift['shiftstart'] ?>" name="shiftstart" type="time" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_end ?></label>
                                    <input value="<?= $rowshift['shiftend'] ?>" name="shiftend" type="time" class="form-control" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_stardatt ?></label>
                                    <input value="<?= $rowshift['instart'] ?>" name="instart" type="time" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_endatt ?></label>
                                    <input value="<?= $rowshift['inend'] ?>" name="inend" type="time" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_startout ?></label>
                                    <input value="<?= $rowshift['outstart'] ?>" name="outstart" type="time" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_endout ?></label>
                                    <input value="<?= $rowshift['outend'] ?>" name="outend" type="time" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_delaylimits ?></label>
                                    <input value="<?= $rowshift['latelimit'] ?>" name="latelimit" type="number" class="form-control" placeholder="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><?= $lang_addsh_earlylimits ?></label>
                                    <input value="<?= $rowshift['earlylimit'] ?>" name="earlylimit" type="number" class="form-control" placeholder="0">
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-success btn-lg btn-block">
                                <i class="fas fa-save mr-2"></i><?= $lang_addhicont_confirm ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>



<?php include('includes/footer.php') ?>