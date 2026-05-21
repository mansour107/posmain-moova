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
    $itemImage = '';
    if (!empty($rowitem['img_filename'])) {
        $itemImage = 'uploads/' . htmlspecialchars((string) $rowitem['img_filename'], ENT_QUOTES, 'UTF-8');
    }

    $fallbackIcon = pos_item_card_fallback_icon($itemNameRaw);

    ob_start();
    ?>
    <div class="col-xxl-2 col-xl-3 col-lg-4 col-md-4 col-sm-6 item-wrapper"
        data-category="<?= $itemCategory ?>">
        <div class="card item-card itemButton pos-menu-card shadow-sm border-0"
            data-item-id="<?= $itemId ?>" data-item-name="<?= $itemName ?>"
            data-item-price="<?= $itemPrice ?>" data-item-barcode="<?= $itemBarcode ?>"
            data-item-desc="<?= $itemDesc ?>" style="transition: all 0.3s ease;">
            <div class="card-body p-2 text-center">
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
                        <?= number_format($itemPrice, 2) ?> <span>ج.م</span>
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

