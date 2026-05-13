<?php

$confirm = moovaRoutingSource('ajax/moova_confirm_order.php');
$change = moovaRoutingSource('ajax/moova_change_order.php');
$worker = moovaRoutingSource('classes/Sync/BranchMoovaApplyWorker.php');

moovaRoutingAssert(strpos($confirm, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, 'confirm endpoint should load PosOrderMutationService');
moovaRoutingAssert(strpos($confirm, '->confirmMoovaOrder($conn') !== false, 'confirm endpoint should call PosOrderMutationService::confirmMoovaOrder');
moovaRoutingAssert(strpos($confirm, 'new MoovaNewOrderApplyService()') === false, 'confirm endpoint should not instantiate Moova apply service directly');
moovaRoutingAssert(strpos($confirm, "'response_mode' => 'direct'") !== false, 'confirm endpoint should preserve direct widget response mode');

moovaRoutingAssert(strpos($change, "require_once('../classes/Pos/Service/PosOrderMutationService.php')") !== false, 'change endpoint should load PosOrderMutationService');
moovaRoutingAssert(strpos($change, 'moova_change_is_cashier_confirmed($payload)') !== false, 'change endpoint should keep cashier review gate');
moovaRoutingAssert(strpos($change, '->changeMoovaOrder($conn') !== false, 'change endpoint should call PosOrderMutationService::changeMoovaOrder');
moovaRoutingAssert(strpos($change, 'new MoovaChangeOrderApplyService()') === false, 'change endpoint should not instantiate Moova change service directly');
moovaRoutingAssert(strpos($change, "'response_mode' => 'direct'") !== false, 'change endpoint should preserve direct widget response mode');

moovaRoutingAssert(strpos($worker, '$this->newOrderApply->applyInTransaction') !== false, 'queued worker should still call shared new-order apply service');
moovaRoutingAssert(strpos($worker, '$this->changeOrderApply->applyInTransaction') !== false, 'queued worker should still call shared change apply service');
moovaRoutingAssert(strpos($worker, "'response_mode' => 'queued'") !== false, 'queued worker should preserve queued response mode');

echo "moova-confirm-change-routing-ok\n";

function moovaRoutingSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function moovaRoutingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
