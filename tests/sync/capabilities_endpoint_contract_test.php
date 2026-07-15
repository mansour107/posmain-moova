<?php

$path = __DIR__ . '/../../ajax/current_user_capabilities.php';
$source = file_get_contents($path);
capabilitiesContractAssert(is_string($source), 'missing capabilities endpoint');
capabilitiesContractAssert(strpos($source, 'require_login') !== false, 'capabilities endpoint must require login');
capabilitiesContractAssert(strpos($source, 'auth_guard_effective_permissions') !== false, 'capabilities endpoint must expose effective permissions');

$layout = file_get_contents(__DIR__ . '/../../includes/layout_capabilities.php');
capabilitiesContractAssert(strpos($layout, 'POSMAIN_CAPABILITIES') !== false, 'layout capabilities must define POSMAIN_CAPABILITIES');
capabilitiesContractAssert(strpos($layout, 'POSMAIN_APPROVER_ROLES') !== false, 'POS acting context must inject approver role map');

$header = file_get_contents(__DIR__ . '/../../includes/header.php');
capabilitiesContractAssert(strpos($header, 'layout_capabilities.php') !== false, 'header must inject layout capabilities');

echo "capabilities-endpoint-contract-ok\n";

function capabilitiesContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
