<?php
/** @var array $unitProfile */
/** @var array $unitOptions */
/** @var string $itemType */
/** @var int $trackStock */
/** @var bool $isEdit */

$profile = $unitProfile;
$sellActive = !empty($profile['sell_active']);
$purchaseActive = !empty($profile['purchase_active']);
$sellUnitId = (int) ($profile['sell_unit_id'] ?? 0);
$storageUnitId = (int) ($profile['storage_unit_id'] ?? 0);
$purchaseUnitId = (int) ($profile['purchase_unit_id'] ?? 0);

function posmain_unit_profile_options(array $unitOptions, int $selectedId): string
{
    $html = '';
    foreach ($unitOptions as $unit) {
        $id = (int) $unit['id'];
        $name = htmlspecialchars((string) $unit['uname'], ENT_QUOTES, 'UTF-8');
        $selected = $id === $selectedId ? ' selected' : '';
        $html .= "<option value=\"{$id}\"{$selected}>{$name}</option>";
    }

    return $html;
}
?>
<input type="hidden" name="item_unit_profile_present" value="1">
<input type="hidden" name="sell_active" id="sell_active" value="<?= $sellActive ? '1' : '0' ?>">
<input type="hidden" name="purchase_active" id="purchase_active" value="<?= $purchaseActive ? '1' : '0' ?>">

<section class="item-editor-panel item-type-panel" id="item-type-section">
    <div class="item-editor-panel-header">
        <div>
            <h3 class="item-editor-panel-title">2. نوع الصنف</h3>
            <p class="item-editor-panel-subtitle">يحدد حقول البيع والشراء والتخزين أدناه.</p>
        </div>
    </div>
    <div class="item-editor-panel-body">
        <div class="item-type-options mb-0">
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
    </div>
</section>

