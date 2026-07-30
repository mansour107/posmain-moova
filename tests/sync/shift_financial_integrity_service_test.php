<?php

require_once __DIR__ . '/../../classes/Pos/Service/ShiftFinancialIntegrityService.php';

function shiftFinancialIntegrityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = new ShiftFinancialIntegrityService();
$base = [
    'payments' => [
        'total' => '60.000',
        'refund_total' => '12.000',
        'settled_refund_total' => '12.000',
        'pending_external_refund_total' => '0.000',
        'net_total' => '48.000',
        'cash_net' => '40.000',
        'non_cash_net' => '8.000',
    ],
    'reconciliation' => [
        'cash_difference' => '0.000',
    ],
];

$green = $service->assertCloseable([
    'gross_sales' => '60.00',
    'refund_total' => '12.00',
    'net_sales' => '48.00',
], $base);
shiftFinancialIntegrityAssert($green['ok'] === true, 'matching durable projections should pass');

$pending = $base;
$pending['payments']['settled_refund_total'] = '0.000';
$pending['payments']['pending_external_refund_total'] = '12.000';
$pending['payments']['net_total'] = '60.000';
$pending['payments']['non_cash_net'] = '20.000';
$pendingGreen = $service->assertCloseable([
    'gross_sales' => '60.00',
    'refund_total' => '12.00',
    'net_sales' => '48.00',
], $pending);
shiftFinancialIntegrityAssert($pendingGreen['ok'] === true, 'pending external settlement bridges revenue and custody');

$broken = $base;
$broken['payments']['total'] = '55.000';
$broken['payments']['net_total'] = '43.000';
$broken['payments']['non_cash_net'] = '3.000';
$broken['reconciliation']['cash_difference'] = '2.000';
$rejected = false;
try {
    $service->assertCloseable([
        'gross_sales' => '60.00',
        'refund_total' => '12.00',
        'net_sales' => '48.00',
    ], $broken);
} catch (ShiftFinancialIntegrityException $exception) {
    $violations = $exception->violations();
    $rejected = isset(
        $violations['gross_sales_to_tenders'],
        $violations['net_revenue'],
        $violations['drawer_cash_to_cash_tender']
    );
}
shiftFinancialIntegrityAssert($rejected, 'mismatched revenue, tender, and drawer projections must fail');

echo "shift_financial_integrity_service_test: OK\n";
