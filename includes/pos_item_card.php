<?php

function pos_item_card_fallback_icon(string $itemName): string
{
    if (strpos($itemName, 'قهوة') !== false || strpos($itemName, 'لاتيه') !== false || strpos($itemName, 'كابتشينو') !== false) {
        return 'fa-mug-hot';
    }

    if (strpos($itemName, 'شاي') !== false || strpos($itemName, 'عصير') !== false || strpos($itemName, 'مياه') !== false) {
        return 'fa-tint';
    }

    if (strpos($itemName, 'كرواسون') !== false || strpos($itemName, 'خبز') !== false) {
        return 'fa-bread-slice';
    }

    return 'fa-utensils';
}

function pos_item_card_quantity_label($value): string
{
    $number = (float) $value;
    if (abs($number - round($number)) < 0.000001) {
        return (string) (int) round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function pos_item_card_availability_badge(array $rowitem): string
{
    $status = (string) ($rowitem['availability_status'] ?? 'available');
    if ($status === 'recipe_low') {
        $qty = pos_item_card_quantity_label($rowitem['recipe_effective_available_qty'] ?? 0);
        return '<span class="badge bg-warning text-dark pos-item-availability-badge">متبقي ' . htmlspecialchars($qty, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    if (empty($rowitem['is_available'])) {
        $label = $status === 'recipe_unavailable' ? 'غير متاح' : 'مخفي';
        return '<span class="badge bg-danger pos-item-availability-badge">' . $label . '</span>';
    }

    return '';
}

function pos_render_item_card(array $rowitem): string
{
    $itemId = isset($rowitem['id']) ? (int) $rowitem['id'] : 0;
    $itemNameRaw = isset($rowitem['iname']) ? (string) $rowitem['iname'] : 'صنف غير محدد';
    $itemName = htmlspecialchars($itemNameRaw, ENT_QUOTES, 'UTF-8');

    $itemPrice = 0.0;
    if (isset($rowitem['price1']) && $rowitem['price1'] !== '') {
        $itemPrice = (float) $rowitem['price1'];
    } elseif (isset($rowitem['price']) && $rowitem['price'] !== '') {
        $itemPrice = (float) $rowitem['price'];
    }

    $itemBarcode = htmlspecialchars((string) ($rowitem['barcode'] ?? ''), ENT_QUOTES, 'UTF-8');
    $itemCategory = htmlspecialchars((string) ($rowitem['group1'] ?? ''), ENT_QUOTES, 'UTF-8');
    $itemDesc = htmlspecialchars((string) ($rowitem['info'] ?? ''), ENT_QUOTES, 'UTF-8');
    $isAvailable = !array_key_exists('is_available', $rowitem) || (int) $rowitem['is_available'] === 1;
    $canAdd = array_key_exists('availability_can_add', $rowitem) ? (bool) $rowitem['availability_can_add'] : $isAvailable;
    $availabilityStatus = htmlspecialchars((string) ($rowitem['availability_status'] ?? ($isAvailable ? 'available' : 'manual_unavailable')), ENT_QUOTES, 'UTF-8');
    $unavailableReasonRaw = (string) ($rowitem['unavailable_reason'] ?? $rowitem['recipe_unavailable_reason'] ?? '');
    $unavailableReason = htmlspecialchars($unavailableReasonRaw, ENT_QUOTES, 'UTF-8');
    $requiresManagerOverride = !empty($rowitem['availability_requires_manager_override']);
    $overrideAllowed = !empty($rowitem['availability_override_allowed']);
    $overridePermission = htmlspecialchars((string) ($rowitem['availability_override_permission'] ?? ''), ENT_QUOTES, 'UTF-8');
    $recipeEnabled = !empty($rowitem['recipe_enabled']);
    $recipeQty = htmlspecialchars((string) ($rowitem['recipe_effective_available_qty'] ?? ''), ENT_QUOTES, 'UTF-8');
    $recipeRevision = htmlspecialchars((string) ($rowitem['recipe_availability_revision'] ?? ''), ENT_QUOTES, 'UTF-8');
    $hasVariants = !empty($rowitem['has_variants']) || !empty($rowitem['has_active_variants']);
    $variantDataAttribute = '';
    if ($hasVariants && isset($rowitem['variants']) && is_array($rowitem['variants'])) {
        $variantJson = json_encode($rowitem['variants'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($variantJson)) {
            $variantDataAttribute = ' data-variants="' . htmlspecialchars($variantJson, ENT_QUOTES, 'UTF-8') . '"';
        }
    }
    $itemImage = '';
    if (!empty($rowitem['img_filename'])) {
        $itemImage = 'uploads/' . htmlspecialchars((string) $rowitem['img_filename'], ENT_QUOTES, 'UTF-8');
    }

    $fallbackIcon = pos_item_card_fallback_icon($itemNameRaw);
    $availabilityBadge = pos_item_card_availability_badge($rowitem);
    $cardClasses = 'card item-card itemButton pos-menu-card shadow-sm border-0';
    if (!$isAvailable) {
        $cardClasses .= ' item-unavailable';
    } elseif (!empty($rowitem['availability_low_stock'])) {
        $cardClasses .= ' item-low-stock';
    }
    $cardStyle = 'transition: all 0.3s ease;';
    if (!$isAvailable) {
        $cardStyle .= ' opacity: 0.58;';
    }

    ob_start();
    ?>
    <div class="col-xxl-2 col-xl-3 col-lg-4 col-md-4 col-sm-6 item-wrapper"
        data-category="<?= $itemCategory ?>">
        <div class="<?= $cardClasses ?>"
            data-item-id="<?= $itemId ?>" data-item-name="<?= $itemName ?>"
            data-item-price="<?= $itemPrice ?>" data-item-barcode="<?= $itemBarcode ?>"
            data-item-desc="<?= $itemDesc ?>"
            data-is-available="<?= $isAvailable ? '1' : '0' ?>"
            data-availability-can-add="<?= $canAdd ? '1' : '0' ?>"
            data-availability-status="<?= $availabilityStatus ?>"
            data-unavailable-reason="<?= $unavailableReason ?>"
            data-requires-manager-override="<?= $requiresManagerOverride ? '1' : '0' ?>"
            data-override-allowed="<?= $overrideAllowed ? '1' : '0' ?>"
            data-override-permission="<?= $overridePermission ?>"
            data-recipe-enabled="<?= $recipeEnabled ? '1' : '0' ?>"
            data-recipe-effective-available-qty="<?= $recipeQty ?>"
            data-recipe-availability-revision="<?= $recipeRevision ?>"
            data-has-variants="<?= $hasVariants ? '1' : '0' ?>"
            <?= $variantDataAttribute ?>
            aria-disabled="<?= $canAdd ? 'false' : 'true' ?>"
            title="<?= !$isAvailable && $unavailableReason !== '' ? $unavailableReason : $itemName ?>"
            style="<?= $cardStyle ?>">
            <div class="card-body p-2 text-center">
                <?php if ($availabilityBadge !== ''): ?>
                    <div class="text-end mb-1"><?= $availabilityBadge ?></div>
                <?php endif; ?>
                <div class="item-image-container mb-2 ratio ratio-1x1 overflow-hidden"
                    style="cursor: pointer; background: #f8f9fa;">
                    <?php if (!empty($itemImage) && file_exists(__DIR__ . '/../' . html_entity_decode($itemImage, ENT_QUOTES, 'UTF-8'))): ?>
                    <img src="<?= $itemImage ?>"
                        class="item-image-click object-fit-cover w-100 h-100"
                        style="width: 100%; height: 100%;">
                    <?php else: ?>
                    <div
                        class="d-flex align-items-center justify-content-center item-image-click pos-item-fallback">
                        <i class="fas <?= $fallbackIcon ?> fa-3x"></i>
                    </div>
                    <?php endif; ?>
                </div>

                <h6 class="card-title text-truncate mb-1" style="font-size: 0.85rem;"
                    title="<?= $itemName ?>">
                    <?= $itemName ?>
                </h6>

                <div class="pos-item-footer">
                    <p class="card-text fw-bold pos-item-price mb-0">
                        <?php if ($hasVariants): ?>
                            <i class="fas fa-list-ul ml-1"></i>اختيارات
                        <?php else: ?>
                            <?= number_format($itemPrice, 2) ?> <span>ج.م</span>
                        <?php endif; ?>
                    </p>
                </div>

                <button class="btn btn-outline-primary btn-sm w-100 item-details-btn"
                    style="font-size: 0.75rem;">
                    <i class="fas fa-info-circle me-1"></i>التفاصيل
                </button>
            </div>
        </div>
    </div>
    <?php
    return trim(ob_get_clean());
}
