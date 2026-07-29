<?php

require_once dirname(__DIR__) . '/Value/CashAmount.php';

final class ShiftFinancialIntegrityException extends RuntimeException
{
    /** @var array<string, array{expected:string,actual:string}> */
    private array $violations;

    /**
     * @param array<string, array{expected:string,actual:string}> $violations
     */
    public function __construct(array $violations)
    {
        parent::__construct('SHIFT_FINANCIAL_RECONCILIATION_MISMATCH');
        $this->violations = $violations;
    }

    /** @return array<string, array{expected:string,actual:string}> */
    public function violations(): array
    {
        return $this->violations;
    }
}

/**
 * Exact-decimal certification boundary for a drawer close.
 *
 * Revenue is owned by posted orders/credit notes. Tender custody is owned by
 * order_payments/payment_refunds, while physical cash is owned by drawer
 * movements. A normal shift close may proceed only when those three durable
 * projections agree.
 */
final class ShiftFinancialIntegrityService
{
    /**
     * @param array{gross_sales:mixed,refund_total:mixed,net_sales:mixed} $sales
     * @param array<string,mixed> $reconciliation
     * @return array{ok:bool,violations:array<string,array{expected:string,actual:string}>}
     */
    public function evaluate(array $sales, array $reconciliation): array
    {
        $payments = is_array($reconciliation['payments'] ?? null)
            ? $reconciliation['payments']
            : [];
        $drawer = is_array($reconciliation['reconciliation'] ?? null)
            ? $reconciliation['reconciliation']
            : [];

        $grossSales = CashAmount::normalize($sales['gross_sales'] ?? '0.00', true);
        $refundTotal = CashAmount::normalize($sales['refund_total'] ?? '0.00', true);
        $netSales = CashAmount::normalize($sales['net_sales'] ?? '0.00', true);
        $grossTender = CashAmount::normalize($payments['total'] ?? '0.00', true);
        $tenderRefundTotal = CashAmount::normalize($payments['refund_total'] ?? '0.00', true);
        $settledRefundTotal = CashAmount::normalize($payments['settled_refund_total'] ?? '0.00', true);
        $pendingExternal = CashAmount::normalize($payments['pending_external_refund_total'] ?? '0.00', true);
        $netTender = CashAmount::normalize($payments['net_total'] ?? '0.00', true);
        $cashNet = CashAmount::normalize($payments['cash_net'] ?? '0.00', true);
        $nonCashNet = CashAmount::normalize($payments['non_cash_net'] ?? '0.00', true);
        $drawerCashDifference = CashAmount::normalize($drawer['cash_difference'] ?? '0.00', true);

        $violations = [];
        $this->compare($violations, 'gross_sales_to_tenders', $grossSales, $grossTender);
        $this->compare($violations, 'posted_refunds_to_tender_refunds', $refundTotal, $tenderRefundTotal);
        $this->compare(
            $violations,
            'net_revenue',
            $netSales,
            CashAmount::subtract($grossTender, $tenderRefundTotal)
        );
        $this->compare(
            $violations,
            'net_tender_components',
            $netTender,
            CashAmount::add($cashNet, $nonCashNet)
        );
        $this->compare(
            $violations,
            'settlement_bridge',
            $netTender,
            CashAmount::add($netSales, $pendingExternal)
        );
        $this->compare(
            $violations,
            'settled_refund_components',
            $netTender,
            CashAmount::subtract($grossTender, $settledRefundTotal)
        );
        $this->compare($violations, 'drawer_cash_to_cash_tender', '0.00', $drawerCashDifference);

        return [
            'ok' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @param array{gross_sales:mixed,refund_total:mixed,net_sales:mixed} $sales
     * @param array<string,mixed> $reconciliation
     * @return array{ok:bool,violations:array<string,array{expected:string,actual:string}>}
     */
    public function assertCloseable(array $sales, array $reconciliation): array
    {
        $result = $this->evaluate($sales, $reconciliation);
        if (!$result['ok']) {
            throw new ShiftFinancialIntegrityException($result['violations']);
        }

        return $result;
    }

    /**
     * @param array<string, array{expected:string,actual:string}> $violations
     */
    private function compare(array &$violations, string $key, string $expected, string $actual): void
    {
        $expected = CashAmount::normalize($expected, true);
        $actual = CashAmount::normalize($actual, true);
        if (CashAmount::compare($expected, $actual) !== 0) {
            $violations[$key] = [
                'expected' => $expected,
                'actual' => $actual,
            ];
        }
    }
}
