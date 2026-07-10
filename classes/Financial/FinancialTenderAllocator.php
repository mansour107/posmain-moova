<?php

require_once __DIR__ . '/Decimal.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/RoundingPolicy.php';

/** Largest-remainder allocation of a refund total across original tenders. */
final class FinancialTenderAllocator
{
    /**
     * @param array<int,array{id:int|string,amount:string}> $tenders
     * @return array<int,array{id:int|string,amount:string}>
     */
    public static function allocate(string $refundTotal, array $tenders): array
    {
        $refundTotal = Money::from($refundTotal)->toString();
        if ($tenders === []) {
            throw new InvalidArgumentException('REFUND_TENDERS_REQUIRED');
        }

        $pool = Money::zero();
        $normalized = [];
        foreach ($tenders as $tender) {
            $amount = Money::from((string) ($tender['amount'] ?? '0'))->toString();
            if (!Money::from($amount)->isPositive()) {
                continue;
            }
            $pool = $pool->add(Money::from($amount));
            $normalized[] = [
                'id' => $tender['id'],
                'amount' => $amount,
            ];
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('REFUND_TENDERS_REQUIRED');
        }
        if ($pool->compare(Money::from($refundTotal)) < 0) {
            throw new InvalidArgumentException('REFUND_EXCEEDS_AVAILABLE_TENDERS');
        }

        $poolString = $pool->toString();
        $allocations = [];
        $remaining = $refundTotal;
        $remainders = [];
        foreach ($normalized as $index => $tender) {
            $raw = bcdiv(bcmul($refundTotal, $tender['amount'], 12), $poolString, 6);
            $rounded = RoundingPolicy::halfUp($raw);
            $allocations[$index] = $rounded;
            $remaining = FinancialDecimal::subtract($remaining, $rounded, Money::SCALE);
            $remainders[] = [
                'index' => $index,
                'remainder' => FinancialDecimal::subtract($raw, $rounded, 6),
                'id' => (string) $tender['id'],
            ];
        }

        usort($remainders, static function (array $left, array $right): int {
            $compare = FinancialDecimal::compare($right['remainder'], $left['remainder'], 6);
            return $compare !== 0 ? $compare : strcmp($left['id'], $right['id']);
        });

        while (FinancialDecimal::compare($remaining, '0', Money::SCALE) !== 0) {
            foreach ($remainders as $remainder) {
                if (FinancialDecimal::compare($remaining, '0', Money::SCALE) === 0) {
                    break;
                }
                $direction = FinancialDecimal::compare($remaining, '0', Money::SCALE) > 0 ? '0.01' : '-0.01';
                $allocations[$remainder['index']] = FinancialDecimal::add(
                    $allocations[$remainder['index']],
                    $direction,
                    Money::SCALE
                );
                $remaining = FinancialDecimal::subtract($remaining, $direction, Money::SCALE);
            }
        }

        $out = [];
        foreach ($normalized as $index => $tender) {
            $amount = Money::from($allocations[$index])->toString();
            if (!Money::from($amount)->isPositive()) {
                continue;
            }
            $out[] = ['id' => $tender['id'], 'amount' => $amount];
        }

        return $out;
    }
}
