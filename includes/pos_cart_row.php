<?php

require_once __DIR__ . '/../classes/Financial/Money.php';
require_once __DIR__ . '/../classes/Financial/RoundingPolicy.php';
require_once __DIR__ . '/../classes/Recipe/RecipeDecimal.php';

/**
 * Shared cart row markup for POS premium dark UI.
 */
function pos_render_cart_row(array $row): string
{
    $itemId = (int) ($row['item_id'] ?? 0);
    $itemName = htmlspecialchars((string) ($row['item_name'] ?? 'صنف غير معروف'), ENT_QUOTES, 'UTF-8');
    $qtyDecimal = RecipeDecimal::normalize($row['qty'] ?? '1');
    $qty = htmlspecialchars($qtyDecimal, ENT_QUOTES, 'UTF-8');
    $price = RecipeDecimal::normalize($row['price'] ?? '0');
    $subtotal = array_key_exists('subtotal', $row)
        ? Money::from($row['subtotal'])->toString()
        : Money::from(RoundingPolicy::halfUp(bcmul($price, $qtyDecimal, 12)))->toString();
    $barcode = htmlspecialchars((string) ($row['barcode'] ?? (string) $itemId), ENT_QUOTES, 'UTF-8');
    $lineNote = htmlspecialchars((string) ($row['line_note'] ?? ''), ENT_QUOTES, 'UTF-8');
    $preparationValues = is_array($row['preparation_values'] ?? null) ? $row['preparation_values'] : [];
    $sugarSpoons = null;
    foreach ($preparationValues as $preparationValue) {
        if (($preparationValue['code'] ?? $preparationValue['field_code'] ?? '') === 'sugar_spoons') {
            $sugarSpoons = max(
                0,
                min(
                    PreparationSelectionService::SUGAR_SPOONS_SAFETY_LIMIT,
                    (int) ($preparationValue['value'] ?? $preparationValue['selected_value'] ?? 0)
                )
            );
            break;
        }
    }
    if ($sugarSpoons === null && !empty($row['sugar_allowed'])) {
        $sugarSpoons = 0;
    }
    $preparationJson = htmlspecialchars(
        json_encode($sugarSpoons === null ? [] : [['code' => 'sugar_spoons', 'value' => $sugarSpoons]], JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );
    $uVal = htmlspecialchars((string) ($row['u_val'] ?? '1'), ENT_QUOTES, 'UTF-8');
    $priceFormatted = rtrim(rtrim($price, '0'), '.') ?: '0';
    $subtotalFormatted = $subtotal;
    $persisted = !empty($row['persisted']);
    $persistedQty = htmlspecialchars((string) ($row['persisted_qty'] ?? $qty), ENT_QUOTES, 'UTF-8');
    $persistedAttrs = $persisted
        ? ' data-persisted-line="1" data-persisted-qty="' . $persistedQty . '" data-catalog-price="' . htmlspecialchars($priceFormatted, ENT_QUOTES, 'UTF-8') . '"'
        : ' data-catalog-price="' . htmlspecialchars($priceFormatted, ENT_QUOTES, 'UTF-8') . '"';

    ob_start();
    ?>
    <div class="item-card-order pos-cart-row" data-itemid="<?= $barcode ?>"<?= $persistedAttrs ?>>
        <div class="pos-cart-row-inner">
            <div class="pos-cart-price-display" aria-hidden="true"><?= $subtotalFormatted ?> <span class="pos-currency">ج.م</span></div>
            <div class="pos-cart-qty">
                <button type="button" class="btn qty-step qty-decrease" title="تقليل">−</button>
                <input type="number"
                    class="form-control form-control-sm text-center quantityInput nozero fw-bold"
                    value="<?= $qty ?>"
                    name="itmqty[]"
                    min="1"
                    step="1"
                    title="الكمية">
                <button type="button" class="btn qty-step qty-increase" title="زيادة">+</button>
                <input type="hidden" name="u_val[]" value="<?= $uVal ?>">
            </div>
            <div class="pos-cart-main">
                <input type="hidden" value="<?= $itemId ?>" name="itmname[]">
                <input type="hidden" class="barcode" value="<?= $barcode ?>">
                <div class="pos-cart-name" title="<?= $itemName ?>"><?= $itemName ?></div>
                <?php if ($sugarSpoons !== null): ?>
                    <small class="pos-cart-preparation text-muted">السكر: <?= $sugarSpoons === 0 ? 'بدون' : $sugarSpoons . ' ملعقة' ?></small>
                <?php endif; ?>
                <input type="hidden" class="preparationValuesInput" name="itmpreparation[]" value="<?= $preparationJson ?>">
                <input type="hidden" class="lineNoteInput" name="itmnote[]" value="<?= $lineNote ?>">
                <input type="hidden" class="managerApprovalInput" name="itmmanagerapproval[]" value="">
            </div>
            <div class="pos-cart-note">
                <button type="button"
                    class="btn lineNoteButton <?= $lineNote !== '' ? 'line-note-has-value' : 'line-note-empty' ?>"
                    title="إضافة ملاحظة للمطبخ"
                    aria-label="إضافة ملاحظة للمطبخ">
                    <i class="fas fa-sticky-note"></i>
                </button>
            </div>
            <button type="button" class="btn delRow" title="حذف" aria-label="حذف">
                <i class="fas fa-times"></i>
            </button>
            <div class="pos-cart-value d-none">
                <input type="hidden" name="itmdisc[]" value="0">
                <input type="text"
                    class="form-control form-control-sm text-center subtotal fw-bold"
                    readonly
                    value="<?= $subtotalFormatted ?>"
                    name="itmval[]"
                    title="القيمة">
            </div>
            <div class="pos-cart-price d-none">
                <input type="number"
                    class="form-control form-control-sm text-center priceInput nozero"
                    value="<?= $priceFormatted ?>"
                    name="itmprice[]"
                    step="0.01"
                    title="السعر">
            </div>
        </div>
    </div>
    <?php
    return trim(ob_get_clean());
}
