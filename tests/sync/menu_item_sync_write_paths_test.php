<?php

$root = dirname(__DIR__, 2);

function menuSyncWritePathSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
    return $source;
}

function menuSyncWritePathAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$helper = menuSyncWritePathSource($root . '/classes/Sync/MenuItemSyncRecorder.php');
menuSyncWritePathAssertContains("'menu_sync_enabled' => true", $helper, 'Menu sync helper should enable catalog sync for explicit menu writes.');
menuSyncWritePathAssertContains('catch (Throwable $exception)', $helper, 'Menu sync helper should not break POS writes when sync is unavailable.');

$paths = [
    'do/doadd_item.php' => 'item_form',
    'do/doedit_item.php' => 'item_form',
    'do/dodel_item.php' => 'item_delete',
    'do/update_item_price.php' => 'item_price_update',
    'do/uploaditems.php' => 'item_upload',
];

foreach ($paths as $relativePath => $sourceSystem) {
    $source = menuSyncWritePathSource($root . '/' . $relativePath);
    menuSyncWritePathAssertContains('MenuItemSyncRecorder.php', $source, "{$relativePath} should load the menu sync recorder.");
    menuSyncWritePathAssertContains('posmain_record_menu_item_sync', $source, "{$relativePath} should enqueue menu item sync.");
    menuSyncWritePathAssertContains($sourceSystem, $source, "{$relativePath} should tag menu sync source {$sourceSystem}.");
}

$upload = menuSyncWritePathSource($root . '/do/uploaditems.php');
menuSyncWritePathAssertContains('WHERE id = ', $upload, 'Spreadsheet item updates must stay scoped to the matched item.');

echo "menu item sync write paths ok\n";
