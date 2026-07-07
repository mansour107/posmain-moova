<?php

$root = dirname(__DIR__, 2);

function posVirtualKeyboardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$posBarcode = (string) file_get_contents($root . '/pos_barcode.php');
$content = (string) file_get_contents($root . '/includes/pos_content.php');
$css = (string) file_get_contents($root . '/dist/css/pos_barcode.css');
$js = (string) file_get_contents($root . '/js/pos_virtual_keyboard.js');

posVirtualKeyboardAssert($posBarcode !== '', 'Unable to read pos_barcode.php');
posVirtualKeyboardAssert($content !== '', 'Unable to read pos_content.php');
posVirtualKeyboardAssert($css !== '', 'Unable to read pos_barcode.css');
posVirtualKeyboardAssert($js !== '', 'Unable to read pos_virtual_keyboard.js');

posVirtualKeyboardAssert(strpos($posBarcode, 'posKeyboardToggleBtn') !== false, 'POS corner menu should expose keyboard toggle');
posVirtualKeyboardAssert(strpos($posBarcode, 'fa-keyboard') !== false, 'Keyboard toggle should use keyboard icon');
posVirtualKeyboardAssert(strpos($content, 'pos_virtual_keyboard.js') !== false, 'POS content should load virtual keyboard script');
posVirtualKeyboardAssert(strpos($js, 'POSMAIN.VirtualKeyboard') !== false, 'Virtual keyboard module should register POSMAIN API');
posVirtualKeyboardAssert(strpos($js, 'posVirtualKeyboard') !== false, 'Virtual keyboard overlay root should exist');
posVirtualKeyboardAssert(strpos($js, '#posKeyboardToggleBtn') !== false, 'Virtual keyboard should bind corner toggle');
posVirtualKeyboardAssert(strpos($js, 'posUnifiedSearch') !== false, 'Virtual keyboard should default to unified search input');
posVirtualKeyboardAssert(strpos($css, '.pos-virtual-keyboard') !== false, 'Virtual keyboard styles should exist');
posVirtualKeyboardAssert(strpos($css, 'pos-virtual-keyboard-open') !== false, 'Virtual keyboard open body class should exist');

echo "pos-virtual-keyboard-contract-ok\n";
