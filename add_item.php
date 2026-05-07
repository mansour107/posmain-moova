<?php include('includes/header.php') ?>
<?php
$isEdit = isset($_GET['edit']);
$editId = $isEdit ? (int) $_GET['edit'] : 0;
if ($isEdit && $editId < 1) {
    header('Location: dashboard.php');
    exit;
}
if ($isEdit) {
    $rowitm = $conn->query("SELECT * FROM myitems WHERE id = " . $editId)->fetch_assoc();
    if ($rowitm == null) {
        header('Location: dashboard.php');
        exit;
    }
}
?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">
                        <i class="fas fa-<?= $isEdit ? 'pen' : 'plus-circle' ?> text-primary ml-2"></i>
                        <?= $isEdit ? 'تعديل صنف' : 'إضافة صنف' ?>
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
                        <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
                        <li class="breadcrumb-item"><a href="myitems.php">الأصناف</a></li>
                        <li class="breadcrumb-item active"><?= $isEdit ? 'تعديل' : 'إضافة' ?></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                    <i class="fas fa-check-circle ml-1"></i>
                    تم الحفظ بنجاح.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                    <i class="fas fa-exclamation-triangle ml-1"></i>
                    <?php
                    $err = isset($_GET['error']) ? $_GET['error'] : '';
                    if ($err === 'duplicate_name') {
                        echo 'يوجد صنف بنفس الاسم، اختر اسماً مختلفاً.';
                    } elseif ($err === 'save_failed') {
                        echo 'تعذّر حفظ البيانات. حاول مرة أخرى.';
                    } elseif ($err === 'invalid_image') {
                        echo 'صيغة الصورة غير مسموحة. استخدم jpg أو png أو gif أو jpeg أو webp.';
                    } else {
                        echo 'حدث خطأ أثناء الحفظ.';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($role['add_items'] == 1): ?>

                <?php if (!$isEdit): ?>
                    <form action="do/doadd_item.php" method="post" enctype="multipart/form-data" id="item-main-form">
                <?php else: ?>
                    <form action="do/doedit_item.php?edit=<?= $editId ?>" method="post" enctype="multipart/form-data" id="item-main-form">
                <?php endif; ?>

                <?php
                $rowlstitm = $conn->query('SELECT MAX(code) AS max_code FROM myitems')->fetch_assoc();
                $maxCode = $rowlstitm['max_code'] ?? null;
                if ($maxCode === null) {
                    $itmid = 1;
                } elseif ($isEdit) {
                    $itmid = $rowitm['code'];
                } else {
                    $itmid = (int) $maxCode + 1;
                }
                
                // Get the last barcode and increment by 1
                $rowlstbarcode = $conn->query('SELECT MAX(CAST(barcode AS UNSIGNED)) AS max_barcode FROM myitems WHERE barcode REGEXP \'^[0-9]+$\'')->fetch_assoc();
                $maxBarcode = $rowlstbarcode['max_barcode'] ?? null;
                if ($maxBarcode === null) {
                    $newBarcode = 1;
                } elseif ($isEdit) {
                    $newBarcode = $rowitm['barcode'];
                } else {
                    $newBarcode = (int) $maxBarcode + 1;
                }
                ?>

                <div class="card card-primary card-outline shadow-sm">
                    <div class="card-header border-primary">
                        <h3 class="card-title font-weight-bold mb-0">
                            <i class="fas fa-info-circle ml-2"></i>
                            <?= $isEdit ? 'بيانات الصنف' : 'بيانات الصنف الجديد' ?>
                        </h3>
                    </div>
                    <div class="card-body">

                        <div class="row">
                            <div class="col-md-2">
                                <div class="form-group mb-md-0">
                                    <label class="text-muted small mb-1">الكود</label>
                                    <input readonly value="<?= htmlspecialchars((string) $itmid, ENT_QUOTES, 'UTF-8') ?>" class="form-control bg-light" type="text" name="code">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group mb-md-0">
                                    <label class="text-muted small mb-1">الباركود</label>
                                    <input required value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>" class="form-control frst" type="text" name="barcode">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label for="iname">اسم الصنف <span class="text-danger">*</span></label>
                                    <input id="iname" required class="form-control" type="text" name="iname"
                                           value="<?= $isEdit ? htmlspecialchars($rowitm['iname'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                           placeholder="اسم الصنف كما يظهر في الفواتير">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label for="name2" class="text-muted small">الاسم الثاني</label>
                                    <input id="name2" class="form-control" type="text" name="name2" placeholder="اختياري"
                                           value="<?= $isEdit ? htmlspecialchars((string) ($rowitm['name2'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label for="group1">المجموعة</label>
                                    <select id="group1" name="group1" class="form-control">
                                        <option value="">— اختر —</option>
                                        <?php
                                        $resgroup1 = $conn->query('SELECT * FROM item_group WHERE isdeleted = 0');
                                        while ($rowgroup1 = $resgroup1->fetch_assoc()) { ?>
                                            <option value="<?= (int) $rowgroup1['id'] ?>" <?= ($isEdit && (int) $rowgroup1['id'] === (int) $rowitm['group1']) ? 'selected' : '' ?>><?= htmlspecialchars($rowgroup1['gname'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label for="group2">التصنيف</label>
                                    <select id="group2" name="group2" class="form-control">
                                        <option value="">— اختر —</option>
                                        <?php
                                        $resgroup2 = $conn->query('SELECT * FROM item_group2 WHERE isdeleted = 0');
                                        while ($rowgroup2 = $resgroup2->fetch_assoc()) { ?>
                                            <option value="<?= (int) $rowgroup2['id'] ?>" <?= ($isEdit && (int) $rowgroup2['id'] === (int) $rowitm['group2']) ? 'selected' : '' ?>><?= htmlspecialchars($rowgroup2['gname'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-md-0">
                                    <label for="imgs"><i class="fas fa-image text-muted ml-1"></i> صورة الصنف</label>
                                    <div class="custom-file">
                                        <input type="file" name="imgs[]" class="custom-file-input" id="imgs" multiple accept="image/*,.jpg,.jpeg,.png,.gif,.webp">
                                        <label class="custom-file-label" for="imgs" data-browse="استعراض">اختر صورة</label>
                                    </div>
                                    <small class="form-text text-muted">صيغ مسموحة: jpg, png, gif, webp</small>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-2">
                            <div class="col-12">
                                <div class="form-group mb-0">
                                    <label for="info" class="text-muted small">ملاحظات</label>
                                    <textarea id="info" class="form-control" name="info" rows="2" placeholder="وصف أو ملاحظات داخلية"><?= $isEdit ? htmlspecialchars((string) ($rowitm['info'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?></textarea>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card card-outline card-secondary shadow-sm mt-4">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between border-secondary">
                        <h3 class="card-title font-weight-bold mb-0">
                            <i class="fas fa-layer-group ml-2"></i> الوحدات والأسعار
                        </h3>
                        <button type="button" id="addUnit" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus ml-1"></i> إضافة وحدة
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm table-bordered table-striped mb-0">
                                <thead class="thead-light text-center">
                                    <tr>
                                        <th class="align-middle" style="min-width: 130px;">الوحدة</th>
                                        <th class="align-middle" style="min-width: 90px;">المعامل</th>
                                        <th class="align-middle" style="min-width: 110px;">الباركود</th>
                                        <th class="align-middle" style="min-width: 90px;">التكلفة</th>
                                        <th class="align-middle" style="min-width: 90px;">البيع 1</th>
                                        <th class="align-middle" style="min-width: 90px;">البيع 2</th>
                                        <th class="align-middle" style="min-width: 90px;">السوق</th>
                                        <th class="align-middle" style="width: 56px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="unitsContainer">
                                <?php if (!$isEdit) { ?>
                                    <tr class="urow">
                                        <td>
                                            <select name="unit_id[]" class="form-control form-control-sm">
                                                <?php
                                                $resunit = $conn->query('SELECT * FROM myunits');
                                                while ($rowunit = $resunit->fetch_assoc()) { ?>
                                                    <option value="<?= (int) $rowunit['id'] ?>"><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td><input class="form-control form-control-sm text-center" type="number" readonly name="u_val[]" value="1" step="0.001"></td>
                                        <td><input class="form-control form-control-sm" type="text" name="unit_barcode[]" value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>"></td>
                                        <td><input type="number" name="cost_price[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                        <td><input type="number" name="price1[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                        <td><input type="number" name="price2[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                        <td><input type="number" name="market_price[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                        <td class="text-center align-middle">
                                            <button type="button" class="btn btn-sm btn-outline-danger deleteRow" title="حذف الصف"><i class="fas fa-times"></i></button>
                                        </td>
                                    </tr>
                                <?php } else {
                                    $resunt = $conn->query("SELECT * FROM item_units WHERE item_id = " . $editId);
                                    while ($rowunt = $resunt->fetch_assoc()) { ?>
                                        <tr class="urow">
                                            <td>
                                                <select name="unit_id[]" class="form-control form-control-sm">
                                                    <?php
                                                    $resunit = $conn->query('SELECT * FROM myunits');
                                                    while ($rowunit = $resunit->fetch_assoc()) { ?>
                                                        <option <?= ((int) $rowunit['id'] === (int) $rowunt['unit_id']) ? 'selected' : '' ?> value="<?= (int) $rowunit['id'] ?>"><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                            <td><input class="form-control form-control-sm text-center" type="number" name="u_val[]" value="<?= htmlspecialchars((string) $rowunt['u_val'], ENT_QUOTES, 'UTF-8') ?>" step="0.001"></td>
                                            <td><input class="form-control form-control-sm" type="text" name="unit_barcode[]" value="<?= htmlspecialchars((string) $rowunt['unit_barcode'], ENT_QUOTES, 'UTF-8') ?>"></td>
                                            <td><input type="number" name="cost_price[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['cost_price'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                            <td><input type="number" name="price1[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price1'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                            <td><input type="number" name="price2[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price2'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                            <td><input type="number" name="price3[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price3'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                            <td class="text-center align-middle">
                                                <button type="button" class="btn btn-sm btn-outline-danger deleteRow" title="حذف الصف"><i class="fas fa-times"></i></button>
                                            </td>
                                        </tr>
                                    <?php }
                                } ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mb-0 p-3 border-top bg-light">
                            <i class="fas fa-lightbulb ml-1"></i>
                            السطر الأول يمثل الوحدة الأساسية. تعديل الأسعار فيه يحدّث باقي الصفوف حسب المعامل.
                        </p>
                    </div>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between bg-white">
                        <div>
                            <a href="myitems.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-right ml-1"></i> رجوع للأصناف
                            </a>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-<?= $isEdit ? 'warning' : 'primary' ?> btn-lg px-4">
                                <i class="fas fa-save ml-1"></i> <?= $isEdit ? 'تحديث الصنف' : 'حفظ الصنف' ?>
                            </button>
                        </div>
                    </div>
                </div>

                </form>

                <?php if (!$isEdit): ?>
                <div class="card card-outline card-info shadow-sm mt-4">
                    <div class="card-header border-info">
                        <h3 class="card-title font-weight-bold mb-0">
                            <i class="fas fa-file-excel ml-2 text-info"></i> استيراد أصناف من Excel
                        </h3>
                    </div>
                    <div class="card-body">
                        <form action="do/uploaditems.php" method="post" enctype="multipart/form-data" class="d-flex flex-column flex-md-row align-items-stretch align-items-md-end">
                            <div class="flex-grow-1 mb-3 mb-md-0 pl-md-3">
                                <label for="excel-file" class="text-muted small d-block">ملف Excel</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" name="file" id="excel-file" required accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                                    <label class="custom-file-label" for="excel-file" data-browse="استعراض">اختر الملف</label>
                                </div>
                            </div>
                            <button class="btn btn-info btn-md px-4" type="submit">
                                <i class="fas fa-upload ml-1"></i> رفع واستيراد
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card card-outline card-danger shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-ban fa-3x text-danger mb-3"></i>
                        <h4>ليس لديك صلاحية</h4>
                        <p class="text-muted mb-0">لا يمكنك إضافة أو تعديل الأصناف. راجع مدير النظام.</p>
                        <a href="dashboard.php" class="btn btn-primary mt-3">الرئيسية</a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>
</div>

<script>
$(document).ready(function() {
    $('.custom-file-input').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).siblings('.custom-file-label').addClass('selected').html(fileName || 'اختر ملفاً');
    });

    var fields = ['cost_price', 'price1', 'price2', 'market_price'];

    fields.forEach(function(fieldName) {
        $(document).on('input', '.urow:first input[name="' + fieldName + '[]"]', function() {
            var firstRowValue = parseFloat($(this).val()) || 0;
            $('.urow').each(function(index) {
                if (index === 0) return;
                var u_val = parseFloat($(this).find('input[name="u_val[]"]').val()) || 1;
                $(this).find('input[name="' + fieldName + '[]"]').val((firstRowValue * u_val).toFixed(3));
            });
        });
    });

    $(document).on('input', 'input[name="u_val[]"]', function() {
        var currentRow = $(this).closest('.urow');
        var u_val = parseFloat($(this).val()) || 1;
        fields.forEach(function(fieldName) {
            var firstRowValue = parseFloat($('.urow:first input[name="' + fieldName + '[]"]').val()) || 0;
            currentRow.find('input[name="' + fieldName + '[]"]').val((firstRowValue * u_val).toFixed(3));
        });
    });

    $('#item-main-form').on('submit', function(e) {
        var selectedValues = [];
        var duplicateFound = false;
        $('select[name="unit_id[]"]').each(function() {
            var val = $(this).val();
            if (val && selectedValues.indexOf(val) !== -1) duplicateFound = true;
            selectedValues.push(val);
        });
        if (duplicateFound) {
            e.preventDefault();
            alert('غير مسموح بتكرار الوحدات');
        }
    });
});
</script>

<script src="js/additem.js"></script>
<?php include('includes/footer.php') ?>
