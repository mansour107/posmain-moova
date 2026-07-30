<?php

/**
 * Commercial V1 Step 3 exit gate: atomic mutations (version + idempotency + outbox path).
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
    echo "Usage: php tools/commercial_v1_step3_gate.php [--evidence-dir=DIR] [--json]\n";
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
    'tests/sync/commercial_v1_step3_atomic_contract_test.php' => 'commercial-v1-step3-atomic-contract-ok',
    'tests/sync/commercial_v1_step3_atomic_runtime_test.php' => 'commercial-v1-step3-atomic-runtime-ok',
    'tests/sync/pos_payment_split_service_idempotency_test.php' => 'pos-payment-split-service-idempotency-ok',
    'tests/sync/pos_table_pay_without_save_test.php' => 'pos-table-pay-without-save-ok',
    'tests/sync/phase4_table_transfer_service_test.php' => 'phase4-table-transfer-service-ok',
    'tests/sync/phase4_table_merge_service_test.php' => 'phase4-table-merge-service-ok',
    'tests/sync/drawer_cash_flow_integration_test.php' => 'drawer-cash-flow-integration-ok',
    'tests/sync/cash_flow_full_day_integration_test.php' => 'cash-flow-full-day-integration-ok',
] as $script => $successMarker) {
    $result = CommercialV1GateSupport::run($root, $script);
    $verification = CommercialV1GateSupport::verifyTestResult($result, $successMarker);
    $assert('run:' . basename($script), $verification['ok'], $verification['detail']);
}

$evidenceDir = CommercialV1GateSupport::evidenceDirectory($root, $options);
foreach ([
    'tools/commercial_v1_step1_gate.php' => 'OK commercial-v1-step1',
    'tools/commercial_v1_step2_gate.php' => 'OK commercial-v1-step2',
] as $script => $successMarker) {
    $result = CommercialV1GateSupport::run($root, $script, ['--evidence-dir=' . $evidenceDir]);
    $verification = CommercialV1GateSupport::verifyTestResult($result, $successMarker);
    $assert('run:' . basename($script), $verification['ok'], $verification['detail']);
}

$mutation = (string) file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
$assert('pay_locks_version', str_contains($mutation, 'payTableOrderInsideTransaction') && substr_count($mutation, 'lockAndAssert') >= 4);
$assert('cancel_idempotent_scope', str_contains($mutation, 'SCOPE_ORDER_CANCEL') && str_contains($mutation, 'cancelTableOrderInsideTransaction'));

$identity = CommercialV1GateSupport::sourceIdentity($root);
$assert('git_commit_identity', $identity['git_commit'] !== '');
$assert(
    'clean_release_candidate',
    $identity['source_tree_clean'],
    'dirty_entries=' . count($identity['status_porcelain'])
);
$receipt = [
    'gate' => 'commercial_v1_step3',
    'created_at' => gmdate('c'),
    'ok' => $failures === [],
    'failures' => $failures,
    'checks' => $checks,
    'identity' => $identity,
];
$receiptPath = CommercialV1GateSupport::writeReceipt($evidenceDir, 'step3', $receipt);

if (array_key_exists('json', $options)) {
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($receipt['ok'] ? 'OK' : 'FAIL') . ' commercial-v1-step3 checks=' . count($checks)
        . ' failures=' . count($failures) . ' receipt=' . $receiptPath . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
}

exit($receipt['ok'] ? 0 : 1);
