<?php

$mutation = moovaConvergenceSource('classes/Pos/Service/PosOrderMutationService.php');
$newApply = moovaConvergenceSource('classes/Moova/MoovaNewOrderApplyService.php');
$changeApply = moovaConvergenceSource('classes/Moova/MoovaChangeOrderApplyService.php');
$worker = moovaConvergenceSource('classes/Sync/BranchMoovaApplyWorker.php');

moovaConvergenceAssert(strpos($mutation, 'public function confirmMoovaOrder') !== false, 'mutation service should expose confirmMoovaOrder');
moovaConvergenceAssert(strpos($mutation, 'public function changeMoovaOrder') !== false, 'mutation service should expose changeMoovaOrder');
moovaConvergenceAssert(strpos($mutation, 'new MoovaNewOrderApplyService()') !== false, 'confirm wrapper should delegate to the existing Moova new-order apply service');
moovaConvergenceAssert(strpos($mutation, 'new MoovaChangeOrderApplyService()') !== false, 'change wrapper should delegate to the existing Moova change apply service');
moovaConvergenceAssert(strpos($mutation, "throw new InvalidArgumentException('MOOVA_LINK_REQUIRED')") !== false, 'Moova wrappers should require a mapped POS link');

moovaConvergenceAssert(strpos($newApply, 'fetchOrderLinkForUpdate') !== false, 'new-order apply should lock idempotency/order link rows');
moovaConvergenceAssert(strpos($newApply, 'IDEMPOTENCY_PAYLOAD_CONFLICT') !== false, 'new-order duplicate key with changed payload should conflict');
moovaConvergenceAssert(strpos($newApply, 'response_payload') !== false, 'new-order duplicate key should replay stored response');
moovaConvergenceAssert(strpos($newApply, 'last_pos_state_hash') !== false, 'new-order apply should store the POS state hash for later changes');
moovaConvergenceAssert(strpos($newApply, 'recordOrderSnapshot') !== false, 'new-order apply should record sync order snapshots');
moovaConvergenceAssert(strpos($newApply, 'recordTableSnapshot') !== false, 'new-order apply should record sync table snapshots');

moovaConvergenceAssert(strpos($changeApply, 'fetchChangeLinkForUpdate') !== false, 'change apply should lock change idempotency rows');
moovaConvergenceAssert(strpos($changeApply, 'IDEMPOTENCY_PAYLOAD_CONFLICT') !== false, 'change duplicate key with changed payload should conflict');
moovaConvergenceAssert(strpos($changeApply, 'provider_status') !== false, 'change duplicate key should replay final provider status');
moovaConvergenceAssert(strpos($changeApply, 'preApplyDeclineCode') !== false, 'change apply should run stale/validity decline checks before mutation');
moovaConvergenceAssert(strpos($changeApply, 'POS_ORDER_LINES_CHANGED') !== false, 'stale Moova edits should decline with POS_ORDER_LINES_CHANGED');
moovaConvergenceAssert(strpos($changeApply, 'replaceMoovaTableOrder') !== false, 'edit change should delegate replacement to POS order service');
moovaConvergenceAssert(strpos($changeApply, 'cancelMoovaTableOrder') !== false, 'cancel change should delegate cancellation to POS order service');
moovaConvergenceAssert(strpos($changeApply, 'recordOrderSnapshot') !== false, 'change apply should record sync order snapshots');
moovaConvergenceAssert(strpos($changeApply, 'recordTableSnapshot') !== false, 'change apply should record sync table snapshots');

moovaConvergenceAssert(strpos($worker, 'private MoovaNewOrderApplyService $newOrderApply') !== false, 'queued worker should share the new-order apply service');
moovaConvergenceAssert(strpos($worker, 'private MoovaChangeOrderApplyService $changeOrderApply') !== false, 'queued worker should share the change apply service');

echo "moova-pos-mutation-convergence-ok\n";

function moovaConvergenceSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function moovaConvergenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
