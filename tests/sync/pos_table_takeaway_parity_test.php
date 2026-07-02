<?php

require_once __DIR__ . '/../../classes/Pos/Service/InventoryMovementService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

function posParityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$posSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php') ?: '';
posParityAssert(
    strpos($posSource, 'insertTakeawayDetailLine($conn, $orderId, $storeId, $line') !== false
    && strpos($posSource, 'insertTableOrderItems') !== false,
    'table order item insert must reuse normalized takeaway detail lines'
);

$tableBlock = substr($posSource, (int) strpos($posSource, 'private function insertTableOrderItems'));
posParityAssert(
    strpos($tableBlock, 'normalizeInvoiceLines') !== false,
    'table orders must normalize lines through InventoryMovementService'
);

posParityAssert(
    strpos($posSource, 'inventoryMovementService->normalizeInvoiceLines') !== false,
    'takeaway and table flows must share inventory movement normalization'
);

echo "pos_table_takeaway_parity_test: OK\n";
