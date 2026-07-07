<?php

$root = dirname(__DIR__, 2);

function posOverrideLockoutContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$overrideAuth = file_get_contents($root . '/ajax/pos_override_auth.php');
$approvalService = file_get_contents($root . '/classes/Pos/Service/ManagerApprovalService.php');

posOverrideLockoutContractAssert(strpos($overrideAuth, 'PinService') !== false, 'override auth should use PinService');
posOverrideLockoutContractAssert(strpos($overrideAuth, 'isTerminalFrozen') !== false, 'override auth should check terminal freeze');
posOverrideLockoutContractAssert(strpos($overrideAuth, 'manager_override_denied') !== false, 'override auth should audit denied attempts');
posOverrideLockoutContractAssert(strpos($overrideAuth, 'manager_override_granted') !== false, 'override auth should audit granted attempts');
posOverrideLockoutContractAssert(strpos($overrideAuth, 'PIN_TERMINAL_FROZEN') !== false, 'override auth should surface terminal freeze code');
posOverrideLockoutContractAssert(strpos($overrideAuth, 'MANAGER_PIN_LOCKED') !== false, 'override auth should surface user lock code');

posOverrideLockoutContractAssert(strpos($approvalService, 'recordUserFailure') !== false, 'approval service should record user PIN failures');
posOverrideLockoutContractAssert(strpos($approvalService, 'recordTerminalFailure') !== false, 'approval service should record terminal failures');
posOverrideLockoutContractAssert(strpos($approvalService, 'clearUserFailures') !== false, 'approval service should clear user failures on success');
posOverrideLockoutContractAssert(strpos($approvalService, 'ManagerApprovalRequiredException') !== false, 'approval service should throw typed approval-required exception');
posOverrideLockoutContractAssert(strpos($approvalService, 'InvalidArgumentException') !== false, 'approval service should treat invalid PIN format as manager PIN invalid');

$posJs = file_get_contents($root . '/js/pos_barcode.js');
posOverrideLockoutContractAssert(strpos($posJs, 'overrideErrorMessage') !== false, 'POS JS should map override failure codes to user messages');
posOverrideLockoutContractAssert(strpos($posJs, 'MANAGER_PIN_INVALID') !== false, 'POS JS should handle invalid manager PIN feedback');
posOverrideLockoutContractAssert(strpos($posJs, 'MANAGER_PERMISSION_DENIED') !== false, 'POS JS should handle manager permission denial feedback');
posOverrideLockoutContractAssert(strpos($posJs, 'initialError') !== false, 'PIN pad modal should accept initial error text');
posOverrideLockoutContractAssert(strpos($posJs, 'promptOverridePin') !== false, 'override flow should re-prompt after failed PIN attempts');

echo "pos-override-lockout-contract-ok\n";
