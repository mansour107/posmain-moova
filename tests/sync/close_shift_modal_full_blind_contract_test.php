<?php

function fullBlindCloseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "close-shift-modal-full-blind-fail: {$message}\n");
        exit(1);
    }
}

/** Extract only the close modal so unrelated POS cash-management UI is allowed. */
function closeModalSlice(string $source, string $endMarker): string
{
    $start = strpos($source, 'id="closeShiftModal"');
    fullBlindCloseAssert($start !== false, 'closeShiftModal must exist');
    $end = strpos($source, $endMarker, $start);
    fullBlindCloseAssert($end !== false, 'close modal end marker must exist');

    return substr($source, $start, $end - $start);
}

$restaurant = file_get_contents(__DIR__ . '/../../includes/pos_content.php');
$supermarket = file_get_contents(__DIR__ . '/../../includes/pos_supermarket_content.php');
$wizard = file_get_contents(__DIR__ . '/../../js/pos_shift_count_wizard.js');

fullBlindCloseAssert($restaurant !== false, 'restaurant POS source readable');
fullBlindCloseAssert($supermarket !== false, 'supermarket POS source readable');
fullBlindCloseAssert($wizard !== false, 'count wizard source readable');

$restaurantModal = closeModalSlice($restaurant, '<!-- Modal الدليفري -->');
$supermarketModal = closeModalSlice($supermarket, '<!-- Scripts -->');

foreach (['restaurant' => $restaurantModal, 'supermarket' => $supermarketModal] as $surface => $modal) {
    fullBlindCloseAssert(
        strpos($modal, 'data-testid="close-shift-guidance"') !== false,
        "{$surface} close modal must provide neutral count guidance"
    );
    foreach ([
        'عدّ أعمى',
        'كل الأرقام مخفية',
        'دون الرجوع إلى المبيعات',
        'لن تظهر أرقام المبيعات',
    ] as $policyCopy) {
        fullBlindCloseAssert(
            strpos($modal, $policyCopy) === false,
            "{$surface} close modal must not announce the blind-count policy"
        );
    }
    foreach ([
        'id="shiftPreview"',
        'get_shift_preview.php',
        'إجمالي المبيعات',
        'عدد الطلبات',
        'النقدية المتوقعة',
        'إيداعات الشيفت',
        'مصروفات الشيفت',
        'z_report.php',
        'printShiftSalesReport',
    ] as $leak) {
        fullBlindCloseAssert(strpos($modal, $leak) === false, "{$surface} close modal must not expose {$leak}");
    }
}

fullBlindCloseAssert(
    strpos($wizard, 'loadShiftPreview') === false,
    'opening the close wizard must not request a financial preview'
);
fullBlindCloseAssert(
    strpos($wizard, 'do_begin_shift_close_count.php') !== false,
    'count token must still begin through the controlled close-count endpoint'
);

echo "close-shift-modal-full-blind-ok\n";
