<?php

/**
 * Strict fixed-point decimal helper for financial values.
 *
 * This intentionally does not accept PHP floats. A financial request must use
 * a decimal string (or an integer) so a binary floating-point value can never
 * silently become a posted amount.
 */
final class FinancialDecimal
{
    public static function normalize($value, int $scale, bool $allowNegative = false): string
    {
        if ($scale < 0 || $scale > 12) {
            throw new InvalidArgumentException('FINANCIAL_SCALE_INVALID');
        }
        if (is_float($value) || is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_STRING_REQUIRED');
        }

        $value = trim((string) $value);
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_INVALID');
        }
        if (!$allowNegative && strncmp($value, '-', 1) === 0) {
            throw new InvalidArgumentException('FINANCIAL_AMOUNT_NEGATIVE');
        }

        $negative = strncmp($value, '-', 1) === 0;
        $value = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $scale) {
            if (trim(substr($fraction, $scale), '0') !== '') {
                throw new InvalidArgumentException('FINANCIAL_DECIMAL_SCALE_EXCEEDED');
            }
            $fraction = substr($fraction, 0, $scale);
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, $scale, '0');
        $result = $whole . ($scale > 0 ? '.' . $fraction : '');

        return $negative && trim($result, '0.') !== '' ? '-' . $result : $result;
    }

    public static function compare(string $left, string $right, int $scale): int
    {
        return bccomp(self::normalize($left, $scale, true), self::normalize($right, $scale, true), $scale);
    }

    public static function add(string $left, string $right, int $scale): string
    {
        return self::normalize(bcadd(self::normalize($left, $scale, true), self::normalize($right, $scale, true), $scale), $scale, true);
    }

    public static function subtract(string $left, string $right, int $scale): string
    {
        return self::normalize(bcsub(self::normalize($left, $scale, true), self::normalize($right, $scale, true), $scale), $scale, true);
    }

    public static function multiply(string $left, string $right, int $scale): string
    {
        return self::normalize(bcmul(self::normalize($left, 6, true), self::normalize($right, 6, true), $scale), $scale, true);
    }
}
