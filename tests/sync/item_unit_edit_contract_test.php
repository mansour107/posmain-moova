<?php

$root = dirname(__DIR__, 2);

$form = itemUnitEditSource($root . '/add_item.php');
$save = itemUnitEditSource($root . '/do/doedit_item.php');
$js = itemUnitEditSource($root . '/js/additem.js');

foreach ([
    '$renderedUnitRows = 0',
    '$renderedUnitRows++',
    '$renderedUnitRows === 0',
    'id="addUnit"',
    'id="unitsContainer"',
] as $needle) {
    itemUnitEditAssert(strpos($form, $needle) !== false, 'edit item form should render a usable unit row when none exists: ' . $needle);
}

foreach ([
    'posmain_edit_item_save_units($conn, $item_id, $payload[\'units\'])',
    'INSERT INTO item_units',
    'UPDATE item_units',
    'unit_id NOT IN',
    'posmain_edit_item_unit_exists',
] as $needle) {
    itemUnitEditAssert(strpos($save, $needle) !== false, 'edit item save should upsert and remove item unit rows: ' . $needle);
}

foreach ([
    '$(\'#addUnit\').on(\'click\'',
    '$(\'.urow\').first().clone()',
    'clone.find(\'input[name="u_val[]"]\').val(\'6\').prop(\'readonly\', false)',
    'refreshItemUnitsUi',
] as $needle) {
    itemUnitEditAssert(strpos($js, $needle) !== false, 'add item JS should keep add-unit behavior: ' . $needle);
}

echo "item-unit-edit-contract-ok\n";

function itemUnitEditSource(string $path): string
{
    $source = file_get_contents($path);
    itemUnitEditAssert(is_string($source), 'Unable to read ' . $path);

    return (string) $source;
}

function itemUnitEditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}
