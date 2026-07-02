<?php

final class ItemUnitConversionFeatureFlags
{
    public static function strictPosFactorResolution(): bool
    {
        return self::flag('ERP_STRICT_POS_FACTOR', true);
    }

    public static function exactDecimalConversions(): bool
    {
        return self::flag('ERP_EXACT_DECIMAL_CONVERSIONS', true);
    }

    public static function appendOnlyJournals(): bool
    {
        return self::flag('ERP_APPEND_ONLY_JOURNALS', true);
    }

    public static function strictSideEffects(): bool
    {
        $configured = getenv('POSMAIN_SIDE_EFFECT_MODE');
        if (is_string($configured) && strtolower(trim($configured)) === 'live') {
            return true;
        }

        return self::flag('ERP_STRICT_SIDE_EFFECTS', false);
    }

    private static function flag(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
