<?php

$root = dirname(__DIR__, 2);

$add = itemImageUploadSource($root . '/do/doadd_item.php');
$edit = itemImageUploadSource($root . '/do/doedit_item.php');
$variant = itemImageUploadSource($root . '/classes/Pos/Service/ItemVariantService.php');
$schema = itemImageUploadSource($root . '/db/DB.sql');

itemImageUploadAssert(strpos($schema, '`size` int(11) NOT NULL') !== false, 'imgs.size should be required in the canonical schema');

foreach ([
    'INSERT INTO imgs (iname, itemid, size) VALUES (?, ?, ?)',
    "bind_param('sii'",
] as $needle) {
    itemImageUploadAssert(strpos($add, $needle) !== false, 'add item image insert should persist size: ' . $needle);
    itemImageUploadAssert(strpos($edit, $needle) !== false, 'edit item image insert should persist size: ' . $needle);
}

itemImageUploadAssert(
    strpos($variant, 'INSERT INTO imgs (iname, itemid, size) VALUES (?, ?, ?)') !== false,
    'variant image copy should persist size'
);

echo "item_image_upload_contract_test: OK\n";

function itemImageUploadSource(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException('Unable to read source: ' . $path);
    }

    return $contents;
}

function itemImageUploadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
