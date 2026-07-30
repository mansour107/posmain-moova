<?php

/**
 * Commercial V1 Step 2 exit gate: exact money + tax-off + tender/change facts.
 */

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ROOT_RESOLVE_FAILED\n");
    exit(1);
}

$options = getopt('', ['json', 'help', 'evidence-dir:']);
if (isset($options['help'])) {
    echo "Usage: php tools/commercial_v1_step2_gate.php [--evidence-dir=DIR] [--json]\n";
    exit(0);
}
require_once $root . '/tools/lib/CommercialV1GateSupport.php';

$checks = [];
$failures = [];
$assert = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? (': ' . $detail) : '');
    }
};

foreach ([
    'tests/sync/commercial_v1_step2_money_contract_test.php' => 'commercial-v1-step2-money-contract-ok',
    'tests/sync/commercial_v1_step2_money_runtime_test.php' => 'commercial-v1-step2-money-runtime-ok',
    'tests/sync/commercial_v1_browser_money_contract_test.js' => 'commercial-v1-browser-money-contract-ok',
    'tests/sync/pos_cashier_split_discount_contract_test.php' => 'pos-cashier-split-discount-contract-ok',
    'tests/sync/pos_split_payment_service_test.php' => 'pos-split-payment-service-ok',
    'tests/sync/pos_table_endpoint_idempotency_test.php' => 'pos-table-endpoint-idempotency-ok',
    'tests/sync/pos_browser_write_csrf_test.php' => 'pos-browser-write-csrf-ok',
    'tests/sync/order_pricing_service_test.php' => 'order-pricing-service-ok',
    'tests/sync/commercial_v1_step1_security_contract_test.php' => 'commercial-v1-step1-security-contract-ok',
] as $script => $successMarker) {
    $result = CommercialV1GateSupport::run($root, $script);
    $verification = CommercialV1GateSupport::verifyTestResult($result, $successMarker);
    $assert('run:' . basename($script), $verification['ok'], $verification['detail']);
}

require_once $root . '/classes/Financial/FinancialMoneyInput.php';
$floatRejected = false;
try {
    FinancialMoneyInput::money(1.25);
} catch (InvalidArgumentException $e) {
    $floatRejected = true;
}
$assert('float_money_rejected', $floatRejected);

$posRequest = (string) file_get_contents($root . '/classes/Pos/Http/PosRequest.php');
$assert('pos_request_rejects_floats', str_contains($posRequest, 'assertNoPhpFloats'));
$invoice = (string) file_get_contents($root . '/classes/Financial/FinancialInvoicePostingService.php');
$assert('invoice_tax_fail_closed', str_contains($invoice, 'TAX_DISABLED_NONZERO_TAX_REJECTED'));
$mutation = (string) file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
$assert('cash_change_facts', str_contains($mutation, 'change_due') && str_contains($mutation, 'tendered_amount'));
$assert('non_cash_over_tender_guard', str_contains($mutation, 'NON_CASH_TENDER_EXCEEDS_REMAINING'));

$identity = CommercialV1GateSupport::sourceIdentity($root);
$assert('git_commit_identity', $identity['git_commit'] !== '');
$assert(
    'clean_release_candidate',
    $identity['source_tree_clean'],
    'dirty_entries=' . count($identity['status_porcelain'])
);
$evidenceDir = CommercialV1GateSupport::evidenceDirectory($root, $options);
$receipt = [
    'gate' => 'commercial_v1_step2',
    'created_at' => gmdate('c'),
    'ok' => $failures === [],
    'failures' => $failures,
    'checks' => $checks,
    'identity' => $identity,
];
$receiptPath = CommercialV1GateSupport::writeReceipt($evidenceDir, 'step2', $receipt);

if (array_key_exists('json', $options)) {
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($receipt['ok'] ? 'OK' : 'FAIL') . ' commercial-v1-step2 checks=' . count($checks)
        . ' failures=' . count($failures) . ' receipt=' . $receiptPath . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
}

exit($receipt['ok'] ? 0 : 1);
