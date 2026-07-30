<?php

/**
 * Runtime proof for Step 2 cash tendered/applied/change_due persistence and tax-off posting.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/db_bootstrap.php';
require_once $root . '/classes/Financial/FinancialMoneyInput.php';
require_once $root . '/classes/Financial/FinancialInvoicePostingService.php';
require_once $root . '/classes/Pos/Service/PosOrderMutationService.php';

function step2RuntimeAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$conn = posmain_db_connect();

// Ensure cash fact columns exist.
foreach ([
    'tendered_amount' => "ALTER TABLE order_payments ADD COLUMN tendered_amount DECIMAL(19,2) NULL AFTER amount",
    'applied_amount' => "ALTER TABLE order_payments ADD COLUMN applied_amount DECIMAL(19,2) NULL AFTER tendered_amount",
    'change_due' => "ALTER TABLE order_payments ADD COLUMN change_due DECIMAL(19,2) NULL AFTER applied_amount",
] as $column => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM order_payments LIKE '" . $conn->real_escape_string($column) . "'");
    if (!($check instanceof mysqli_result) || $check->num_rows < 1) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Failed to add ' . $column . ': ' . $conn->error);
        }
    }
}

$service = new PosOrderMutationService();
$ref = new ReflectionClass($service);
$method = $ref->getMethod('calculateTakeawayPayment');

$result = $method->invoke($service, '100.00', '120.00', '0.00');
step2RuntimeAssert($result['cash'] === '100.00', 'cash applied must equal remaining');
step2RuntimeAssert($result['cash_tendered'] === '120.00', 'cash tendered must be preserved');
step2RuntimeAssert($result['change_due'] === '20.00', 'change due must be exact');
step2RuntimeAssert($result['applied_amount'] === '100.00', 'applied amount must equal net');

$bankRejected = false;
try {
    $method->invoke($service, '100.00', '0.00', '120.00');
} catch (InvalidArgumentException $e) {
    $bankRejected = $e->getMessage() === 'NON_CASH_TENDER_EXCEEDS_REMAINING';
}
step2RuntimeAssert($bankRejected, 'bank over-tender must be rejected');

$mixed = $method->invoke($service, '100.00', '40.00', '60.00');
step2RuntimeAssert($mixed['cash'] === '40.00' && $mixed['bank'] === '60.00', 'mixed tender applied amounts must match');
step2RuntimeAssert($mixed['change_due'] === '0.00', 'exact mixed tender has no change');

$taxRejected = false;
try {
    (new FinancialInvoicePostingService())->postInvoiceFinalization(
        $conn,
        1,
        ['net' => '100.00', 'tax' => '14.00'],
        1,
        1,
        1,
        ['idempotency_key' => 'step2-tax-reject-' . getmypid()]
    );
} catch (InvalidArgumentException $e) {
    $taxRejected = $e->getMessage() === 'TAX_DISABLED_NONZERO_TAX_REJECTED';
}
step2RuntimeAssert($taxRejected, 'nonzero tax invoice posting must fail closed');

echo "commercial-v1-step2-money-runtime-ok\n";
