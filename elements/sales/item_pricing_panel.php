<?php
/** @var array $unitProfile */
/** @var bool $hideParentPricingSection */

$profile = $unitProfile;
$directCostPrice = (float) ($profile['direct_cost_price'] ?? 0);
$costPerUnit = (float) ($profile['cost_per_unit'] ?? 0);
if ($costPerUnit <= 0) {
    $costPerUnit = $directCostPrice;
}
$visibleCost = $costPerUnit > 0 ? $costPerUnit : $directCostPrice;
?>
<section class="item-editor-panel<?= !empty($hideParentPricingSection) ? ' d-none' : '' ?>" id="item-pricing-section" data-section="pricing">
    <div class="item-editor-panel-header">
        <div>
            <h3 class="item-editor-panel-title">4. السعر والتكلفة</h3>
            <p class="item-editor-panel-subtitle">سعر البيع والتكلفة المباشرة للصنف (بدون تنوعات).</p>
        </div>
    </div>
    <div class="item-editor-panel-body item-pricing-body" id="item-pricing-body">
        <div class="item-pricing-fields" id="sell-price-cost-row">
            <div class="form-group mb-0">
                <label for="sell_price1">سعر البيع</label>
                <input type="number" name="sell_price1" id="sell_price1" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($profile['sell_price1'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
            </div>
            <div class="form-group mb-0">
                <label for="direct_cost_price">التكلفة</label>
                <input type="number" name="direct_cost_price" id="direct_cost_price" class="form-control form-control-sm" value="<?= htmlspecialchars($visibleCost > 0 ? (string) $visibleCost : '', ENT_QUOTES, 'UTF-8') ?>" step="0.001" min="0">
            </div>
        </div>
        <div class="item-pricing-margin" id="sell-margin-row">
            <span class="item-pricing-margin__label">هامش الربح</span>
            <span class="item-pricing-margin__value is-empty" id="sell_profit_margin">—</span>
        </div>
        <small class="form-text text-muted d-none" id="pricing-optional-hint">سعر البيع اختياري للمواد الخام — اتركه فارغاً إذا كانت المادة لا تُباع مباشرة.</small>
    </div>
</section>
