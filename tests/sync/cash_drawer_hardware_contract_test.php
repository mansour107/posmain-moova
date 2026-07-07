<?php

$root = dirname(__DIR__, 2);

function cashDrawerHardwareContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$serviceFile = (string) file_get_contents($root . '/classes/Pos/Service/CashDrawerHardwareService.php');
$noSale = (string) file_get_contents($root . '/ajax/pos_drawer_no_sale.php');
$status = (string) file_get_contents($root . '/ajax/pos_drawer_status.php');
$js = (string) file_get_contents($root . '/js/pos_barcode.js');
$css = (string) file_get_contents($root . '/dist/css/pos_barcode.css');
$manifest = (string) file_get_contents($root . '/config/rbac_route_manifest.php');
$smoke = (string) file_get_contents($root . '/tools/cash_drawer_smoke.php');

cashDrawerHardwareContractAssert($serviceFile !== '', 'Cash drawer service should exist');
cashDrawerHardwareContractAssert(strpos($serviceFile, 'class CashDrawerHardwareService') !== false, 'Cash drawer service class expected');
cashDrawerHardwareContractAssert(strpos($serviceFile, 'openPulseCommand') !== false, 'ESC/POS open pulse helper expected');
cashDrawerHardwareContractAssert(strpos($serviceFile, 'parseDrawerStatusByte') !== false, 'Drawer status parser expected');
cashDrawerHardwareContractAssert(strpos($serviceFile, 'driver\' => \'network\'') !== false, 'Network driver expected');
cashDrawerHardwareContractAssert(strpos($serviceFile, 'simulator') === false, 'Simulator driver should be removed');

cashDrawerHardwareContractAssert(strpos($noSale, 'CashDrawerHardwareService') !== false, 'No-sale endpoint should call hardware service');
cashDrawerHardwareContractAssert(strpos($noSale, 'userMessageForCode') !== false, 'No-sale endpoint should expose friendly messages');
cashDrawerHardwareContractAssert(strpos($status, 'readStatus') !== false, 'Status endpoint should read drawer status');
cashDrawerHardwareContractAssert(strpos($status, 'CashDrawerHardwareService') !== false, 'Status endpoint should load hardware service');

cashDrawerHardwareContractAssert(strpos($js, 'posmainSwalPremiumOptions') !== false, 'POS JS should expose premium swal helper');
cashDrawerHardwareContractAssert(strpos($js, 'posmainShowDrawerError') !== false, 'POS JS should expose drawer error helper');
cashDrawerHardwareContractAssert(strpos($js, 'posmainPollDrawerStatus') !== false, 'POS JS should poll drawer status');
cashDrawerHardwareContractAssert(strpos($js, 'DRAWER_SESSION_REQUIRED') !== false, 'POS JS should map drawer session error');
cashDrawerHardwareContractAssert(strpos($js, 'محاكاة') === false, 'POS JS should not mention simulator mode');

cashDrawerHardwareContractAssert(strpos($css, '.swal2-popup.pos-swal-premium .swal2-icon.swal2-error') !== false, 'Premium swal error icon styles expected');

cashDrawerHardwareContractAssert(strpos($manifest, 'ajax/pos_drawer_status.php') !== false, 'RBAC manifest should register drawer status route');
cashDrawerHardwareContractAssert(strpos($smoke, 'POSMAIN_CASH_DRAWER_HOST') !== false, 'Smoke tool should support host flag');
cashDrawerHardwareContractAssert(strpos($smoke, 'simulator') === false, 'Smoke tool should not default to simulator');

require_once $root . '/classes/Pos/Service/CashDrawerHardwareService.php';

$pulse = CashDrawerHardwareService::openPulseCommand(0, 50, 500);
cashDrawerHardwareContractAssert($pulse === "\x1B\x70\x00\x19\xFA", 'Default ESC/POS pulse should match pin-2 timing');

cashDrawerHardwareContractAssert(
    CashDrawerHardwareService::parseDrawerStatusByte("\x08") === 'open',
    'Drawer status byte with bit 3 should read open'
);
cashDrawerHardwareContractAssert(
    CashDrawerHardwareService::parseDrawerStatusByte("\x00") === 'closed',
    'Drawer status byte without bit 3 should read closed'
);

echo "cash-drawer-hardware-contract-ok\n";
