<?php

require_once __DIR__ . '/../Financial/FinancialPricingService.php';

final class PaymentReconciliationService
{
    public static function assertOrderPaymentMatchesNet($orderNet, array $payments, int $scale = 2): void
    {
        $expected = Money::from($orderNet);
        $paid = Money::zero();
        foreach ($payments as $payment) {
            $paid = $paid->add(Money::from($payment));
        }

        if ($paid->compare($expected) < 0) {
            throw new InvalidArgumentException('PAYMENT_LESS_THAN_NET');
        }
    }

    public static function recomputeTableNetFromLines(array $lines, $headerDiscount = '0', int $scale = 2): string
    {
        return (new FinancialPricingService())->price($lines, $headerDiscount)['totals']['net'];
    }
}
