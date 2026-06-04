<?php

require_once dirname(__DIR__, 2) . '/classes/Items/ItemEditorFlash.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];

ItemEditorFlash::set('success', 'saved');
$flash = ItemEditorFlash::take();
assert($flash !== null && $flash['type'] === 'success' && $flash['message'] === 'تم الحفظ بنجاح.');
assert(ItemEditorFlash::take() === null, 'flash should be consumed once');

ItemEditorFlash::set('danger', 'duplicate_barcode');
$flash = ItemEditorFlash::take();
assert($flash !== null && strpos($flash['message'], 'الباركود') !== false);

echo "item-editor-flash-ok\n";
