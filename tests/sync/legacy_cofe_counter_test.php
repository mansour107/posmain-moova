<?php

$source = file_get_contents(__DIR__ . '/../../ajax/cofe_create_order.php');

cofeCounterAssert(strpos($source, 'PosOrderController') !== false, 'cofe endpoint should use PosOrderController');
cofeCounterAssert(strpos($source, 'INSERT INTO ot_head') === false, 'cofe endpoint should not allocate invoice numbers inline');
cofeCounterAssert(strpos($source, 'nextCofeProId') === false, 'cofe endpoint should not keep local counter helpers');
cofeCounterAssert(strpos($source, 'SCOPE_COFE_CREATE') !== false, 'cofe endpoint should use shared idempotency scope');

echo "legacy-cofe-counter-ok\n";

function cofeCounterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
