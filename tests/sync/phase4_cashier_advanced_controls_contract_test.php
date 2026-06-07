<?php

$sourcePath = __DIR__ . '/../../includes/pos_content.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read includes/pos_content.php');
}

phase4CashierUxAssert(strpos($source, 'id="posAdvancedSetup"') !== false, 'advanced setup fields should remain in the form');
phase4CashierUxAssert(strpos($source, 'data-bs-toggle="collapse" data-bs-target="#posAdvancedSetup"') === false, 'empty advanced setup trigger should not be shown');
phase4CashierUxAssert(strpos($source, 'إعدادات متقدمة') === false, 'empty advanced setup Arabic label should not be shown');
phase4CashierUxAssert(strpos($source, 'pos-table-visible-control') !== false, 'current table control should remain visible');
phase4CashierUxAssert(strpos($source, 'id="selected_table_display"') !== false, 'selected table display should remain');
phase4CashierUxAssert(strpos($source, 'id="searchInput"') !== false, 'search input should remain visible');
phase4CashierUxAssert(strpos($source, 'id="barcodeInput"') !== false, 'barcode input should remain visible');
phase4CashierUxAssert(strpos($source, 'name="pro_date"') !== false, 'pro_date field should remain submitted');
phase4CashierUxAssert(strpos($source, 'name="accural_date"') !== false, 'accural_date field should remain submitted');
phase4CashierUxAssert(strpos($source, 'name="store_id"') !== false, 'store_id field should remain submitted');
phase4CashierUxAssert(strpos($source, 'name="emp_id"') !== false, 'emp_id field should remain submitted');
phase4CashierUxAssert(strpos($source, 'name="acc2_id"') !== false, 'customer field should remain submitted');
phase4CashierUxAssert(strpos($source, 'name="fund_id"') !== false, 'fund field should remain submitted');
phase4CashierUxAssert(strpos($source, 'required') !== false, 'required constraints should remain');
phase4CashierUxAssert(strpos($source, 'pos-mode-tab') !== false, 'order type tabs should remain');
phase4CashierUxAssert(strpos($source, 'data-bs-target="#paymentModal"') !== false, 'payment modal trigger should remain');

$advancedPos = strpos($source, 'id="posAdvancedSetup"');
$storePos = strpos($source, 'name="store_id"');
$fundPos = strpos($source, 'name="fund_id"');
$itemsPos = strpos($source, '<!-- الأصناف المُضافة -->');
phase4CashierUxAssert($advancedPos !== false && $storePos > $advancedPos && $fundPos > $advancedPos, 'accounting controls should be inside advanced area after collapse marker');
phase4CashierUxAssert($itemsPos !== false && $fundPos < $itemsPos, 'advanced controls should end before added items list');

echo "phase4-cashier-advanced-controls-contract-ok\n";

function phase4CashierUxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
