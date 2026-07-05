<?php
/** @var array $unitProfile */
/** @var string $itemType */
/** @var int $trackStock */
/** @var int $defaultUnitId */

$profile = $unitProfile;
$sellActive = !empty($profile['sell_active']);
$purchaseActive = false;
$sellUnitId = (int) ($profile['sell_unit_id'] ?? 0);
$storageUnitId = (int) ($profile['storage_unit_id'] ?? 0);
$purchaseUnitId = (int) ($profile['purchase_unit_id'] ?? 0);
$resolvedDefaultUnit = (int) ($defaultUnitId ?? 0);
if ($sellUnitId < 1) {
    $sellUnitId = $resolvedDefaultUnit;
}
if ($storageUnitId < 1) {
    $storageUnitId = $resolvedDefaultUnit;
}
if ($purchaseUnitId < 1) {
    $purchaseUnitId = $resolvedDefaultUnit;
}
$directCostPrice = (float) ($profile['direct_cost_price'] ?? 0);
$costPerUnit = (float) ($profile['cost_per_unit'] ?? 0);
if ($costPerUnit <= 0) {
    $costPerUnit = $directCostPrice;
}
$visibleCost = $costPerUnit > 0 ? $costPerUnit : $directCostPrice;
?>
<input type="hidden" name="item_unit_profile_present" value="1">
<input type="hidden" name="sell_active" id="sell_active" value="<?= $sellActive ? '1' : '0' ?>">
<input type="hidden" name="purchase_active" id="purchase_active" value="0">
<input type="hidden" name="cost_source" id="cost_source" value="direct">
<input type="hidden" name="sell_unit_id" id="sell_unit_id" value="<?= $sellUnitId > 0 ? (int) $sellUnitId : '' ?>">
<input type="hidden" name="storage_unit_id" id="storage_unit_id" value="<?= $storageUnitId > 0 ? (int) $storageUnitId : '' ?>">
<input type="hidden" name="purchase_unit_id" id="purchase_unit_id" value="<?= $purchaseUnitId > 0 ? (int) $purchaseUnitId : '' ?>">
<input type="hidden" name="sell_storage_factor" id="sell_storage_factor" value="1">
<input type="hidden" name="sell_storage_swapped" id="sell_storage_swapped" value="0">
<input type="hidden" name="purchase_storage_factor" id="purchase_storage_factor" value="1">
<input type="hidden" name="purchase_storage_swapped" id="purchase_storage_swapped" value="0">
<input type="hidden" name="purchase_cost" id="purchase_cost" value="">
<input type="hidden" name="purchase_barcode" id="purchase_barcode" value="">
<input type="hidden" name="recipe_cost_price" id="recipe_cost_price" value="0">
<input type="hidden" name="sell_barcode" value="<?= htmlspecialchars((string) ($profile['sell_barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="track_stock" id="track_stock" value="<?= $trackStock ? '1' : '0' ?>">

<section class="item-editor-panel item-type-panel" id="item-type-section">
    <div class="item-editor-panel-header">
        <div>
            <h3 class="item-editor-panel-title">2. نوع الصنف</h3>
            <p class="item-editor-panel-subtitle">يحدد إعدادات البيع والمخزون للصنف.</p>
        </div>
    </div>
    <div class="item-editor-panel-body">
        <div class="item-type-options mb-0">
            <button type="button" class="item-type-choice <?= $itemType === 'service' ? 'active' : '' ?>" data-item-type="service">
                <span class="item-type-choice__name">خدمة</span>
                <span class="item-type-choice__example">مثل: رسوم توصيل</span>
            </button>
            <button type="button" class="item-type-choice <?= $itemType === 'made' ? 'active' : '' ?>" data-item-type="made">
                <span class="item-type-choice__name">يصنع من مكونات</span>
                <span class="item-type-choice__example">مثل: بيتزا</span>
            </button>
            <button type="button" class="item-type-choice <?= $itemType === 'sellable' ? 'active' : '' ?>" data-item-type="sellable">
                <span class="item-type-choice__name">يباع كما هو</span>
                <span class="item-type-choice__example">مثل: مياه معدنية</span>
            </button>
            <button type="button" class="item-type-choice <?= $itemType === 'ingredient' ? 'active' : '' ?>" data-item-type="ingredient">
                <span class="item-type-choice__name">مادة خام</span>
                <span class="item-type-choice__example">مثل: دقيق</span>
            </button>
        </div>
        <select id="item_type" name="item_type" class="form-control d-none">
            <option value="service" <?= $itemType === 'service' ? 'selected' : '' ?>>Service</option>
            <option value="made" <?= $itemType === 'made' ? 'selected' : '' ?>>Made</option>
            <option value="sellable" <?= $itemType === 'sellable' ? 'selected' : '' ?>>Sellable</option>
            <option value="ingredient" <?= $itemType === 'ingredient' ? 'selected' : '' ?>>Ingredient</option>
            <?php if ($itemType === 'packaging') { ?>
                <option value="packaging" selected>Packaging</option>
            <?php } ?>
        </select>
    </div>
</section>
