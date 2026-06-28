<?php

require_once __DIR__ . '/../../classes/Pos/Service/OrderMutationSideEffectsService.php';
require_once __DIR__ . '/../../classes/Pos/Service/KitchenEventPublisher.php';

$sideEffects = file_get_contents(__DIR__ . '/../../classes/Pos/Service/OrderMutationSideEffectsService.php');
$kitchen = file_get_contents(__DIR__ . '/../../classes/Pos/Service/KitchenEventPublisher.php');

kdsFoundationAssert(strpos($sideEffects, 'recordTableSave') !== false, 'side effects service should centralize table save');
kdsFoundationAssert(strpos($sideEffects, 'recordCashierMutation') !== false, 'side effects should cover cashier create/update');
kdsFoundationAssert(strpos($sideEffects, 'recordTablePayment') !== false, 'side effects should cover table payment');
kdsFoundationAssert(strpos($sideEffects, 'SyncOutboxEventService') !== false, 'side effects should record sync outbox');
kdsFoundationAssert(strpos($sideEffects, 'OrderEventService') !== false, 'side effects should record order events');
kdsFoundationAssert(strpos($sideEffects, 'KitchenEventPublisher') !== false, 'side effects should publish kitchen metadata');
kdsFoundationAssert(strpos($kitchen, 'buildKotPayloadByOrderId') !== false, 'kitchen publisher should reuse KOT payload');

echo "kds-foundation-contract-ok\n";

function kdsFoundationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
