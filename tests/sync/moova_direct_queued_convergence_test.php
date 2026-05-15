<?php

require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';

$service = new MoovaLocalIngestService();

$widgetNewOrder = [
    'cofeOrderId' => 'moova-converge-order-1',
    'branchId' => 'branch-1',
    'tableNumber' => '12',
    'idempotencyKey' => 'provider:new:1',
    'notes' => 'no sugar',
    'items' => [
        ['itemId' => 'coffee-1', 'qty' => '2', 'price' => '35.000'],
    ],
];
$pollerNewOrder = [
    'moova_order_id' => 'moova-converge-order-1',
    'moova_branch_id' => 'branch-1',
    'table_number' => '12',
    'idempotency_key' => 'provider:new:1',
    'note' => 'no sugar',
    'items' => [
        ['item_id' => 'coffee-1', 'quantity' => 2, 'unit_price' => '35.000'],
    ],
];

moovaConvergenceAssertSame(
    $service->normalizeIdempotencyKey($widgetNewOrder, 'new_order'),
    $service->normalizeIdempotencyKey($pollerNewOrder, 'new_order'),
    'new-order idempotency key should match across direct widget and queued poller payloads'
);
moovaConvergenceAssertSame(
    $service->normalizePayloadHash($widgetNewOrder),
    $service->normalizePayloadHash($pollerNewOrder),
    'new-order payload hash should match across direct widget and queued poller payloads'
);

$widgetChange = [
    'action' => 'edit',
    'moovaOrderId' => 'moova-converge-order-1',
    'branchId' => 'branch-1',
    'requestEventId' => 'change-1',
    'idempotencyKey' => 'provider:change:1',
    'providerOrderId' => '123',
    'items' => [
        ['itemId' => 'coffee-1', 'qty' => '1'],
    ],
];
$pollerChange = [
    'event_type' => 'edit_order',
    'moova_order_id' => 'moova-converge-order-1',
    'moova_branch_id' => 'branch-1',
    'provider_event_id' => 'change-1',
    'idempotency_key' => 'provider:change:1',
    'provider_order_id' => '123',
    'items' => [
        ['item_id' => 'coffee-1', 'quantity' => 1],
    ],
];

moovaConvergenceAssertSame(
    $service->normalizeIdempotencyKey($widgetChange, 'edit_order'),
    $service->normalizeIdempotencyKey($pollerChange, 'edit_order'),
    'change idempotency key should match across direct widget and queued poller payloads'
);
moovaConvergenceAssertSame(
    $service->normalizePayloadHash($widgetChange),
    $service->normalizePayloadHash($pollerChange),
    'change payload hash should match across direct widget and queued poller payloads'
);

$confirmSource = moovaConvergenceSource('ajax/moova_confirm_order.php');
$changeSource = moovaConvergenceSource('ajax/moova_change_order.php');

foreach ([
    'confirm endpoint' => $confirmSource,
    'change endpoint' => $changeSource,
] as $label => $source) {
    moovaConvergenceAssertContains('MoovaLocalIngestService', $source, $label . ' should load the shared local ingest normalizer');
    moovaConvergenceAssertContains('normalizeIdempotencyKey', $source, $label . ' should normalize idempotency like the queued worker');
    moovaConvergenceAssertContains('normalizePayloadHash', $source, $label . ' should normalize request hashes like the queued worker');
    moovaConvergenceAssertContains("'request_hash' => \$requestHash", $source, $label . ' should pass the normalized request hash to the apply service');
    moovaConvergenceAssertContains("'request_json' => \$requestJson", $source, $label . ' should pass the stored request JSON to the apply service');
}

moovaConvergenceAssertContains('normalizeNewOrderForPos($payload)', $confirmSource, 'confirm endpoint should apply a POS-shaped new-order payload');
moovaConvergenceAssertContains('normalizeChangeForPos($payload)', $changeSource, 'change endpoint should apply a POS-shaped change payload');
moovaConvergenceAssertNotContains('MoovaPosIntegration::changePayloadHash', $changeSource, 'change endpoint should not use the older direct-only hash helper');

echo "moova-direct-queued-convergence-ok\n";

function moovaConvergenceSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function moovaConvergenceAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function moovaConvergenceAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}

function moovaConvergenceAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
