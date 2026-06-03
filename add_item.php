<?php include('includes/header.php') ?>
<?php
require_once __DIR__ . '/classes/Pos/Service/ItemVariantService.php';

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

function posmain_add_item_unit_options(mysqli $conn): array
{
    $options = [];
    $resunit = $conn->query('SELECT * FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY id');
    while ($rowunit = $resunit->fetch_assoc()) {
        $options[] = [
            'id' => (int) $rowunit['id'],
            'uname' => (string) $rowunit['uname'],
        ];
    }

    if (!$options) {
        $options[] = [
            'id' => 0,
            'uname' => 'قطعة',
        ];
    }

    return $options;
}

$unitOptions = posmain_add_item_unit_options($conn);
$itemType = $isEdit ? (string) ($rowitm['item_type'] ?? 'sellable') : 'sellable';
$itemType = in_array($itemType, ['sellable', 'ingredient', 'packaging', 'service'], true) ? $itemType : 'sellable';
$trackStock = $itemType === 'service' ? 0 : ($isEdit ? (int) ($rowitm['track_stock'] ?? 1) : 1);
$preferredUnitId = $isEdit ? (int) ($rowitm['preferred_unit_id'] ?? 0) : 0;
$itemVariants = [];
try {
    $itemVariantService = new ItemVariantService();
    $itemVariantService->ensureSchema($conn);
    $itemVariants = $isEdit ? $itemVariantService->variantsForParent($conn, $editId, false) : [];
} catch (Throwable $exception) {
    $itemVariants = [];
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
                <input type="hidden" name="item_variants_payload_present" value="1">

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

                <style>
                    .item-editor-shell {
                        background: #f6f8fb;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        padding: 20px;
                    }
                    .item-editor-hero {
                        align-items: center;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        display: flex;
                        gap: 16px;
                        justify-content: space-between;
                        margin-bottom: 18px;
                        padding: 18px 20px;
                    }
                    .item-editor-title {
                        color: #102033;
                        font-size: 1.35rem;
                        font-weight: 800;
                        margin: 0;
                    }
                    .item-editor-status {
                        align-items: center;
                        background: #e7f7f3;
                        border: 1px solid #b8e4da;
                        border-radius: 999px;
                        color: #0f766e;
                        display: inline-flex;
                        font-size: .82rem;
                        font-weight: 700;
                        gap: 6px;
                        padding: 6px 12px;
                    }
                    .item-editor-grid {
                        direction: ltr;
                        display: grid;
                        gap: 18px;
                        grid-template-columns: minmax(0, 1fr) 310px;
                    }
                    .item-editor-main {
                        direction: rtl;
                        display: flex;
                        flex-direction: column;
                        gap: 16px;
                        min-width: 0;
                    }
                    .item-editor-panel {
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
                        min-width: 0;
                        overflow: hidden;
                    }
                    .item-editor-panel-header {
                        align-items: center;
                        border-bottom: 1px solid #e2e8f0;
                        display: flex;
                        gap: 12px;
                        justify-content: space-between;
                        padding: 16px 18px;
                    }
                    .item-editor-panel-title {
                        color: #102033;
                        font-size: 1rem;
                        font-weight: 800;
                        margin: 0;
                    }
                    .item-editor-panel-subtitle {
                        color: #64748b;
                        font-size: .82rem;
                        margin: 4px 0 0;
                    }
                    #addUnit {
                        flex: 0 0 auto;
                        white-space: nowrap;
                    }
                    .item-editor-panel-body {
                        padding: 18px;
                    }
                    .item-type-options {
                        display: grid;
                        gap: 8px;
                        grid-template-columns: repeat(4, minmax(0, 1fr));
                    }
                    .item-type-choice {
                        background: #f8fafc;
                        border: 1px solid #cbd5e1;
                        border-radius: 8px;
                        color: #334155;
                        font-weight: 700;
                        min-height: 42px;
                    }
                    .item-type-choice.active {
                        background: #0f766e;
                        border-color: #0f766e;
                        color: #ffffff;
                    }
                    .item-units-table th,
                    .item-units-table td {
                        vertical-align: middle !important;
                    }
                    .unit-relation {
                        align-items: center;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 8px;
                        justify-content: flex-end;
                        min-width: 250px;
                    }
                    .unit-relation input {
                        max-width: 96px;
                    }
                    .unit-relation-base {
                        background: #ecfdf5;
                        border: 1px solid #bbf7d0;
                        border-radius: 999px;
                        color: #047857;
                        display: inline-flex;
                        font-weight: 700;
                        padding: 6px 10px;
                    }
                    .unit-impact-preview {
                        background: #f8fafc;
                        border-top: 1px solid #e2e8f0;
                        color: #475569;
                        font-size: .88rem;
                        padding: 12px 18px;
                    }
                    .unit-base-row td {
                        background: #fbfefd;
                    }
                    .unit-base-row .unit-select {
                        border-color: #99f6e4;
                        box-shadow: 0 0 0 1px rgba(20, 184, 166, .12);
                        font-weight: 800;
                    }
                    .unit-equation {
                        align-items: center;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 8px;
                        justify-content: flex-end;
                        min-width: 250px;
                    }
                    .unit-equation strong {
                        color: #102033;
                    }
                    .unit-equation-muted {
                        color: #64748b;
                        font-size: .82rem;
                        width: 100%;
                    }
                    .unit-base-lock {
                        align-items: center;
                        background: #ccfbf1;
                        border: 1px solid #99f6e4;
                        border-radius: 999px;
                        color: #0f766e;
                        display: inline-flex;
                        font-size: .78rem;
                        font-weight: 800;
                        gap: 5px;
                        padding: 6px 10px;
                    }
                    .base-delete-disabled {
                        cursor: not-allowed;
                        opacity: .35;
                    }
                    .item-image-feedback {
                        align-items: center;
                        background: #f8fafc;
                        border: 1px solid #dbe4ef;
                        border-radius: 8px;
                        display: none;
                        gap: 10px;
                        margin-top: 10px;
                        padding: 8px;
                    }
                    .item-image-feedback.is-visible {
                        display: flex;
                    }
                    .item-image-feedback img {
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 7px;
                        height: 48px;
                        object-fit: cover;
                        width: 48px;
                    }
                    .item-image-feedback-text {
                        min-width: 0;
                    }
                    .item-image-feedback-name {
                        color: #102033;
                        display: block;
                        font-size: .86rem;
                        font-weight: 800;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: nowrap;
                    }
                    .item-image-feedback-status {
                        align-items: center;
                        color: #0f766e;
                        display: inline-flex;
                        font-size: .78rem;
                        font-weight: 700;
                        gap: 5px;
                        margin-top: 2px;
                    }
                    .item-summary-sidebar {
                        align-self: start;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
                        direction: rtl;
                        overflow: hidden;
                        position: sticky;
                        top: 86px;
                    }
                    .item-summary-sidebar .summary-head {
                        background: #102033;
                        color: #ffffff;
                        padding: 16px;
                    }
                    .summary-line {
                        border-bottom: 1px solid #e2e8f0;
                        padding: 12px 16px;
                    }
                    .summary-line:last-child {
                        border-bottom: 0;
                    }
                    .summary-label {
                        color: #64748b;
                        display: block;
                        font-size: .78rem;
                    }
                    .summary-value {
                        color: #102033;
                        display: block;
                        font-weight: 800;
                        margin-top: 3px;
                    }
                    .item-editor-actions {
                        align-items: center;
                        background: rgba(255, 255, 255, .96);
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        bottom: 16px;
                        box-shadow: 0 12px 30px rgba(15, 23, 42, .12);
                        display: flex;
                        flex-wrap: wrap;
                        gap: 10px;
                        justify-content: space-between;
                        margin-top: 18px;
                        padding: 12px;
                        position: sticky;
                        z-index: 20;
                    }
                    @media (max-width: 991.98px) {
                        .item-editor-grid {
                            grid-template-columns: 1fr;
                        }
                        .item-summary-sidebar {
                            position: static;
                        }
                        .item-type-options {
                            grid-template-columns: repeat(2, minmax(0, 1fr));
                        }
                    }
                </style>

                <div class="item-editor-shell">
                    <div class="item-editor-hero">
                        <div>
                            <h2 class="item-editor-title"><?= $isEdit ? 'تعديل الصنف' : 'إضافة صنف جديد' ?></h2>
                            <div class="mt-2">
                                <span class="item-editor-status">
                                    <i class="fas fa-database"></i>
                                    <?= $trackStock ? 'مخزون متابع' : 'بدون متابعة مخزون' ?>
                                </span>
                                <span class="text-muted small mr-2">املأ البيانات بالترتيب، وافتح الأقسام الاختيارية فقط عند الحاجة.</span>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center">
                            <a href="myitems.php" class="btn btn-outline-secondary ml-2">
                                <i class="fas fa-arrow-right ml-1"></i> رجوع للأصناف
                            </a>
                            <button type="submit" name="save_intent" value="stay" class="btn btn-primary">
                                <i class="fas fa-save ml-1"></i> <?= $isEdit ? 'حفظ التغييرات' : 'حفظ الصنف' ?>
                            </button>
                        </div>
                    </div>

                    <div class="item-editor-grid">
                        <div class="item-editor-main">
                            <section class="item-editor-panel" id="item-info-section">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">1. معلومات الصنف</h3>
                                        <p class="item-editor-panel-subtitle">البيانات التي تظهر في الفواتير والقوائم والتقارير.</p>
                                    </div>
                                </div>
                                <div class="item-editor-panel-body">
                                    <div class="row">
                                        <div class="col-lg-5">
                                            <div class="form-group">
                                                <label for="iname">اسم الصنف <span class="text-danger">*</span></label>
                                                <input id="iname" required class="form-control form-control-lg" type="text" name="iname"
                                                       value="<?= $isEdit ? htmlspecialchars($rowitm['iname'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                                       placeholder="مثال: قهوة تركي">
                                            </div>
                                        </div>
                                        <div class="col-lg-3">
                                            <div class="form-group">
                                                <label for="name2" class="text-muted small">الاسم الثاني</label>
                                                <input id="name2" class="form-control form-control-lg" type="text" name="name2" placeholder="اختياري"
                                                       value="<?= $isEdit ? htmlspecialchars((string) ($rowitm['name2'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>">
                                            </div>
                                        </div>
                                        <div class="col-lg-2">
                                            <div class="form-group">
                                                <label class="text-muted small mb-1">الكود</label>
                                                <input readonly value="<?= htmlspecialchars((string) $itmid, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-lg bg-light" type="text" name="code">
                                            </div>
                                        </div>
                                        <div class="col-lg-2">
                                            <div class="form-group">
                                                <label class="text-muted small mb-1">الباركود</label>
                                                <input required value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-lg frst" type="text" name="barcode">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
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
                                                <div class="item-image-feedback" id="itemImageFeedback" aria-live="polite">
                                                    <img id="itemImagePreview" src="" alt="">
                                                    <div class="item-image-feedback-text">
                                                        <span class="item-image-feedback-name" id="itemImageFileName"></span>
                                                        <span class="item-image-feedback-status">
                                                            <i class="fas fa-check-circle"></i>
                                                            جاهزة للحفظ
                                                        </span>
                                                    </div>
                                                </div>
                                                <small class="form-text text-muted">صيغ مسموحة: jpg, png, gif, webp</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group mb-0 mt-3">
                                        <label for="info" class="text-muted small">ملاحظات</label>
                                        <textarea id="info" class="form-control" name="info" rows="2" placeholder="وصف أو ملاحظات داخلية"><?= $isEdit ? htmlspecialchars((string) ($rowitm['info'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?></textarea>
                                    </div>
                                </div>
                            </section>

                            <section class="item-editor-panel" id="item-units-section">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">2. الوحدات والأسعار</h3>
                                        <p class="item-editor-panel-subtitle">اختر وحدة العد الأساسية أولاً، ثم أضف وحدات الشراء أو البيع مثل: 1 كرتونة = 12 قطعة.</p>
                                    </div>
                                    <button type="button" id="addUnit" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus ml-1"></i> إضافة وحدة شراء/بيع
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm mb-0 item-units-table">
                                        <thead class="thead-light text-center">
                                            <tr>
                                                <th style="min-width: 140px;">الوحدة</th>
                                                <th style="min-width: 260px;">العلاقة مع الوحدة الأساسية</th>
                                                <th style="min-width: 120px;">الباركود</th>
                                                <th style="min-width: 100px;">التكلفة</th>
                                                <th style="min-width: 110px;">سعر البيع 1</th>
                                                <th style="min-width: 110px;">سعر البيع 2</th>
                                                <th style="min-width: 105px;">سعر السوق</th>
                                                <th style="width: 60px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="unitsContainer">
                                        <?php if (!$isEdit) { ?>
                                            <tr class="urow unit-base-row">
                                                <td>
                                                    <select name="unit_id[]" class="form-control form-control-sm unit-select">
                                                        <?php foreach ($unitOptions as $rowunit) { ?>
                                                            <option value="<?= (int) $rowunit['id'] ?>"><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <div class="unit-relation">
                                                        <span class="unit-relation-base">وحدة العد الأساسية</span>
                                                        <input class="form-control form-control-sm text-center unit-factor-input" type="number" readonly name="u_val[]" value="1" step="0.001">
                                                        <span class="unit-relation-text">قطعة</span>
                                                    </div>
                                                </td>
                                                <td><input class="form-control form-control-sm" type="text" name="unit_barcode[]" value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>"></td>
                                                <td><input type="number" name="cost_price[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                                <td><input type="number" name="price1[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                                <td><input type="number" name="price2[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                                <td><input type="number" name="market_price[]" class="form-control form-control-sm" value="0" step="0.001" min="0"></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary base-delete-disabled" disabled title="لا يمكن حذف وحدة العد الأساسية"><i class="fas fa-lock"></i></button>
                                                </td>
                                            </tr>
                                        <?php } else {
                                            $renderedUnitRows = 0;
                                            $resunt = $conn->query("SELECT * FROM item_units WHERE item_id = " . $editId);
                                            while ($rowunt = $resunt->fetch_assoc()) {
                                                $renderedUnitRows++;
                                                $isBaseUnitRow = $renderedUnitRows === 1;
                                                ?>
                                                <tr class="urow <?= $isBaseUnitRow ? 'unit-base-row' : '' ?>">
                                                    <td>
                                                        <select name="unit_id[]" class="form-control form-control-sm unit-select">
                                                            <?php foreach ($unitOptions as $rowunit) { ?>
                                                                <option <?= ((int) $rowunit['id'] === (int) $rowunt['unit_id']) ? 'selected' : '' ?> value="<?= (int) $rowunit['id'] ?>"><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <div class="unit-relation">
                                                            <?php if ($isBaseUnitRow): ?>
                                                                <span class="unit-relation-base">وحدة العد الأساسية</span>
                                                            <?php else: ?>
                                                                <span>1 <strong class="unit-relation-unit-name"></strong> =</span>
                                                            <?php endif; ?>
                                                            <input class="form-control form-control-sm text-center unit-factor-input" type="number" <?= $isBaseUnitRow ? 'readonly' : '' ?> name="u_val[]" value="<?= htmlspecialchars((string) $rowunt['u_val'], ENT_QUOTES, 'UTF-8') ?>" step="0.001">
                                                            <span class="unit-relation-text">قطعة</span>
                                                        </div>
                                                    </td>
                                                    <td><input class="form-control form-control-sm" type="text" name="unit_barcode[]" value="<?= htmlspecialchars((string) $rowunt['unit_barcode'], ENT_QUOTES, 'UTF-8') ?>"></td>
                                                    <td><input type="number" name="cost_price[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['cost_price'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="price1[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price1'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="price2[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price2'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="market_price[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) $rowunt['price3'], ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td class="text-center">
                                                        <?php if ($isBaseUnitRow): ?>
                                                            <button type="button" class="btn btn-sm btn-outline-secondary base-delete-disabled" disabled title="لا يمكن حذف وحدة العد الأساسية"><i class="fas fa-lock"></i></button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-sm btn-outline-danger deleteRow" title="حذف الصف"><i class="fas fa-times"></i></button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php }
                                            if ($renderedUnitRows === 0) { ?>
                                                <tr class="urow unit-base-row">
                                                    <td>
                                                        <select name="unit_id[]" class="form-control form-control-sm unit-select">
                                                            <?php foreach ($unitOptions as $rowunit) { ?>
                                                                <option value="<?= (int) $rowunit['id'] ?>"><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <div class="unit-relation">
                                                            <span class="unit-relation-base">وحدة العد الأساسية</span>
                                                            <input class="form-control form-control-sm text-center unit-factor-input" type="number" name="u_val[]" value="1" step="0.001">
                                                            <span class="unit-relation-text">قطعة</span>
                                                        </div>
                                                    </td>
                                                    <td><input class="form-control form-control-sm" type="text" name="unit_barcode[]" value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>"></td>
                                                    <td><input type="number" name="cost_price[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($rowitm['cost_price'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="price1[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($rowitm['price1'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="price2[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($rowitm['price2'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td><input type="number" name="market_price[]" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($rowitm['price3'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0"></td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary base-delete-disabled" disabled title="لا يمكن حذف وحدة العد الأساسية"><i class="fas fa-lock"></i></button>
                                                    </td>
                                                </tr>
                                            <?php }
                                        } ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="unit-impact-preview">
                                    <i class="fas fa-calculator ml-1 text-success"></i>
                                    <span id="unitImpactPreview">مثال: عند استلام 2 من الوحدة الثانية سيُحسب أثرها على الوحدة الأساسية تلقائياً.</span>
                                </div>
                            </section>

                            <section class="item-editor-panel" id="item-inventory-section">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">3. إعدادات المخزون</h3>
                                        <p class="item-editor-panel-subtitle">نوع الصنف ومتابعة الرصيد والوحدة المفضلة للمخزون.</p>
                                    </div>
                                </div>
                                <div class="item-editor-panel-body">
                                    <label class="d-block mb-2">نوع الصنف</label>
                                    <div class="item-type-options mb-3">
                                        <button type="button" class="item-type-choice <?= $itemType === 'sellable' ? 'active' : '' ?>" data-item-type="sellable">منتج للبيع</button>
                                        <button type="button" class="item-type-choice <?= $itemType === 'ingredient' ? 'active' : '' ?>" data-item-type="ingredient">مكوّن</button>
                                        <button type="button" class="item-type-choice <?= $itemType === 'packaging' ? 'active' : '' ?>" data-item-type="packaging">تغليف</button>
                                        <button type="button" class="item-type-choice <?= $itemType === 'service' ? 'active' : '' ?>" data-item-type="service">خدمة</button>
                                    </div>
                                    <select id="item_type" name="item_type" class="form-control d-none">
                                        <option value="sellable" <?= $itemType === 'sellable' ? 'selected' : '' ?>>Sellable</option>
                                        <option value="ingredient" <?= $itemType === 'ingredient' ? 'selected' : '' ?>>Ingredient</option>
                                        <option value="packaging" <?= $itemType === 'packaging' ? 'selected' : '' ?>>Packaging</option>
                                        <option value="service" <?= $itemType === 'service' ? 'selected' : '' ?>>Service</option>
                                    </select>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group mb-md-0">
                                                <label for="preferred_unit_id">الوحدة المفضلة</label>
                                                <select id="preferred_unit_id" name="preferred_unit_id" class="form-control">
                                                    <option value="">— من أول وحدة —</option>
                                                    <?php foreach ($unitOptions as $rowunit): ?>
                                                        <option value="<?= (int) $rowunit['id'] ?>" <?= (int) $rowunit['id'] === $preferredUnitId ? 'selected' : '' ?>><?= htmlspecialchars($rowunit['uname'], ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="d-block">متابعة الرصيد</label>
                                            <input type="hidden" name="track_stock" value="0">
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input" id="track_stock" name="track_stock" value="1" <?= $trackStock ? 'checked' : '' ?>>
                                                <label class="custom-control-label" for="track_stock">خصم/متابعة الرصيد في المخزون</label>
                                            </div>
                                            <small class="form-text text-muted">الخدمات تحفظ بدون مخزون حتى لو كان المفتاح مفعلاً.</small>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="item-editor-panel" id="item-recipe-section">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">الوصفة</h3>
                                        <p class="item-editor-panel-subtitle">اربط المكوّنات والاستهلاك لهذا الصنف عند الحاجة.</p>
                                    </div>
                                    <?php if ($isEdit): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="recipe_manage.php?item_id=<?= (int) $editId ?>">
                                            <i class="fas fa-utensils ml-1"></i> فتح إدارة الوصفات
                                        </a>
                                    <?php else: ?>
                                        <span class="badge badge-light p-2">تظهر بعد حفظ الصنف</span>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <section class="item-editor-panel" id="item-variations-card">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">التنوعات</h3>
                                        <p class="item-editor-panel-subtitle">استخدمها للأحجام أو الاختيارات التي تباع كأصناف مستقلة.</p>
                                    </div>
                                    <button type="button" id="addVariantRow" class="btn btn-sm btn-outline-info">
                                        <i class="fas fa-plus ml-1"></i> إضافة تنوع
                                    </button>
                                </div>
                                <div id="variantEditorBody" class="collapse <?= count($itemVariants) > 0 ? 'show' : '' ?>">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead class="thead-light text-center">
                                                <tr>
                                                    <th style="min-width: 130px;">النوع</th>
                                        <th style="min-width: 190px;">اسم الصنف الفرعي</th>
                                        <th style="min-width: 110px;">الباركود</th>
                                        <th style="min-width: 90px;">التكلفة</th>
                                        <th style="min-width: 100px;">سعر 1</th>
                                        <th style="min-width: 100px;">سعر 2</th>
                                        <th style="min-width: 100px;">سعر السوق</th>
                                        <th style="width: 70px;">نشط</th>
                                        <th style="width: 80px;">افتراضي</th>
                                        <th style="width: 120px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="variantRowsContainer">
                                    <?php foreach ($itemVariants as $variantIndex => $variant): ?>
                                        <?php
                                        $variantLinkId = (int) ($variant['relation_id'] ?? 0);
                                        $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
                                        $variantLabel = htmlspecialchars((string) ($variant['variant_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantName = htmlspecialchars((string) ($variant['iname'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantBarcode = htmlspecialchars((string) ($variant['barcode'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantCode = htmlspecialchars((string) ($variant['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantCost = htmlspecialchars((string) ($variant['cost_price'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantPrice1 = htmlspecialchars((string) ($variant['price1'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantPrice2 = htmlspecialchars((string) ($variant['price2'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantPrice3 = htmlspecialchars((string) ($variant['price3'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantActive = (int) ($variant['is_active'] ?? 1) === 1;
                                        $variantDefault = (int) ($variant['is_default'] ?? 0) === 1;
                                        $variantSort = (int) ($variant['sort_order'] ?? ($variantIndex + 1));
                                        ?>
                                        <tr class="variant-row">
                                            <td>
                                                <input type="hidden" name="variant_link_id[]" value="<?= $variantLinkId ?>">
                                                <input type="hidden" name="variant_item_id[]" value="<?= $variantItemId ?>">
                                                <input type="hidden" name="variant_code[]" value="<?= $variantCode ?>">
                                                <input type="hidden" class="variant-sort-input" name="variant_sort[]" value="<?= $variantSort ?>">
                                                <input type="text" class="form-control form-control-sm variant-label-input" name="variant_label[]" value="<?= $variantLabel ?>" placeholder="صغير / كبير">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm variant-name-input" name="variant_name[]" value="<?= $variantName ?>" placeholder="يتولد تلقائياً من اسم الصنف">
                                            </td>
                                            <td><input type="text" class="form-control form-control-sm" name="variant_barcode[]" value="<?= $variantBarcode ?>" placeholder="اختياري"></td>
                                            <td><input type="number" class="form-control form-control-sm" name="variant_cost_price[]" value="<?= $variantCost ?>" step="0.001" min="0"></td>
                                            <td><input type="number" class="form-control form-control-sm" name="variant_price1[]" value="<?= $variantPrice1 ?>" step="0.001" min="0"></td>
                                            <td><input type="number" class="form-control form-control-sm" name="variant_price2[]" value="<?= $variantPrice2 ?>" step="0.001" min="0"></td>
                                            <td><input type="number" class="form-control form-control-sm" name="variant_market_price[]" value="<?= $variantPrice3 ?>" step="0.001" min="0"></td>
                                            <td class="text-center align-middle">
                                                <input type="hidden" name="variant_active[<?= (int) $variantIndex ?>]" value="0">
                                                <input type="checkbox" name="variant_active[<?= (int) $variantIndex ?>]" value="1" <?= $variantActive ? 'checked' : '' ?>>
                                            </td>
                                            <td class="text-center align-middle">
                                                <input type="hidden" name="variant_default[<?= (int) $variantIndex ?>]" value="0">
                                                <input type="checkbox" class="variant-default-check" name="variant_default[<?= (int) $variantIndex ?>]" value="1" <?= $variantDefault ? 'checked' : '' ?>>
                                            </td>
                                            <td class="text-center align-middle">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-secondary moveVariantUp" title="رفع"><i class="fas fa-arrow-up"></i></button>
                                                    <button type="button" class="btn btn-outline-secondary moveVariantDown" title="خفض"><i class="fas fa-arrow-down"></i></button>
                                                    <button type="button" class="btn btn-outline-danger removeVariantRow" title="حذف"><i class="fas fa-times"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                        </table>
                                    </div>
                                    <p class="text-muted small mb-0 p-3 border-top bg-light">
                                        <i class="fas fa-info-circle ml-1"></i>
                                        عند وجود تنوعات يظهر الصنف الرئيسي في الكاشير كاختيار، وكل تنوع يحفظ ويباع كصنف مستقل في الفاتورة والمخزون والمزامنة.
                                    </p>
                                </div>
                            </section>
                        </div>

                        <aside class="item-summary-sidebar">
                            <div class="summary-head">
                                <div class="font-weight-bold">ملخص الصنف</div>
                                <div class="small text-white-50 mt-1">يتحدث أثناء التعديل</div>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">الاسم</span>
                                <span class="summary-value" id="summaryItemName"><?= $isEdit ? htmlspecialchars($rowitm['iname'], ENT_QUOTES, 'UTF-8') : 'صنف جديد' ?></span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">الباركود</span>
                                <span class="summary-value" id="summaryBarcode"><?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">نوع الصنف</span>
                                <span class="summary-value" id="summaryItemType"><?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">الوحدة الأساسية</span>
                                <span class="summary-value" id="summaryBaseUnit">—</span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">عدد الوحدات</span>
                                <span class="summary-value" id="summaryUnitCount">1</span>
                            </div>
                        </aside>
                    </div>

                    <div class="item-editor-actions">
                        <div>
                            <a href="myitems.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times ml-1"></i> إلغاء
                            </a>
                        </div>
                        <div class="d-flex flex-wrap">
                            <?php if (!$isEdit): ?>
                                <button type="submit" name="save_intent" value="add_another" class="btn btn-outline-primary ml-2">
                                    <i class="fas fa-plus ml-1"></i> حفظ وإضافة صنف آخر
                                </button>
                            <?php endif; ?>
                            <button type="submit" name="save_intent" value="close" class="btn btn-outline-primary ml-2">
                                <i class="fas fa-check ml-1"></i> حفظ وإغلاق
                            </button>
                            <button type="submit" name="save_intent" value="stay" class="btn btn-primary">
                                <i class="fas fa-save ml-1"></i> <?= $isEdit ? 'حفظ التغييرات' : 'حفظ الصنف' ?>
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

    $('#imgs').on('change', function() {
        var files = this.files || [];
        var $feedback = $('#itemImageFeedback');
        var $preview = $('#itemImagePreview');
        var $fileName = $('#itemImageFileName');

        if (!files.length) {
            $feedback.removeClass('is-visible');
            $preview.attr('src', '');
            $fileName.text('');
            return;
        }

        var firstFile = files[0];
        var displayName = firstFile.name || 'صورة محددة';
        if (files.length > 1) {
            displayName += ' +' + (files.length - 1);
        }
        $fileName.text(displayName);
        $feedback.addClass('is-visible');

        if (firstFile.type && firstFile.type.indexOf('image/') === 0) {
            var reader = new FileReader();
            reader.onload = function(event) {
                $preview.attr('src', event.target.result);
            };
            reader.readAsDataURL(firstFile);
        } else {
            $preview.attr('src', '');
        }
    });

	    var fields = ['cost_price', 'price1', 'price2', 'market_price'];
	    var itemTypeLabels = {
	        sellable: 'منتج للبيع',
	        ingredient: 'مكوّن',
	        packaging: 'تغليف',
	        service: 'خدمة'
	    };

	    function selectedUnitName(row) {
	        return $.trim(row.find('.unit-select option:selected').text() || '');
	    }

	    function baseUnitName() {
	        var first = $('#unitsContainer .urow').first();
	        return selectedUnitName(first) || 'الوحدة الأساسية';
	    }

	    window.refreshItemUnitsUi = function() {
	        var baseName = baseUnitName();
	        var unitCount = $('#unitsContainer .urow').length;
	        $('#summaryBaseUnit').text(baseName || '—');
	        $('#summaryUnitCount').text(unitCount);
	        $('#unitsContainer .urow').each(function(index) {
	            var row = $(this);
	            var relation = row.find('.unit-relation');
	            var input = row.find('input[name="u_val[]"]');
	            relation.find('.unit-relation-text').text(baseName);
	            if (index === 0) {
	                row.addClass('unit-base-row');
	                input.prop('readonly', true).val('1').addClass('d-none');
	                relation.empty()
	                    .append('<span class="unit-base-lock"><i class="fas fa-lock"></i> وحدة العد الأساسية</span>')
	                    .append(
	                        $('<span class="unit-equation"></span>')
	                            .append($('<strong></strong>').text('1 ' + baseName))
	                            .append('<span>=</span>')
	                            .append($('<strong></strong>').text('1 ' + baseName))
	                    )
	                    .append('<span class="unit-equation-muted">اختر هنا أصغر وحدة تريد أن يحسب النظام المخزون بها.</span>')
	                    .append(input);
	            } else {
	                row.removeClass('unit-base-row');
	                input.prop('readonly', false).removeClass('d-none');
	                var unitName = selectedUnitName(row) || 'الوحدة';
	                if (!relation.find('.unit-relation-unit-name').length) {
	                    relation.html('<span>1 <strong class="unit-relation-unit-name"></strong> =</span>')
	                        .append(input)
	                        .append('<span class="unit-relation-text"></span>');
	                }
	                relation.find('.unit-relation-unit-name').text(unitName);
	                relation.find('.unit-relation-text').text(baseName);
	            }
	        });
	        var second = $('#unitsContainer .urow').eq(1);
	        if (second.length) {
	            var unitName = selectedUnitName(second) || 'الوحدة الثانية';
	            var factor = parseFloat(second.find('input[name="u_val[]"]').val()) || 1;
	            $('#unitImpactPreview').text('مثال: عند استلام 2 ' + unitName + ' سيضاف ' + (2 * factor).toFixed(3) + ' ' + baseName + ' إلى المخزون.');
	        } else {
	            $('#unitImpactPreview').text('أضف وحدة شراء مثل كرتونة لتحديد علاقتها بالوحدة الأساسية.');
	        }
	    };

	    function refreshItemTypeUi(type) {
	        type = type || $('#item_type').val() || 'sellable';
	        $('.item-type-choice').removeClass('active');
	        $('.item-type-choice[data-item-type="' + type + '"]').addClass('active');
	        $('#item_type').val(type);
	        $('#summaryItemType').text(itemTypeLabels[type] || type);
	        if (type === 'service') {
	            $('#track_stock').prop('checked', false).prop('disabled', true);
	        } else {
	            $('#track_stock').prop('disabled', false);
	        }
	    }

	    function refreshItemSummary() {
	        $('#summaryItemName').text($.trim($('#iname').val() || '') || 'صنف جديد');
	        $('#summaryBarcode').text($.trim($('input[name="barcode"]').val() || '') || '—');
	    }

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
	        window.refreshItemUnitsUi();
	    });

	    $(document).on('change', '.unit-select', window.refreshItemUnitsUi);
	    $(document).on('input', '#iname, input[name="barcode"]', refreshItemSummary);
	    $('.item-type-choice').on('click', function() {
	        refreshItemTypeUi($(this).data('item-type'));
	    });

    function nextVariantIndex() {
        return $('#variantRowsContainer .variant-row').length;
    }

    function variantRowHtml(index) {
        return `
            <tr class="variant-row">
                <td>
                    <input type="hidden" name="variant_link_id[]" value="0">
                    <input type="hidden" name="variant_item_id[]" value="0">
                    <input type="hidden" name="variant_code[]" value="">
                    <input type="hidden" class="variant-sort-input" name="variant_sort[]" value="${index + 1}">
                    <input type="text" class="form-control form-control-sm variant-label-input" name="variant_label[]" value="" placeholder="صغير / كبير">
                </td>
                <td>
                    <input type="text" class="form-control form-control-sm variant-name-input" name="variant_name[]" value="" placeholder="يتولد تلقائياً من اسم الصنف">
                </td>
                <td><input type="text" class="form-control form-control-sm" name="variant_barcode[]" value="" placeholder="اختياري"></td>
                <td><input type="number" class="form-control form-control-sm" name="variant_cost_price[]" value="0" step="0.001" min="0"></td>
                <td><input type="number" class="form-control form-control-sm" name="variant_price1[]" value="0" step="0.001" min="0"></td>
                <td><input type="number" class="form-control form-control-sm" name="variant_price2[]" value="0" step="0.001" min="0"></td>
                <td><input type="number" class="form-control form-control-sm" name="variant_market_price[]" value="0" step="0.001" min="0"></td>
                <td class="text-center align-middle">
                    <input type="hidden" name="variant_active[${index}]" value="0">
                    <input type="checkbox" name="variant_active[${index}]" value="1" checked>
                </td>
                <td class="text-center align-middle">
                    <input type="hidden" name="variant_default[${index}]" value="0">
                    <input type="checkbox" class="variant-default-check" name="variant_default[${index}]" value="1">
                </td>
                <td class="text-center align-middle">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary moveVariantUp" title="رفع"><i class="fas fa-arrow-up"></i></button>
                        <button type="button" class="btn btn-outline-secondary moveVariantDown" title="خفض"><i class="fas fa-arrow-down"></i></button>
                        <button type="button" class="btn btn-outline-danger removeVariantRow" title="حذف"><i class="fas fa-times"></i></button>
                    </div>
                </td>
            </tr>
        `;
    }

    function refreshVariantSorts() {
        $('#variantRowsContainer .variant-row').each(function(index) {
            $(this).find('.variant-sort-input').val(index + 1);
            $(this).find('input[name^="variant_active"]').each(function() {
                $(this).attr('name', 'variant_active[' + index + ']');
            });
            $(this).find('input[name^="variant_default"]').each(function() {
                $(this).attr('name', 'variant_default[' + index + ']');
            });
        });
    }

    function generatedVariantName(label) {
        var parentName = $.trim($('#iname').val() || '');
        label = $.trim(label || '');
        if (parentName === '') return label;
        if (label === '') return parentName;
        if (label.indexOf(parentName) === 0) return label;
        return parentName + ' - ' + label;
    }

	    $('#addVariantRow').on('click', function() {
	        $('#variantEditorBody').addClass('show').css('display', 'block');
	        $('#variantRowsContainer').append(variantRowHtml(nextVariantIndex()));
	        refreshVariantSorts();
	    });

    $(document).on('click', '.removeVariantRow', function() {
        $(this).closest('.variant-row').remove();
        refreshVariantSorts();
    });

    $(document).on('click', '.moveVariantUp, .moveVariantDown', function() {
        var row = $(this).closest('.variant-row');
        if ($(this).hasClass('moveVariantUp')) {
            row.prev('.variant-row').before(row);
        } else {
            row.next('.variant-row').after(row);
        }
        refreshVariantSorts();
    });

    $(document).on('change', '.variant-default-check', function() {
        if ($(this).prop('checked')) {
            $('.variant-default-check').not(this).prop('checked', false);
        }
    });

    $(document).on('input', '.variant-label-input', function() {
        var row = $(this).closest('.variant-row');
        var nameInput = row.find('.variant-name-input');
        if ($.trim(nameInput.val() || '') === '') {
            nameInput.attr('placeholder', generatedVariantName($(this).val()));
        }
    });

    function variationsAreValidForSubmit() {
        var valid = true;
        var seenLabels = [];
        $('#variantRowsContainer .variant-row').each(function() {
            var label = $.trim($(this).find('.variant-label-input').val() || '');
            var name = $.trim($(this).find('.variant-name-input').val() || '');
            if (label === '' && name === '') {
                return;
            }
            var key = label.toLowerCase();
            if (key !== '' && seenLabels.indexOf(key) !== -1) {
                alert('لا يمكن تكرار نفس نوع التنوع');
                valid = false;
                return false;
            }
            if (key !== '') {
                seenLabels.push(key);
            }
        });
        return valid;
    }

    $('#item-main-form').on('submit', function(e) {
        refreshVariantSorts();
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
	        if (!variationsAreValidForSubmit()) {
	            e.preventDefault();
	        }
	    });

	    refreshItemSummary();
	    refreshItemTypeUi();
	    window.refreshItemUnitsUi();
	});
	</script>

<script src="js/additem.js"></script>
<?php include('includes/footer.php') ?>
