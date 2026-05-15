<?php

$serviceSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');
if ($serviceSource === false) {
    throw new RuntimeException('Unable to read PosOrderMutationService.php');
}

phase4ManagerApprovalContractAssert(strpos($serviceSource, "require_once __DIR__ . '/ManagerApprovalService.php';") !== false, 'mutation service should require ManagerApprovalService');
phase4ManagerApprovalContractAssert(strpos($serviceSource, 'private $managerApprovalService;') !== false, 'mutation service should store ManagerApprovalService');
phase4ManagerApprovalContractAssert(strpos($serviceSource, '?ManagerApprovalService $managerApprovalService = null') !== false, 'constructor should accept optional manager approval service');
phase4ManagerApprovalContractAssert(strpos($serviceSource, '$this->managerApprovalService = $managerApprovalService ?: new ManagerApprovalService();') !== false, 'constructor should default manager approval service');
phase4ManagerApprovalContractAssert(substr_count($serviceSource, 'requireDiscountApprovalIfNeeded($conn') >= 2, 'takeaway and table discount paths should call approval hook');
phase4ManagerApprovalContractAssert(strpos($serviceSource, "'discount.override'") !== false, 'discount override action type expected');
phase4ManagerApprovalContractAssert(strpos($serviceSource, "'pos_order'") !== false, 'pos_order target type expected');
phase4ManagerApprovalContractAssert(strpos($serviceSource, 'requireApprovedIfNeeded(') !== false, 'approval service should enforce through requireApprovedIfNeeded');

$approvalSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php');
if ($approvalSource === false) {
    throw new RuntimeException('Unable to read ManagerApprovalService.php');
}
phase4ManagerApprovalContractAssert(strpos($approvalSource, 'POSMAIN_REQUIRE_DISCOUNT_APPROVAL') !== false, 'approval enforcement should be env-gated');
phase4ManagerApprovalContractAssert(strpos($approvalSource, 'discount_approval_threshold') !== false, 'approval enforcement should support threshold context');
phase4ManagerApprovalContractAssert(strpos($approvalSource, 'MANAGER_APPROVAL_REQUIRED') !== false, 'approval required code expected');

echo "phase4-manager-approval-integration-contract-ok\n";

function phase4ManagerApprovalContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
