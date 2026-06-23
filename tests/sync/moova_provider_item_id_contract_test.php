<?php

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';

function moovaProviderItemIdAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " (expected {$expected}, got {$actual})\n");
        exit(1);
    }
}

moovaProviderItemIdAssertSame(
    '42',
    MoovaPosIntegration::normalizeProviderItemId('pos-item-42'),
    'pos-item prefix should normalize to numeric id'
);
moovaProviderItemIdAssertSame(
    '42',
    MoovaPosIntegration::normalizeProviderItemId('POS-ITEM-42'),
    'pos-item prefix should be case-insensitive'
);
moovaProviderItemIdAssertSame(
    '42',
    MoovaPosIntegration::normalizeProviderItemId('42'),
    'plain numeric ids should remain unchanged'
);
moovaProviderItemIdAssertSame(
    'BC-12345',
    MoovaPosIntegration::normalizeProviderItemId('BC-12345'),
    'barcode-like ids should remain unchanged'
);
moovaProviderItemIdAssertSame(
    '',
    MoovaPosIntegration::normalizeProviderItemId(''),
    'empty ids should remain empty'
);

$ingestSource = file_get_contents(__DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php');
$orderSource = file_get_contents(__DIR__ . '/../../classes/PosOrderService.php');

if (
    strpos($ingestSource, 'MoovaPosIntegration::normalizeProviderItemId') === false
    || strpos($orderSource, 'MoovaPosIntegration::normalizeProviderItemId') === false
) {
    fwrite(STDERR, "normalizeProviderItemId is not wired into ingest/apply paths\n");
    exit(1);
}

echo "moova-provider-item-id-contract-ok\n";