<section class="item-editor-panel" id="item-sell-section" data-section="sell">
    <div class="item-unit-profile-section">
        <div class="item-unit-profile-section__header">
            <div>
                <h4 class="item-unit-profile-section__title">البيع</h4>
                <p class="item-unit-profile-section__subtitle">وحدة البيع والأسعار الظاهرة في الكاشير.</p>
            </div>
            <label class="item-unit-profile-section__toggle d-none" id="sell-section-toggle-wrap">
                <input type="checkbox" id="sell_section_checkbox" <?= $sellActive ? 'checked' : '' ?>>
                <span>تفعيل</span>
            </label>
        </div>
        <div class="item-unit-profile-section__body" id="sell-section-body">
            <div class="item-unit-profile-field-row">
                <div class="form-group mb-0">
                    <label for="sell_unit_id">وحدة البيع</label>
                    <select name="sell_unit_id" id="sell_unit_id" class="form-control form-control-sm item-profile-unit-select">
                        <?= posmain_unit_profile_options($unitOptions, $sellUnitId) ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="sell_barcode" value="<?= htmlspecialchars((string) ($profile['sell_barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="item-unit-profile-prices">
                <div class="form-group mb-0">
                    <label for="sell_price1">سعر البيع</label>
                    <input type="number" name="sell_price1" id="sell_price1" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['sell_price1'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
                </div>
                <div class="form-group mb-0">
                    <label for="sell_price2">سعر خاص (اختياري)</label>
                    <input type="number" name="sell_price2" id="sell_price2" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['sell_price2'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
                </div>
                <div class="form-group mb-0">
                    <label for="sell_market_price">سعر السوق (اختياري)</label>
                    <input type="number" name="sell_market_price" id="sell_market_price" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['sell_market_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
                </div>
            </div>
        </div>
    </div>
</section>

<section class="item-editor-panel" id="item-purchase-section" data-section="purchase">
    <div class="item-unit-profile-section <?= $purchaseActive ? '' : 'is-disabled is-activatable' ?>" id="purchase-section-card">
        <div class="item-unit-profile-section__header" id="purchase-section-header">
            <div>
                <h4 class="item-unit-profile-section__title">شراء وتخزين</h4>
                <p class="item-unit-profile-section__subtitle">وحدات العد والشراء وتكلفة المورد.</p>
            </div>
            <label class="item-unit-profile-section__toggle">
                <input type="checkbox" id="purchase_section_checkbox" <?= $purchaseActive ? 'checked' : '' ?>>
                <span>تفعيل</span>
            </label>
        </div>
        <div class="item-unit-profile-section__body" id="purchase-section-body">
            <div class="item-unit-profile-field-row">
                <div class="form-group mb-0">
                    <label for="storage_unit_id">وحدة التخزين / العد</label>
                    <select name="storage_unit_id" id="storage_unit_id" class="form-control form-control-sm item-profile-unit-select">
                        <?= posmain_unit_profile_options($unitOptions, $storageUnitId) ?>
                    </select>
                </div>
            </div>
            <div class="item-unit-conversion d-none" id="sell-storage-conversion" data-conversion="sell-storage">
                <span class="item-unit-conversion__label">العلاقة بين البيع والتخزين</span>
                <span>1</span>
                <span class="item-unit-conversion__unit" data-role="left-unit">—</span>
                <span>=</span>
                <input type="number" class="form-control form-control-sm item-unit-conversion__factor" name="sell_storage_factor" id="sell_storage_factor" value="<?= htmlspecialchars((string) ($profile['sell_storage_factor'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0.001">
                <span class="item-unit-conversion__unit" data-role="right-unit">—</span>
                <button type="button" class="item-unit-conversion__swap" title="عكس الاتجاه" data-swap-target="sell-storage"><i class="fas fa-exchange-alt"></i></button>
            </div>
            <div class="item-unit-profile-field-row" id="purchase-only-fields">
                <div class="form-group mb-0">
                    <label for="purchase_unit_id">وحدة الشراء</label>
                    <select name="purchase_unit_id" id="purchase_unit_id" class="form-control form-control-sm item-profile-unit-select">
                        <?= posmain_unit_profile_options($unitOptions, $purchaseUnitId) ?>
                    </select>
                </div>
                <div class="form-group mb-0">
                    <label for="purchase_cost">تكلفة الشراء للوحدة</label>
                    <input type="number" name="purchase_cost" id="purchase_cost" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['purchase_cost'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
                </div>
                <div class="form-group mb-0">
                    <label for="purchase_barcode">باركود الشراء (اختياري)</label>
                    <input type="text" name="purchase_barcode" id="purchase_barcode" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['purchase_barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="item-unit-conversion d-none" id="purchase-storage-conversion" data-conversion="purchase-storage">
                <span class="item-unit-conversion__label">العلاقة بين الشراء والتخزين</span>
                <span>1</span>
                <span class="item-unit-conversion__unit" data-role="left-unit">—</span>
                <span>=</span>
                <input type="number" class="form-control form-control-sm item-unit-conversion__factor" name="purchase_storage_factor" id="purchase_storage_factor" value="<?= htmlspecialchars((string) ($profile['purchase_storage_factor'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0.001">
                <span class="item-unit-conversion__unit" data-role="right-unit">—</span>
                <button type="button" class="item-unit-conversion__swap" title="عكس الاتجاه" data-swap-target="purchase-storage"><i class="fas fa-exchange-alt"></i></button>
            </div>
            <p class="text-muted small mb-0" id="unitImpactPreview">عند استلام مشتريات تُحوَّل الكميات تلقائياً إلى وحدة التخزين.</p>
        </div>
    </div>
</section>

<section class="item-editor-panel" id="item-inventory-section">
    <div class="item-editor-panel-header">
        <div>
            <h3 class="item-editor-panel-title">إعدادات المخزون</h3>
            <p class="item-editor-panel-subtitle">متابعة الرصيد في المخزون.</p>
        </div>
    </div>
    <div class="item-editor-panel-body">
        <input type="hidden" name="track_stock" value="0">
        <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="track_stock" name="track_stock" value="1" <?= $trackStock ? 'checked' : '' ?>>
            <label class="custom-control-label" for="track_stock">خصم/متابعة الرصيد في المخزون</label>
        </div>
        <small class="form-text text-muted">الخدمات تحفظ بدون مخزون. وحدة التخزين تُحدَّد في قسم شراء وتخزين.</small>
    </div>
</section>
