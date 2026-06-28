<?php

$root = realpath(__DIR__ . '/../..');
$paths = [
    'classes/PosOrderService.php' => ['resolveIncomingItems', 'findActiveTableOrderForUpdate'],
    'classes/Moova/MoovaNewOrderApplyService.php' => ['PosOrderService', 'idempotency_key'],
    'classes/Moova/MoovaChangeOrderApplyService.php' => ['PosOrderService', 'decline'],
    'ajax/moova_confirm_order.php' => ['PosOrderMutationService', 'confirmMoovaOrder'],
    'ajax/moova_change_order.php' => ['PosOrderMutationService', 'changeMoovaOrder'],
];

foreach ($paths as $relativePath => $snippets) {
    $source = file_get_contents($root . '/' . $relativePath);
    moovaCompatAssert(is_string($source), 'unable to read ' . $relativePath);
    foreach ($snippets as $snippet) {
        moovaCompatAssert(strpos($source, $snippet) !== false, $relativePath . ' missing ' . $snippet);
    }
}

echo "moova-posorderservice-compat-ok\n";

function moovaCompatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
