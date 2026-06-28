<?php

$source = file_get_contents(__DIR__ . '/../../classes/Inventory/InventoryInvoiceBridge.php');

inventoryBridgeConnAssert(
    strpos($source, 'function movementForInvoiceLine(mysqli $conn, int $invoiceType') !== false,
    'movementForInvoiceLine must accept mysqli $conn'
);
inventoryBridgeConnAssert(
    strpos($source, '$this->movementForInvoiceLine($conn, $invoiceType, $invoiceId, $line, $context, $index)') !== false,
    'recordInvoiceLines must pass $conn into movementForInvoiceLine'
);

echo "inventory-invoice-bridge-conn-contract-ok\n";

function inventoryBridgeConnAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
