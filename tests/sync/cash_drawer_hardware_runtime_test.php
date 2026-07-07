<?php

require_once __DIR__ . '/../../classes/Pos/Service/CashDrawerHardwareService.php';

function cashDrawerHardwareRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('POSMAIN_CASH_DRAWER_MODE');
putenv('POSMAIN_CASH_DRAWER_HOST');

$service = new CashDrawerHardwareService();
$scope = ['tenant' => 91, 'branch' => 7];
$config = $service->resolveDriverConfig(null, $scope);

cashDrawerHardwareRuntimeAssert(($config['driver'] ?? '') === 'disabled', 'Default driver should be disabled without hardware config');

$disabledStatus = $service->readStatus($config);
cashDrawerHardwareRuntimeAssert(($disabledStatus['status'] ?? '') === 'not_configured', 'Disabled driver should report not_configured');

$failedOpen = false;
try {
    $service->open($config);
} catch (RuntimeException $exception) {
    $failedOpen = $exception->getMessage() === 'CASH_DRAWER_NOT_CONFIGURED';
}
cashDrawerHardwareRuntimeAssert($failedOpen, 'Open without hardware config should fail with CASH_DRAWER_NOT_CONFIGURED');

putenv('POSMAIN_CASH_DRAWER_MODE=network');
$missingHostFailed = false;
try {
    $service->resolveDriverConfig(null, $scope);
} catch (RuntimeException $exception) {
    $missingHostFailed = $exception->getMessage() === 'CASH_DRAWER_NOT_CONFIGURED';
}
cashDrawerHardwareRuntimeAssert($missingHostFailed, 'Network mode without host should fail configuration');

echo "cash-drawer-hardware-runtime-ok\n";
