<?php

$root = dirname(__DIR__, 2);
$form = itemEditorOptionBSource($root . '/add_item.php');
$js = itemEditorOptionBSource($root . '/js/additem.js');

foreach ([
    'class="item-editor-shell"',
    'id="item-info-section"',
    'id="item-units-section"',
    'id="item-inventory-section"',
    '1. معلومات الصنف',
    '2. الوحدات والأسعار',
    '3. إعدادات المخزون',
    'id="unitImpactPreview"',
    'id="summaryItemName"',
    'class="item-type-choice',
    'data-item-type="ingredient"',
    'name="save_intent" value="close"',
] as $needle) {
    itemEditorOptionBAssert(strpos($form, $needle) !== false, 'option B item editor should expose: ' . $needle);
}

foreach ([
    'window.refreshItemUnitsUi',
    '1 <strong class="unit-relation-unit-name"',
    'summaryItemType',
    'variantEditorBody',
    "removeClass('d-none')",
] as $needle) {
    itemEditorOptionBAssert(strpos($form, $needle) !== false, 'option B item editor script should wire: ' . $needle);
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
