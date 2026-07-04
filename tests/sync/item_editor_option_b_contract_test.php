<?php

$root = dirname(__DIR__, 2);
$form = itemEditorOptionBSource($root . '/add_item.php');
$panel = itemEditorOptionBSource($root . '/elements/sales/item_unit_profile_panel.php');
$profileJs = itemEditorOptionBSource($root . '/js/item_unit_profile.js');
$js = itemEditorOptionBSource($root . '/js/additem.js');

foreach ([
    'class="item-editor-shell"',
    'id="item-info-section"',
    '1. معلومات الصنف',
    'id="summaryItemName"',
    'id="summarySellUnit"',
    'id="summarySellPrice"',
    'id="summaryCostPerSellUnit"',
    'id="summaryProfitMargin"',
    'id="summaryExtraUnitsLine"',
    'name="save_intent" value="close"',
] as $needle) {
    itemEditorOptionBAssert(strpos($form, $needle) !== false, 'option B item editor should expose: ' . $needle);
}

foreach ([
    'id="sell-price-cost-row"',
    'id="sell_profit_margin"',
    'id="sell-cost-source-block"',
    'item-cost-source-choice',
    'cost-source-direct-choice',
    'كيف تُحسب التكلفة؟',
    'id="cost_per_unit_value"',
    'id="unitImpactPreview"',
    'id="item-inventory-section"',
    'data-item-type="ingredient"',
] as $needle) {
    itemEditorOptionBAssert(strpos($panel, $needle) !== false, 'item unit profile panel should expose: ' . $needle);
}

foreach ([
    'variantEditorBody',
] as $needle) {
    itemEditorOptionBAssert(strpos($form, $needle) !== false, 'option B item editor script should wire: ' . $needle);
}

foreach ([
    'refreshSanitySummary',
    'profitMarginPercent',
    'updateCostSourceChoiceStates',
    'summarySellUnit',
    'sell_profit_margin',
] as $needle) {
    itemEditorOptionBAssert(strpos($profileJs, $needle) !== false, 'item unit profile JS should wire: ' . $needle);
}

foreach ([
    'chooseFirstUnusedUnit',
    'refreshItemUnitsUi',
    '$(\'#addUnit\').on(\'click\'',
] as $needle) {
    itemEditorOptionBAssert(strpos($js, $needle) !== false, 'add item unit JS should support option B behavior: ' . $needle);
}

echo "item-editor-option-b-contract-ok\n";

function itemEditorOptionBSource(string $path): string
{
    $source = file_get_contents($path);
    itemEditorOptionBAssert(is_string($source), 'Unable to read ' . $path);

    return (string) $source;
}

function itemEditorOptionBAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}
