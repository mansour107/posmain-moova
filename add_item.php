<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
page_guard('menu.edit', $conn);
include('includes/header.php') ?>
<?php
require_once __DIR__ . '/classes/Items/ItemEditorFlash.php';
require_once __DIR__ . '/classes/Items/ItemFormInput.php';
require_once __DIR__ . '/classes/Items/ItemCostSourceSupport.php';
require_once __DIR__ . '/classes/Items/ItemUnitProfileBuilder.php';
require_once __DIR__ . '/classes/Items/ItemUnitProfileReader.php';
require_once __DIR__ . '/classes/Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/classes/Pos/Service/PreparationSelectionService.php';

$itemEditorFlash = ItemEditorFlash::take();

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
    $seenIds = [];
    $seenNames = [];
    $resunit = $conn->query('SELECT * FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY id');
    while ($rowunit = $resunit->fetch_assoc()) {
        $id = (int) $rowunit['id'];
        $uname = (string) $rowunit['uname'];
        $normalizedName = mb_strtolower(trim($uname), 'UTF-8');
        if ($id > 0 && isset($seenIds[$id])) {
            continue;
        }
        if ($normalizedName !== '' && isset($seenNames[$normalizedName])) {
            continue;
        }
        if ($id > 0) {
            $seenIds[$id] = true;
        }
        if ($normalizedName !== '') {
            $seenNames[$normalizedName] = true;
        }
        $options[] = [
            'id' => $id,
            'uname' => $uname,
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

function posmain_add_item_group_options(mysqli $conn, string $table): array
{
    $options = [];
    $seenIds = [];
    $seenNames = [];
    $allowedTables = ['item_group' => 'item_group', 'item_group2' => 'item_group2'];
    if (!isset($allowedTables[$table])) {
        return $options;
    }

    $sqlTable = $allowedTables[$table];
    $result = $conn->query("SELECT id, gname FROM {$sqlTable} WHERE isdeleted = 0 ORDER BY gname ASC");
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $name = (string) $row['gname'];
        $normalizedName = mb_strtolower(trim($name), 'UTF-8');
        if ($id > 0 && isset($seenIds[$id])) {
            continue;
        }
        if ($normalizedName !== '' && isset($seenNames[$normalizedName])) {
            continue;
        }
        if ($id > 0) {
            $seenIds[$id] = true;
        }
        if ($normalizedName !== '') {
            $seenNames[$normalizedName] = true;
        }
        $options[] = [
            'id' => $id,
            'name' => $name,
        ];
    }

    return $options;
}

$unitOptions = posmain_add_item_unit_options($conn);
$defaultUnitId = ItemFormInput::resolveDefaultUnitId($conn);
$itemType = $isEdit ? (string) ($rowitm['item_type'] ?? 'sellable') : 'sellable';
$itemType = in_array($itemType, ['sellable', 'made', 'ingredient', 'packaging', 'service'], true) ? $itemType : 'sellable';
$unitProfile = $isEdit
    ? ItemUnitProfileReader::readForItem($conn, $editId, $itemType)
    : ItemUnitProfileReader::defaultProfile($itemType, $defaultUnitId);
if ($isEdit) {
    if ((float) ($unitProfile['sell_price1'] ?? 0) <= 0 && (float) ($rowitm['price1'] ?? 0) > 0) {
        $unitProfile['sell_price1'] = (float) $rowitm['price1'];
    }
    $existingItemCost = (float) ($rowitm['cost_price'] ?? 0);
    if ($existingItemCost > 0) {
        $costPerSellUnit = ItemUnitProfileBuilder::displayCostPerSellUnit($existingItemCost, $unitProfile);
        $unitProfile['cost_per_unit'] = $costPerSellUnit;
        if (($unitProfile['cost_source'] ?? 'purchase') === 'direct') {
            $unitProfile['direct_cost_price'] = $costPerSellUnit;
        }
        if (($unitProfile['cost_source'] ?? 'purchase') === 'recipe') {
            $unitProfile['recipe_cost_price'] = $costPerSellUnit;
        }
    }
}
$itemCostSourceMeta = [
    'recipe_available' => false,
    'recipe_has_cost' => false,
    'recipe_cost' => 0.0,
    'stored_cost_source' => 'direct',
];
if ($isEdit) {
    $itemCostSourceMeta = ItemCostSourceSupport::editorMeta($conn, $editId);
    $unitProfile['recipe_available'] = $itemCostSourceMeta['recipe_available'];
    $unitProfile['recipe_has_cost'] = $itemCostSourceMeta['recipe_has_cost'];
    if ($itemCostSourceMeta['recipe_has_cost']) {
        $unitProfile['recipe_cost_price'] = $itemCostSourceMeta['recipe_cost'];
    }
    $unitProfile['cost_source'] = $itemCostSourceMeta['stored_cost_source'];
} else {
    $unitProfile['cost_source'] = 'direct';
}
$itemGroup1Options = posmain_add_item_group_options($conn, 'item_group');
$itemGroup2Options = posmain_add_item_group_options($conn, 'item_group2');
$canCreateItemGroups = auth_guard_has_permission('menu.edit', $conn);
$canCreateUnits = auth_guard_has_permission('menu.edit', $conn);
$selectedGroup1Id = $isEdit ? (int) ($rowitm['group1'] ?? 0) : 0;
$selectedGroup2Id = $isEdit ? (int) ($rowitm['group2'] ?? 0) : 0;
$selectedGroup1Name = '';
$selectedGroup2Name = '';
foreach ($itemGroup1Options as $groupOption) {
    if ((int) $groupOption['id'] === $selectedGroup1Id) {
        $selectedGroup1Name = (string) $groupOption['name'];
        break;
    }
}
foreach ($itemGroup2Options as $groupOption) {
    if ((int) $groupOption['id'] === $selectedGroup2Id) {
        $selectedGroup2Name = (string) $groupOption['name'];
        break;
    }
}
$trackStock = in_array($itemType, ['service', 'made'], true) ? 0 : ($isEdit ? (int) ($rowitm['track_stock'] ?? 1) : 1);
$preferredUnitId = $isEdit ? (int) ($rowitm['preferred_unit_id'] ?? 0) : 0;
$itemVariants = [];
$preparationFieldsEnabled = function_exists('posmain_app_config') && !empty(posmain_app_config()['features']['preparation_fields']);
$preparationFieldsAvailable = false;
if ($preparationFieldsEnabled) {
    $preparationTable = $conn->query("SHOW TABLES LIKE 'item_preparation_configs'");
    $preparationFieldsAvailable = $preparationTable && $preparationTable->num_rows > 0;
    if ($preparationTable) {
        $preparationTable->free();
    }
}
$sugarPreparation = ['enabled' => false, 'max' => 5, 'inventory_item_id' => 0, 'inventory_qty' => '0'];
if ($preparationFieldsAvailable && $isEdit) {
    $stmt = $conn->prepare("SELECT is_active, max_value, inventory_item_id, inventory_qty_per_value FROM item_preparation_configs WHERE item_id = ? AND field_code = 'sugar_spoons' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $sugarPreparation = [
                'enabled' => (int) ($row['is_active'] ?? 0) === 1,
                'max' => (int) ($row['max_value'] ?? 5),
                'inventory_item_id' => (int) ($row['inventory_item_id'] ?? 0),
                'inventory_qty' => (string) ($row['inventory_qty_per_value'] ?? '0'),
            ];
        }
    }
}
try {
    $itemVariantService = new ItemVariantService();
    $itemVariantService->ensureSchema($conn);
    $itemVariants = $isEdit ? $itemVariantService->variantsForEdit($conn, $editId) : [];
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
                        <?php if ($isEdit): ?>
                            <?= htmlspecialchars((string) ($rowitm['iname'] ?? ''), ENT_QUOTES, 'UTF-8') ?: 'صنف بدون اسم' ?>
                        <?php else: ?>
                            <i class="fas fa-plus-circle text-primary ml-2"></i>
                            إضافة صنف
                        <?php endif; ?>
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

            <?php if ($itemEditorFlash !== null): ?>
                <div class="alert alert-<?= $itemEditorFlash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show shadow-sm item-editor-flash-alert" role="alert" data-autodismiss-ms="10000">
                    <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
                    <i class="fas fa-<?= $itemEditorFlash['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> ml-1"></i>
                    <?= htmlspecialchars($itemEditorFlash['message'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (auth_guard_has_permission('menu.edit', $conn)): ?>

                <?php if (!$isEdit): ?>
                    <form action="do/doadd_item.php" method="post" enctype="multipart/form-data" id="item-main-form">
                    <?= csrf_input('menu_write') ?>
                <?php else: ?>
                    <form action="do/doedit_item.php?edit=<?= $editId ?>" method="post" enctype="multipart/form-data" id="item-main-form">
                    <?= csrf_input('menu_write') ?>
                <?php endif; ?>
                <input type="hidden" name="item_variants_payload_present" value="1">

                <?php
                if ($isEdit) {
                    $newBarcode = $rowitm['barcode'];
                } else {
                    $rowlstbarcode = $conn->query('SELECT MAX(CAST(barcode AS UNSIGNED)) AS max_barcode FROM myitems WHERE barcode REGEXP \'^[0-9]+$\'')->fetch_assoc();
                    $maxBarcode = $rowlstbarcode['max_barcode'] ?? null;
                    $newBarcode = $maxBarcode === null ? 1 : (int) $maxBarcode + 1;
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
                        direction: rtl;
                        display: flex;
                        gap: 16px;
                        justify-content: space-between;
                        margin-bottom: 18px;
                        padding: 14px 16px;
                    }
                    .item-editor-hero__actions {
                        align-items: center;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 10px;
                    }
                    .item-editor-hero__actions .btn {
                        border-radius: 8px;
                        font-weight: 700;
                        min-height: 40px;
                    }
                    .item-editor-hero__new {
                        align-items: center;
                        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 55%, #1e40af 100%);
                        border: 1px solid rgba(255, 255, 255, .14);
                        border-radius: 12px;
                        box-shadow: 0 10px 24px rgba(37, 99, 235, .22), inset 0 1px 0 rgba(255, 255, 255, .16);
                        color: #ffffff;
                        display: inline-flex;
                        gap: 12px;
                        padding: 9px 16px 9px 14px;
                        text-decoration: none;
                        transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
                    }
                    .item-editor-hero__new:hover,
                    .item-editor-hero__new:focus {
                        color: #ffffff;
                        filter: brightness(1.04);
                        text-decoration: none;
                    }
                    .item-editor-hero__new:hover {
                        box-shadow: 0 14px 28px rgba(37, 99, 235, .28);
                        transform: translateY(-1px);
                    }
                    .item-editor-hero__new:focus-visible {
                        outline: 2px solid #93c5fd;
                        outline-offset: 2px;
                    }
                    .item-editor-hero__new-icon {
                        align-items: center;
                        background: rgba(255, 255, 255, .16);
                        border-radius: 10px;
                        display: inline-flex;
                        font-size: .95rem;
                        height: 36px;
                        justify-content: center;
                        width: 36px;
                    }
                    .item-editor-hero__new-copy {
                        display: flex;
                        flex-direction: column;
                        line-height: 1.2;
                    }
                    .item-editor-hero__new-label {
                        font-size: .92rem;
                        font-weight: 800;
                    }
                    .item-editor-hero__new-hint {
                        color: rgba(255, 255, 255, .78);
                        font-size: .72rem;
                        font-weight: 600;
                        margin-top: 2px;
                    }
                    .item-editor-hero--add {
                        justify-content: flex-end;
                    }
                    @media (max-width: 575.98px) {
                        .item-editor-hero:not(.item-editor-hero--add) {
                            align-items: stretch;
                            flex-direction: column;
                        }
                        .item-editor-hero__actions {
                            justify-content: stretch;
                        }
                        .item-editor-hero__actions .btn {
                            flex: 1 1 auto;
                            justify-content: center;
                        }
                        .item-editor-hero__new {
                            justify-content: center;
                            width: 100%;
                        }
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
                    #item-info-section .item-name-field-input {
                        min-width: 0;
                        width: 100%;
                    }
                    #item-info-section input[name="barcode"] {
                        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                        font-size: 1.05rem;
                        letter-spacing: 0.03em;
                        min-width: 0;
                        width: 100%;
                    }
                    .item-type-options {
                        display: grid;
                        gap: 8px;
                        grid-template-columns: repeat(4, minmax(0, 1fr));
                    }
                    .item-type-choice {
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        cursor: pointer;
                        display: flex;
                        flex-direction: column;
                        gap: 3px;
                        padding: 12px 10px;
                        text-align: center;
                        transition: border-color .15s ease, background-color .15s ease;
                    }
                    .item-type-choice:hover {
                        border-color: #94a3b8;
                    }
                    .item-type-choice__name {
                        color: #334155;
                        font-size: .92rem;
                        font-weight: 700;
                    }
                    .item-type-choice__example {
                        color: #94a3b8;
                        font-size: .76rem;
                    }
                    .item-type-choice.active {
                        background: #eff6ff;
                        border-color: #2563eb;
                    }
                    .item-type-choice.active .item-type-choice__name {
                        color: #1d4ed8;
                    }
                    .item-type-choice.active .item-type-choice__example {
                        color: #60a5fa;
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
                    .summary-value--margin {
                        color: #047857;
                    }
                    .summary-value--margin.is-negative {
                        color: #b91c1c;
                    }
                    .summary-value--margin.is-empty {
                        color: #94a3b8;
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
                    .variant-cards-header {
                        background: #f8fafc;
                        border-bottom: 1px solid #e2e8f0;
                        color: #64748b;
                        display: grid;
                        font-size: .78rem;
                        font-weight: 700;
                        gap: 10px;
                        grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, .85fr) minmax(0, .95fr) 72px;
                        padding: 10px 16px;
                    }
                    .variant-cards-list {
                        display: flex;
                        flex-direction: column;
                    }
                    .variant-card {
                        border-bottom: 1px solid #e2e8f0;
                        padding: 12px 16px;
                    }
                    .variant-card:last-child {
                        border-bottom: 0;
                    }
                    .variant-card.is-field-error {
                        background: #fef2f2;
                    }
                    .variant-card.table-warning {
                        background: #fffbeb;
                    }
                    .variant-card__grid {
                        align-items: start;
                        display: grid;
                        gap: 10px;
                        grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, .85fr) minmax(0, .95fr) 72px;
                    }
                    .variant-card__field .form-control {
                        min-width: 0;
                    }
                    .variant-card__label {
                        color: #64748b;
                        display: none;
                        font-size: .74rem;
                        font-weight: 700;
                        margin-bottom: 4px;
                    }
                    .variant-card__actions {
                        align-items: center;
                        display: flex;
                        gap: 4px;
                        justify-content: center;
                        padding-top: 2px;
                    }
                    .variant-card__hint {
                        color: #b45309;
                        font-size: .74rem;
                        margin-top: 4px;
                    }
                    .variant-card__margin {
                        color: #047857;
                        display: block;
                        font-size: .72rem;
                        font-weight: 700;
                        margin-top: 4px;
                    }
                    .variant-card__margin.is-negative {
                        color: #b91c1c;
                    }
                    .variant-card__margin.is-empty {
                        color: #94a3b8;
                        font-weight: 600;
                    }
                    .variant-editor-footer {
                        background: #f8fafc;
                        border-top: 1px solid #e2e8f0;
                        color: #64748b;
                        font-size: .82rem;
                        margin: 0;
                        padding: 12px 16px;
                    }
                    @media (max-width: 767.98px) {
                        .variant-cards-header {
                            display: none;
                        }
                        .variant-card__grid {
                            grid-template-columns: 1fr 1fr;
                        }
                        .variant-card__field--wide {
                            grid-column: 1 / -1;
                        }
                        .variant-card__actions {
                            grid-column: 1 / -1;
                            justify-content: flex-end;
                            padding-top: 0;
                        }
                        .variant-card__label {
                            display: block;
                        }
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
                <link rel="stylesheet" href="dist/css/item_catalog_group_picker.css?v=<?= (int) (@filemtime(__DIR__ . '/dist/css/item_catalog_group_picker.css') ?: 1) ?>">
                <link rel="stylesheet" href="dist/css/item_unit_profile.css?v=<?= (int) (@filemtime(__DIR__ . '/dist/css/item_unit_profile.css') ?: 1) ?>">

                <div class="item-editor-shell">
                    <div class="item-editor-hero<?= $isEdit ? '' : ' item-editor-hero--add' ?>">
                        <?php if ($isEdit): ?>
                            <a href="add_item.php" class="item-editor-hero__new" id="itemEditorAddNew">
                                <span class="item-editor-hero__new-icon" aria-hidden="true"><i class="fas fa-plus"></i></span>
                                <span class="item-editor-hero__new-copy">
                                    <span class="item-editor-hero__new-label">صنف جديد</span>
                                    <span class="item-editor-hero__new-hint">إضافة صنف آخر</span>
                                </span>
                            </a>
                        <?php endif; ?>
                        <div class="item-editor-hero__actions">
                            <a href="myitems.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-right ml-1"></i> رجوع للأصناف
                            </a>
                            <?php if ($isEdit): ?>
                                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#deleteItemModal" title="حذف الصنف">
                                    <i class="fas fa-trash ml-1"></i> حذف الصنف
                                </button>
                            <?php endif; ?>
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
                                    <input type="hidden" name="name2" value="<?= $isEdit ? htmlspecialchars((string) ($rowitm['name2'] ?? ''), ENT_QUOTES, 'UTF-8') : '' ?>">
                                    <div class="row">
                                        <div class="col-12 col-lg-7">
                                            <div class="form-group mb-lg-0">
                                                <label for="iname">اسم الصنف <span class="text-danger">*</span></label>
                                                <input id="iname" required class="form-control form-control-lg item-name-field-input" type="text" name="iname"
                                                       value="<?= $isEdit ? htmlspecialchars($rowitm['iname'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                                       placeholder="مثال: قهوة تركي">
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-5">
                                            <div class="form-group mb-lg-0">
                                                <label for="barcode">الباركود</label>
                                                <input id="barcode" required value="<?= htmlspecialchars((string) $newBarcode, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-lg frst" type="text" name="barcode" autocomplete="off" spellcheck="false">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-lg-2">
                                        <div class="col-12 col-lg-6">
                                            <div class="form-group mb-md-0">
                                                <label for="group1_input">التصنيف</label>
                                                <div class="item-catalog-group-picker" data-picker-type="group1">
                                                    <div class="item-catalog-group-picker__row">
                                                        <div class="item-catalog-group-picker__field">
                                                            <div class="item-catalog-combobox" data-group-type="group1">
                                                                <input type="hidden" class="item-catalog-combobox__value" name="group1" id="group1" value="<?= $selectedGroup1Id > 0 ? (int) $selectedGroup1Id : '' ?>">
                                                                <div class="item-catalog-combobox__control">
                                                                    <input
                                                                        type="text"
                                                                        class="item-catalog-combobox__input"
                                                                        id="group1_input"
                                                                        autocomplete="off"
                                                                        role="combobox"
                                                                        aria-expanded="false"
                                                                        aria-controls="group1_listbox"
                                                                        placeholder="اكتب للبحث أو اختر تصنيفاً..."
                                                                        value="<?= htmlspecialchars($selectedGroup1Name, ENT_QUOTES, 'UTF-8') ?>"
                                                                    >
                                                                    <button type="button" class="item-catalog-combobox__toggle" tabindex="-1" aria-label="عرض التصنيفات">
                                                                        <i class="fas fa-chevron-down"></i>
                                                                    </button>
                                                                </div>
                                                                <ul class="item-catalog-combobox__list" id="group1_listbox" role="listbox" hidden>
                                                                    <?php foreach ($itemGroup1Options as $groupOption) { ?>
                                                                        <li class="item-catalog-combobox__option" role="option" data-id="<?= (int) $groupOption['id'] ?>" data-name="<?= htmlspecialchars($groupOption['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groupOption['name'], ENT_QUOTES, 'UTF-8') ?></li>
                                                                    <?php } ?>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                        <?php if ($canCreateItemGroups) { ?>
                                                            <button type="button" class="item-catalog-group-picker__add" title="إضافة تصنيف جديد" aria-label="إضافة تصنيف جديد">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        <?php } ?>
                                                    </div>
                                                    <div class="item-catalog-group-picker__hint"><?= $canCreateItemGroups ? 'اكتب مباشرة في الحقل للبحث، أو استخدم زر +' : 'اكتب مباشرة في الحقل للبحث والاختيار.' ?></div>
                                                    <div class="item-catalog-group-picker__toast" aria-live="polite"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-lg-6">
                                            <div class="form-group mb-md-0">
                                                <label for="group2_input">المجموعة الفرعية</label>
                                                <div class="item-catalog-group-picker" data-picker-type="group2">
                                                    <div class="item-catalog-group-picker__row">
                                                        <div class="item-catalog-group-picker__field">
                                                            <div class="item-catalog-combobox" data-group-type="group2">
                                                                <input type="hidden" class="item-catalog-combobox__value" name="group2" id="group2" value="<?= $selectedGroup2Id > 0 ? (int) $selectedGroup2Id : '' ?>">
                                                                <div class="item-catalog-combobox__control">
                                                                    <input
                                                                        type="text"
                                                                        class="item-catalog-combobox__input"
                                                                        id="group2_input"
                                                                        autocomplete="off"
                                                                        role="combobox"
                                                                        aria-expanded="false"
                                                                        aria-controls="group2_listbox"
                                                                        placeholder="اكتب للبحث أو اختر مجموعة فرعية..."
                                                                        value="<?= htmlspecialchars($selectedGroup2Name, ENT_QUOTES, 'UTF-8') ?>"
                                                                    >
                                                                    <button type="button" class="item-catalog-combobox__toggle" tabindex="-1" aria-label="عرض المجموعات الفرعية">
                                                                        <i class="fas fa-chevron-down"></i>
                                                                    </button>
                                                                </div>
                                                                <ul class="item-catalog-combobox__list" id="group2_listbox" role="listbox" hidden>
                                                                    <?php foreach ($itemGroup2Options as $groupOption) { ?>
                                                                        <li class="item-catalog-combobox__option" role="option" data-id="<?= (int) $groupOption['id'] ?>" data-name="<?= htmlspecialchars($groupOption['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($groupOption['name'], ENT_QUOTES, 'UTF-8') ?></li>
                                                                    <?php } ?>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                        <?php if ($canCreateItemGroups) { ?>
                                                            <button type="button" class="item-catalog-group-picker__add" title="إضافة مجموعة فرعية جديدة" aria-label="إضافة مجموعة فرعية جديدة">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        <?php } ?>
                                                    </div>
                                                    <div class="item-catalog-group-picker__hint"><?= $canCreateItemGroups ? 'اكتب مباشرة في الحقل للبحث، أو استخدم زر +' : 'اكتب مباشرة في الحقل للبحث والاختيار.' ?></div>
                                                    <div class="item-catalog-group-picker__toast" aria-live="polite"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mt-2">
                                        <div class="col-md-6">
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

                            <?php if ($preparationFieldsAvailable && $isEdit): ?>
                                <section class="item-editor-panel" id="item-preparation-section">
                                    <div class="item-editor-panel-header">
                                        <div>
                                            <h3 class="item-editor-panel-title">تحضير المشروب</h3>
                                            <p class="item-editor-panel-subtitle">خيار تشغيلي مجاني، منفصل عن الإضافات والأسعار والوصفة.</p>
                                        </div>
                                    </div>
                                    <div class="item-editor-panel-body">
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="sugar_spoons_enabled" name="sugar_spoons_enabled" value="1" <?= $sugarPreparation['enabled'] ? 'checked' : '' ?> >
                                            <label class="custom-control-label" for="sugar_spoons_enabled">اطلب من الكاشير تحديد ملاعق السكر لهذا الصنف</label>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label for="sugar_spoons_max">الحد الأقصى</label>
                                                <input id="sugar_spoons_max" name="sugar_spoons_max" type="number" min="1" max="20" class="form-control" value="<?= (int) $sugarPreparation['max'] ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="sugar_spoons_inventory_item_id">صنف مخزون السكر (اختياري)</label>
                                                <input id="sugar_spoons_inventory_item_id" name="sugar_spoons_inventory_item_id" type="number" min="0" class="form-control" value="<?= (int) $sugarPreparation['inventory_item_id'] ?>" placeholder="رقم الصنف">
                                            </div>
                                            <div class="col-md-4">
                                                <label for="sugar_spoons_inventory_qty_per_spoon">كمية المخزون لكل ملعقة</label>
                                                <input id="sugar_spoons_inventory_qty_per_spoon" name="sugar_spoons_inventory_qty_per_spoon" type="number" min="0" step="0.000001" class="form-control" value="<?= htmlspecialchars($sugarPreparation['inventory_qty'], ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                        </div>
                                        <small class="form-text text-muted mt-2">لا يوجد اختيار افتراضي: يختار الكاشير 0 إلى الحد الأقصى لكل سطر طلب.</small>
                                    </div>
                                </section>
                            <?php endif; ?>

                            <?php $hideParentPricingSection = count($itemVariants) > 0; ?>
                            <?php include __DIR__ . '/elements/sales/item_unit_profile_panel.php'; ?>

                            <section class="item-editor-panel" id="item-variations-card">
                                <div class="item-editor-panel-header">
                                    <div>
                                        <h3 class="item-editor-panel-title">3. التنوعات</h3>
                                        <p class="item-editor-panel-subtitle">أحجام أو اختيارات بسعر وتكلفة لكل تنوع.</p>
                                    </div>
                                    <button type="button" id="addVariantRow" class="btn btn-sm btn-outline-info">
                                        <i class="fas fa-plus ml-1"></i> إضافة تنوع
                                    </button>
                                </div>
                                <div id="variantEditorBody" class="<?= count($itemVariants) > 0 ? '' : 'd-none' ?>">
                                    <div class="variant-cards-header" aria-hidden="true">
                                        <span>النوع</span>
                                        <span>اسم الصنف الفرعي</span>
                                        <span>الباركود</span>
                                        <span>التكلفة</span>
                                        <span>سعر البيع</span>
                                        <span></span>
                                    </div>
                                    <div class="variant-cards-list" id="variantRowsContainer">
                                    <?php foreach ($itemVariants as $variantIndex => $variant): ?>
                                        <?php
                                        $variantLinkId = (int) ($variant['relation_id'] ?? 0);
                                        $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
                                        $variantLabel = htmlspecialchars((string) ($variant['variant_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantName = htmlspecialchars((string) ($variant['iname'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantBarcode = htmlspecialchars((string) ($variant['barcode'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $variantCost = htmlspecialchars((string) ($variant['cost_price'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantPrice1 = htmlspecialchars((string) ($variant['price1'] ?? '0'), ENT_QUOTES, 'UTF-8');
                                        $variantSort = (int) ($variant['sort_order'] ?? ($variantIndex + 1));
                                        $variantUnlinked = !empty($variant['is_unlinked_recovery']);
                                        ?>
                                        <div class="variant-card variant-row<?= $variantUnlinked ? ' table-warning' : '' ?>" data-original-label="<?= $variantLabel ?>">
                                            <div class="variant-card__grid">
                                                <div class="variant-card__field">
                                                    <span class="variant-card__label">النوع</span>
                                                    <input type="hidden" name="variant_link_id[]" value="<?= $variantLinkId ?>">
                                                    <input type="hidden" name="variant_item_id[]" value="<?= $variantItemId ?>">
                                                    <input type="hidden" class="variant-sort-input" name="variant_sort[]" value="<?= $variantSort ?>">
                                                    <input type="text" class="form-control form-control-sm variant-label-input" name="variant_label[]" value="<?= $variantLabel ?>" placeholder="صغير / كبير">
                                                    <?php if ($variantUnlinked): ?>
                                                        <span class="variant-card__hint">تنوع قديم غير مربوط — سيُعاد ربطه عند الحفظ</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="variant-card__field variant-card__field--wide">
                                                    <span class="variant-card__label">اسم الصنف الفرعي</span>
                                                    <input type="text" class="form-control form-control-sm variant-name-input" name="variant_name[]" value="<?= $variantName ?>" data-initial-child-name="<?= $variantName ?>" placeholder="يتولد تلقائياً من اسم الصنف">
                                                </div>
                                                <div class="variant-card__field">
                                                    <span class="variant-card__label">الباركود</span>
                                                    <input type="text" class="form-control form-control-sm" name="variant_barcode[]" value="<?= $variantBarcode ?>" placeholder="اختياري">
                                                </div>
                                                <div class="variant-card__field">
                                                    <span class="variant-card__label">التكلفة</span>
                                                    <input type="number" class="form-control form-control-sm" name="variant_cost_price[]" value="<?= $variantCost ?>" step="0.001" min="0">
                                                </div>
                                                <div class="variant-card__field">
                                                    <span class="variant-card__label">سعر البيع</span>
                                                    <input type="number" class="form-control form-control-sm variant-sell-price-input" name="variant_price1[]" value="<?= $variantPrice1 ?>" step="0.001" min="0">
                                                    <span class="variant-card__margin is-empty">هامش: —</span>
                                                </div>
                                                <div class="variant-card__actions">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary moveVariantUp" title="رفع"><i class="fas fa-arrow-up"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary moveVariantDown" title="خفض"><i class="fas fa-arrow-down"></i></button>
                                                        <button type="button" class="btn btn-outline-danger removeVariantRow" title="حذف"><i class="fas fa-times"></i></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                    <p class="variant-editor-footer mb-0">
                                        <i class="fas fa-info-circle ml-1"></i>
                                        عند إضافة تنوع يُخفى سعر الصنف الرئيسي — حدّد السعر والتكلفة لكل تنوع هنا. يظهر الصنف في الكاشير كاختيار وكل تنوع يُباع كصنف مستقل.
                                    </p>
                                </div>
                            </section>

                            <?php include __DIR__ . '/elements/sales/item_pricing_panel.php'; ?>

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
                        </div>

                        <aside class="item-summary-sidebar">
                            <div class="summary-head">
                                <div class="font-weight-bold">مراجعة سريعة</div>
                                <div class="small text-white-50 mt-1" id="summaryItemName"><?= $isEdit ? htmlspecialchars($rowitm['iname'], ENT_QUOTES, 'UTF-8') : 'صنف جديد' ?></div>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">سعر البيع</span>
                                <span class="summary-value" id="summarySellPrice">—</span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">التكلفة</span>
                                <span class="summary-value" id="summaryCost">—</span>
                            </div>
                            <div class="summary-line">
                                <span class="summary-label">هامش الربح</span>
                                <span class="summary-value summary-value--margin" id="summaryProfitMargin">—</span>
                            </div>
                        </aside>
                    </div>

                    <div class="item-editor-actions">
                        <div>
                            <a href="myitems.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times ml-1"></i> إلغاء
                            </a>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save ml-1"></i> <?= $isEdit ? 'حفظ التغييرات' : 'حفظ الصنف' ?>
                            </button>
                        </div>
                    </div>
                </div>

                </form>

                <div class="item-catalog-modal" id="itemCatalogGroupModal" aria-hidden="true">
                    <div class="item-catalog-modal__backdrop"></div>
                    <div class="item-catalog-modal__panel" role="dialog" aria-modal="true" aria-labelledby="itemCatalogGroupModalTitle">
                        <h4 class="item-catalog-modal__title" id="itemCatalogGroupModalTitle">إضافة جديدة</h4>
                        <input type="text" class="item-catalog-modal__input form-control" autocomplete="off">
                        <div class="item-catalog-modal__actions">
                            <button type="button" class="btn btn-light item-catalog-modal__cancel">إلغاء</button>
                            <button type="button" class="btn btn-primary item-catalog-modal__save">حفظ</button>
                        </div>
                    </div>
                </div>

                <div class="item-unit-modal" id="itemCatalogUnitModal" aria-hidden="true">
                    <div class="item-unit-modal__backdrop"></div>
                    <div class="item-unit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="itemCatalogUnitModalTitle">
                        <h4 class="item-unit-modal__title" id="itemCatalogUnitModalTitle">وحدة جديدة</h4>
                        <input type="text" class="item-unit-modal__input form-control" autocomplete="off" placeholder="مثال: كرتونة، كيلو، علبة...">
                        <div class="item-unit-modal__feedback" aria-live="polite"></div>
                        <div class="item-unit-modal__actions">
                            <button type="button" class="btn btn-light item-unit-modal__cancel">إلغاء</button>
                            <button type="button" class="btn btn-primary item-unit-modal__save">حفظ الوحدة</button>
                        </div>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <div class="modal fade" id="deleteItemModal" tabindex="-1" role="dialog" aria-labelledby="deleteItemModalLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content bg-danger">
                                <div class="modal-header">
                                    <h4 class="modal-title" id="deleteItemModalLabel">تحذير</h4>
                                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="إغلاق">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                </div>
                                <form action="do/dodel_item.php?id=<?= $editId ?>" method="post">
                                    <div class="modal-body">
                                        <p class="mb-3">هل تريد بالتأكيد حذف <strong><?= htmlspecialchars((string) ($rowitm['iname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>؟ لا يمكن التراجع عن هذا الإجراء.</p>
                                        <label for="delete-item-password" class="small mb-1">كلمة مرور الحذف</label>
                                        <input id="delete-item-password" type="password" class="form-control" name="password" placeholder="كلمة مرور الحذف" required autocomplete="off">
                                    </div>
                                    <div class="modal-footer justify-content-between">
                                        <button type="button" class="btn btn-outline-light" data-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-outline-light">
                                            <i class="fas fa-trash ml-1"></i> حذف
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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

    function refreshItemSummary() {
        $('#summaryItemName').text($.trim($('#iname').val() || '') || 'صنف جديد');
    }

    $(document).on('input', '#iname, input[name="barcode"]', refreshItemSummary);

    function nextVariantIndex() {
        return $('#variantRowsContainer .variant-row').length;
    }

    function variantRowHtml(index) {
        return `
            <div class="variant-card variant-row">
                <div class="variant-card__grid">
                    <div class="variant-card__field">
                        <span class="variant-card__label">النوع</span>
                        <input type="hidden" name="variant_link_id[]" value="0">
                        <input type="hidden" name="variant_item_id[]" value="0">
                        <input type="hidden" class="variant-sort-input" name="variant_sort[]" value="${index + 1}">
                        <input type="text" class="form-control form-control-sm variant-label-input" name="variant_label[]" value="" placeholder="صغير / كبير">
                    </div>
                    <div class="variant-card__field variant-card__field--wide">
                        <span class="variant-card__label">اسم الصنف الفرعي</span>
                        <input type="text" class="form-control form-control-sm variant-name-input" name="variant_name[]" value="" data-initial-child-name="" placeholder="يتولد تلقائياً من اسم الصنف">
                    </div>
                    <div class="variant-card__field">
                        <span class="variant-card__label">الباركود</span>
                        <input type="text" class="form-control form-control-sm" name="variant_barcode[]" value="" placeholder="اختياري">
                    </div>
                    <div class="variant-card__field">
                        <span class="variant-card__label">التكلفة</span>
                        <input type="number" class="form-control form-control-sm" name="variant_cost_price[]" value="0" step="0.001" min="0">
                    </div>
                    <div class="variant-card__field">
                        <span class="variant-card__label">سعر البيع</span>
                        <input type="number" class="form-control form-control-sm variant-sell-price-input" name="variant_price1[]" value="" step="0.001" min="0">
                        <span class="variant-card__margin is-empty">هامش: —</span>
                    </div>
                    <div class="variant-card__actions">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary moveVariantUp" title="رفع"><i class="fas fa-arrow-up"></i></button>
                            <button type="button" class="btn btn-outline-secondary moveVariantDown" title="خفض"><i class="fas fa-arrow-down"></i></button>
                            <button type="button" class="btn btn-outline-danger removeVariantRow" title="حذف"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function refreshVariantSorts() {
        $('#variantRowsContainer .variant-row').each(function(index) {
            $(this).find('.variant-sort-input').val(index + 1);
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

    function markVariantRowAutoNameState($row) {
        var label = $.trim($row.find('.variant-label-input').val() || '');
        var $nameInput = $row.find('.variant-name-input');
        var name = $.trim($nameInput.val() || '');
        var generated = generatedVariantName(label);
        var originalLabel = $.trim($row.attr('data-original-label') || label);
        var oldGenerated = generatedVariantName(originalLabel);
        var initialChildName = $.trim($nameInput.attr('data-initial-child-name') || '');
        var isStaleSavedName = initialChildName !== '' && name === initialChildName && name !== generated;
        if (name === '' || name === generated || name === oldGenerated || isStaleSavedName) {
            $row.attr('data-auto-name', '1');
            $row.attr('data-last-generated', generated);
            if (name === '' || name === oldGenerated || isStaleSavedName) {
                $nameInput.val(generated);
            }
            return;
        }
        $row.attr('data-auto-name', '0');
        $row.attr('data-last-generated', generated);
    }

    function syncVariantNamesBeforeSubmit() {
        $('#variantRowsContainer .variant-row').each(function() {
            var $row = $(this);
            var label = $.trim($row.find('.variant-label-input').val() || '');
            var name = $.trim($row.find('.variant-name-input').val() || '');
            var originalLabel = $.trim($row.attr('data-original-label') || '');
            if ($row.attr('data-auto-name') === '1' || name === '') {
                syncVariantNameFromLabel($row, true);
                return;
            }
            if (originalLabel !== '' && originalLabel !== label && name === generatedVariantName(originalLabel)) {
                $row.find('.variant-name-input').val(generatedVariantName(label));
            }
        });
    }

    function syncVariantNameFromLabel($row, forceUpdate) {
        var label = $.trim($row.find('.variant-label-input').val() || '');
        var $nameInput = $row.find('.variant-name-input');
        var currentName = $.trim($nameInput.val() || '');
        var lastGenerated = $.trim($row.attr('data-last-generated') || '');
        var initialChildName = $.trim($nameInput.attr('data-initial-child-name') || '');
        var isAuto = $row.attr('data-auto-name') === '1';
        var shouldUpdate = !!forceUpdate
            || currentName === ''
            || isAuto
            || (lastGenerated !== '' && currentName === lastGenerated)
            || (initialChildName !== '' && currentName === initialChildName);

        if (!shouldUpdate) {
            return;
        }

        var generated = generatedVariantName(label);
        $nameInput.val(generated);
        $row.attr('data-last-generated', generated);
        $row.attr('data-auto-name', '1');
    }

    function initializeVariantRowAutoNames() {
        $('#variantRowsContainer .variant-row').each(function() {
            markVariantRowAutoNameState($(this));
        });
    }

	    $('#addVariantRow').on('click', function() {
	        $('#variantRowsContainer').append(variantRowHtml(nextVariantIndex()));
	        syncVariantEditorVisibility();
	        refreshVariantSorts();
	        var $row = $('#variantRowsContainer .variant-row').last();
	        markVariantRowAutoNameState($row);
	        var $labelInput = $row.find('.variant-label-input');
	        if ($labelInput.length) {
	            $labelInput.trigger('focus');
	        }
	    });

    $(document).on('click', '.removeVariantRow', function() {
        $(this).closest('.variant-row').remove();
        refreshVariantSorts();
        syncVariantEditorVisibility();
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

    $(document).on('input', '.variant-label-input', function() {
        syncVariantNameFromLabel($(this).closest('.variant-row'), false);
    });

    $(document).on('input', '.variant-name-input', function() {
        var $row = $(this).closest('.variant-row');
        var name = $.trim($(this).val() || '');
        var generated = generatedVariantName($.trim($row.find('.variant-label-input').val() || ''));
        if (name === '' || name === generated) {
            $row.attr('data-auto-name', '1');
            $row.attr('data-last-generated', generated);
            return;
        }
        $row.attr('data-auto-name', '0');
    });

    $(document).on('input', '#iname', function() {
        $('#variantRowsContainer .variant-row').each(function() {
            if ($(this).attr('data-auto-name') === '1') {
                syncVariantNameFromLabel($(this), true);
            }
        });
    });

    function variantRowsForSubmit() {
        var rows = [];
        $('#variantRowsContainer .variant-row').each(function() {
            var label = $.trim($(this).find('.variant-label-input').val() || '');
            var name = $.trim($(this).find('.variant-name-input').val() || '');
            if (label === '' && name === '') {
                return;
            }
            rows.push($(this));
        });
        return rows;
    }

    function sellPricesAreValidForSubmit() {
        var validation = window.ItemEditorValidation;
        var variantRows = variantRowsForSubmit();
        if (variantRows.length > 0) {
            var invalidFields = [];
            variantRows.forEach(function($row) {
                var price = parseFloat($row.find('input[name="variant_price1[]"]').val());
                if (!isFinite(price) || price <= 0) {
                    invalidFields.push($row.find('.variant-sell-price-input'));
                }
            });
            if (invalidFields.length > 0) {
                if (validation) {
                    return validation.fail('يرجى إدخال سعر البيع لكل تنوع', invalidFields);
                }
                alert('يرجى إدخال سعر البيع لكل تنوع');
                return false;
            }
            return true;
        }

        if ($('#sell_active').length && $('#sell_active').val() !== '1') {
            return true;
        }

        var sellPrice = parseFloat($('#sell_price1').val());
        if (!isFinite(sellPrice) || sellPrice <= 0) {
            if (validation) {
                return validation.fail('يرجى إدخال سعر البيع', ['#sell_price1']);
            }
            alert('يرجى إدخال سعر البيع');
            return false;
        }
        return true;
    }

    function variationsAreValidForSubmit() {
        var validation = window.ItemEditorValidation;
        var valid = true;
        var seenLabels = [];
        $('#variantRowsContainer .variant-row').each(function() {
            var $row = $(this);
            var label = $.trim($row.find('.variant-label-input').val() || '');
            var name = $.trim($row.find('.variant-name-input').val() || '');
            if (label === '' && name === '') {
                return;
            }
            var key = label.toLowerCase();
            if (key !== '' && seenLabels.indexOf(key) !== -1) {
                if (validation) {
                    valid = validation.fail('لا يمكن تكرار نفس نوع التنوع', [$row.find('.variant-label-input')]);
                } else {
                    alert('لا يمكن تكرار نفس نوع التنوع');
                    valid = false;
                }
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
        syncVariantNamesBeforeSubmit();
        if (!variationsAreValidForSubmit()) {
            e.preventDefault();
        }
        if (!sellPricesAreValidForSubmit()) {
            e.preventDefault();
        }
    });

	    function syncVariantEditorVisibility() {
	        var hasRows = $('#variantRowsContainer .variant-row').length > 0;
	        $('#variantEditorBody').toggleClass('d-none', !hasRows);
	        $(document).trigger('itemEditorVariantsChanged', [hasRows]);
	    }

	    syncVariantEditorVisibility();
	    initializeVariantRowAutoNames();
	    refreshItemSummary();
	});
	</script>

<script>
$(function() {
    var params = new URLSearchParams(window.location.search);
    if (params.has('saved') || params.has('error')) {
        params.delete('saved');
        params.delete('error');
        var qs = params.toString();
        var cleanUrl = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
        history.replaceState(null, '', cleanUrl);
    }

    $('.item-editor-flash-alert').each(function() {
        var $alert = $(this);
        var delay = parseInt($alert.data('autodismiss-ms'), 10) || 10000;
        window.setTimeout(function() {
            $alert.fadeOut(300, function() {
                $alert.alert('close');
            });
        }, delay);
    });
});
</script>
<?php include('includes/footer.php') ?>
<script src="js/item_unit_picker.js?v=<?= (int) (@filemtime(__DIR__ . '/js/item_unit_picker.js') ?: 1) ?>"></script>
<script>
$(function () {
    if (typeof window.initItemUnitPickers === 'function') {
        window.initItemUnitPickers({
            saveUrl: 'ajax/item_catalog_unit_save.php',
            canCreate: <?= $canCreateUnits ? 'true' : 'false' ?>
        });
    }
});
</script>
<script src="js/item_unit_profile.js?v=<?= (int) (@filemtime(__DIR__ . '/js/item_unit_profile.js') ?: 1) ?>"></script>
<?php if (auth_guard_has_permission('menu.edit', $conn)): ?>
<script src="js/item_catalog_group_picker.js?v=<?= (int) (@filemtime(__DIR__ . '/js/item_catalog_group_picker.js') ?: 1) ?>"></script>
<script>
$(function () {
    if (typeof window.initItemCatalogGroupPickers === 'function') {
        window.initItemCatalogGroupPickers({
            saveUrl: 'ajax/item_catalog_group_save.php',
            canCreate: <?= $canCreateItemGroups ? 'true' : 'false' ?>
        });
    }
});
</script>
<?php endif; ?>
