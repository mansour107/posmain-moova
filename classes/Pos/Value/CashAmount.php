<?php

require_once dirname(__DIR__, 2) . '/Financial/Decimal.php';

/**
 * Exact fixed-point value used by drawer and shift workflows.
 *
 * Drawer tables may retain three decimal places for schema compatibility, but
 * V1 currency values are canonical two-decimal strings. Request boundaries
 * reject PHP floats and any non-zero sub-cent input.
 */
final class CashAmount
{
    public const SCALE = 2;

    public static function normalize($value, bool $allowNegative = false): string
    {
        return FinancialDecimal::normalize($value, self::SCALE, $allowNegative);
    }

    public static function add($left, $right): string
    {
        return FinancialDecimal::add(
            self::normalize($left, true),
            self::normalize($right, true),
            self::SCALE
        );
    }

    public static function subtract($left, $right): string
    {
        return FinancialDecimal::subtract(
            self::normalize($left, true),
            self::normalize($right, true),
            self::SCALE
        );
    }

    public static function compare($left, $right): int
    {
        return FinancialDecimal::compare(
            self::normalize($left, true),
            self::normalize($right, true),
            self::SCALE
        );
    }

    public static function negate($value): string
    {
        return self::subtract('0.000', self::normalize($value, true));
    }
}
