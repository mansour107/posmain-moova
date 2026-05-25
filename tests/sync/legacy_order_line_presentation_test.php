<?php

require_once __DIR__ . '/../../classes/Pos/Service/LegacyOrderLinePresentationService.php';

$service = new LegacyOrderLinePresentationService();

$packLine = $service->presentSaleLine([
    'qty_in' => '0',
    'qty_out' => '12.000000',
    'u_val' => '6.000000',
    'price' => '10.000000',
]);

legacyOrderLinePresentationAssert($packLine['qty'] === '2.000000', 'u_val sale quantity should be reconstructed as sell units');
legacyOrderLinePresentationAssert($packLine['price'] === '60.000000', 'legacy unit price should be presented as sell-unit price');
legacyOrderLinePresentationAssert($packLine['u_val'] === '6.000000', 'original u_val should be preserved for edit posts');
legacyOrderLinePresentationAssert($service->inputValue($packLine['qty']) === '2', 'quantity input should trim insignificant zeros');
legacyOrderLinePresentationAssert($service->inputValue($packLine['u_val']) === '6', 'u_val input should trim insignificant zeros');

$partialLine = $service->presentSaleLine([
    'qty_in' => '2.000000',
    'qty_out' => '5.000000',
    'u_val' => '3.000000',
    'price' => '4.000000',
]);
legacyOrderLinePresentationAssert($partialLine['qty'] === '1.000000', 'qty_out minus qty_in should be divided by u_val');
legacyOrderLinePresentationAssert($partialLine['price'] === '12.000000', 'sell-unit price should scale by u_val');

$invalidUnitLine = $service->presentSaleLine([
    'qty_in' => '0',
    'qty_out' => '5.000000',
    'u_val' => '0',
    'price' => '4.000000',
]);
legacyOrderLinePresentationAssert($invalidUnitLine['qty'] === '5.000000', 'invalid u_val should fall back to one');
legacyOrderLinePresentationAssert($invalidUnitLine['price'] === '4.000000', 'invalid u_val fallback should preserve unit price');
legacyOrderLinePresentationAssert($invalidUnitLine['u_val'] === '1.000000', 'invalid u_val fallback should post one');

$root = dirname(__DIR__, 2);
$helperSource = legacyOrderLinePresentationSource($root . '/classes/Pos/Service/LegacyOrderLinePresentationService.php');
$loadOrder = legacyOrderLinePresentationSource($root . '/ajax/load_order.php');
$getTableOrder = legacyOrderLinePresentationSource($root . '/ajax/get_table_order.php');
$posContent = legacyOrderLinePresentationSource($root . '/includes/pos_content.php');
$posBarcode = legacyOrderLinePresentationSource($root . '/js/pos_barcode.js');
$posJs = legacyOrderLinePresentationSource($root . '/js/pos.js');

foreach (['RecipeDecimal::add', 'RecipeDecimal::subtract', 'RecipeDecimal::multiply', 'RecipeDecimal::divide', 'bcadd', 'bcsub', 'bcmul', 'bcdiv'] as $forbiddenNeedle) {
    legacyOrderLinePresentationAssert(strpos($helperSource, $forbiddenNeedle) === false, 'cashier presentation helper must not require bcmath while recipes are disabled: ' . $forbiddenNeedle);
}
legacyOrderLinePresentationAssert(strpos($helperSource, 'divideScaledDecimal') !== false, 'cashier presentation helper should use local decimal division');
legacyOrderLinePresentationAssert(strpos($helperSource, 'multiplyIntegerStrings') !== false, 'cashier presentation helper should use local decimal multiplication');

foreach ([
    'ajax/load_order.php' => $loadOrder,
    'ajax/get_table_order.php' => $getTableOrder,
    'includes/pos_content.php' => $posContent,
] as $file => $source) {
    legacyOrderLinePresentationAssert(strpos($source, 'LegacyOrderLinePresentationService') !== false, $file . ' should use shared legacy line presentation helper');
    legacyOrderLinePresentationAssert(strpos($source, "floatval(\$item['qty_out']) - floatval(\$item['qty_in'])") === false, $file . ' should not display raw stock-unit quantity');
    legacyOrderLinePresentationAssert(strpos($source, "floatval(\$rowdet['qty_out']) - floatval(\$rowdet['qty_in'])") === false, $file . ' should not display raw stock-unit quantity');
}

legacyOrderLinePresentationAssert(strpos($loadOrder, "'u_val' => (float) \$presentedLine['u_val']") !== false, 'load_order should expose preserved u_val');
legacyOrderLinePresentationAssert(strpos($getTableOrder, "'u_val' => (float) \$presentedLine['u_val']") !== false, 'get_table_order should expose preserved u_val');
legacyOrderLinePresentationAssert(strpos($posContent, 'name="u_val[]" value="<?= htmlspecialchars($u_val') !== false, 'edit-mode cashier HTML should post preserved u_val');
legacyOrderLinePresentationAssert(strpos($posBarcode, '{ uVal: item.u_val || 1 }') !== false, 'loaded barcode POS rows should pass u_val to cart rows');
legacyOrderLinePresentationAssert(strpos($posBarcode, 'name="u_val[]" value="${escapeHtml(String(unitValue))}"') !== false, 'barcode POS cart rows should post preserved u_val');
legacyOrderLinePresentationAssert(strpos($posJs, 'value="${item.u_val || 1}"') !== false, 'legacy POS table loader should post preserved u_val');

echo "legacy-order-line-presentation-ok\n";

function legacyOrderLinePresentationSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function legacyOrderLinePresentationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
