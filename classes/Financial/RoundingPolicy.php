<?php

require_once __DIR__ . '/Decimal.php';

final class RoundingPolicy
{
    public const POSTED_SCALE = 2;

    /**
     * Round half-up at a documented posting boundary.
     *
     * @param int      $postedScale Target posted scale (default EGP 2dp).
     * @param int|null $inputScale  Working scale; auto-detected when null.
     */
    public static function halfUp(string $value, int $postedScale = self::POSTED_SCALE, ?int $inputScale = null): string
    {
        if ($postedScale < 0 || $postedScale > 12) {
            throw new InvalidArgumentException('FINANCIAL_SCALE_INVALID');
        }
        $value = trim($value);
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_INVALID');
        }
        if ($inputScale === null) {
            $dot = strpos($value, '.');
            $inputScale = $dot === false ? $postedScale : max($postedScale, strlen(substr($value, $dot + 1)));
            $inputScale = min(12, max(6, $inputScale));
        }
        $value = bcadd($value, '0', $inputScale);
        $half = '0.' . str_repeat('0', $postedScale) . '5';
        if (bccomp($value, '0', $inputScale) < 0) {
            $value = bcsub($value, $half, $inputScale + 1);
        } else {
            $value = bcadd($value, $half, $inputScale + 1);
        }
        $truncated = bcdiv($value, '1', $postedScale);

        return FinancialDecimal::normalize($truncated, $postedScale, true);
    }
}
