<?php

require_once dirname(__DIR__) . '/Financial/Money.php';
require_once dirname(__DIR__) . '/Financial/UnitPrice.php';
require_once dirname(__DIR__) . '/Financial/DecimalQuantity.php';

/**
 * Commercial V1 money/quantity boundary adapter.
 * Certified write APIs accept canonical decimal strings (or integers) only.
 */
final class FinancialMoneyInput
{
    public static function assertNoPhpFloats($value, string $path = 'payload'): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('JSON_FLOAT_MONEY_REJECTED:' . $path);
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $childPath = $path . '.' . (is_int($key) ? (string) $key : (string) $key);
            self::assertNoPhpFloats($child, $childPath);
        }
    }

    public static function money($value, bool $allowNegative = false): Money
    {
        if (is_float($value) || is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_STRING_REQUIRED');
        }
        if ($value === null || $value === '') {
            $value = '0';
        }

        return Money::from($value, $allowNegative);
    }

    public static function moneyString($value, bool $allowNegative = false): string
    {
        return self::money($value, $allowNegative)->toString();
    }

    public static function unitPriceString($value): string
    {
        if (is_float($value) || is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_STRING_REQUIRED');
        }
        if ($value === null || $value === '') {
            $value = '0';
        }

        return UnitPrice::from($value)->toString();
    }

    public static function quantityString($value): string
    {
        if (is_float($value) || is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('FINANCIAL_DECIMAL_STRING_REQUIRED');
        }
        if ($value === null || $value === '') {
            $value = '0';
        }

        return DecimalQuantity::from($value)->toString();
    }
}
