<?php

class RecipeDecimal
{
    public static function normalize($value, int $scale = 6): string
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            $text = '0';
        }

        $negative = $text[0] === '-';
        if ($negative) {
            $text = substr($text, 1);
        }

        $parts = explode('.', $text, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $parts[1] ?? '';
        $fraction = substr(str_pad($fraction, $scale + 1, '0'), 0, $scale + 1);

        if (strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            $rounded = self::addScaled($whole . substr($fraction, 0, $scale), '1');
            $rounded = str_pad($rounded, $scale + 1, '0', STR_PAD_LEFT);
            $whole = ltrim(substr($rounded, 0, -$scale), '0');
            $whole = $whole === '' ? '0' : $whole;
            $fraction = substr($rounded, -$scale);
        } else {
            $fraction = substr($fraction, 0, $scale);
        }

        return ($negative && ($whole !== '0' || trim($fraction, '0') !== '') ? '-' : '')
            . $whole
            . ($scale > 0 ? '.' . str_pad($fraction, $scale, '0') : '');
    }

    public static function zero(int $scale = 6): string
    {
        return self::normalize('0', $scale);
    }

    public static function isPositive($value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function add($left, $right, int $scale = 6): string
    {
        self::requireBcMath();

        return self::normalize(bcadd(self::normalize($left, $scale), self::normalize($right, $scale), $scale + 4), $scale);
    }

    public static function subtract($left, $right, int $scale = 6): string
    {
        self::requireBcMath();

        return self::normalize(bcsub(self::normalize($left, $scale), self::normalize($right, $scale), $scale + 4), $scale);
    }

    public static function multiply($left, $right, int $scale = 6): string
    {
        self::requireBcMath();

        return self::normalize(bcmul(self::normalize($left, $scale), self::normalize($right, $scale), $scale + 8), $scale);
    }

    public static function divide($left, $right, int $scale = 6): string
    {
        self::requireBcMath();
        if (self::compare($right, '0', $scale) === 0) {
            throw new InvalidArgumentException('Cannot divide recipe decimal by zero.');
        }

        return self::normalize(bcdiv(self::normalize($left, $scale), self::normalize($right, $scale), $scale + 8), $scale);
    }

    public static function applyPercent($value, $percent, int $scale = 6): string
    {
        self::requireBcMath();
        $factor = bcadd('1', bcdiv(self::normalize($percent, 4), '100', $scale + 8), $scale + 8);

        return self::multiply($value, $factor, $scale);
    }

    public static function floorDivideToInt($left, $right, int $scale = 6): int
    {
        self::requireBcMath();
        if (self::compare($right, '0', $scale) <= 0) {
            throw new InvalidArgumentException('Cannot floor-divide recipe decimal by zero or negative value.');
        }
        if (self::compare($left, '0', $scale) <= 0) {
            return 0;
        }

        return (int) bcdiv(self::normalize($left, $scale), self::normalize($right, $scale), 0);
    }

    public static function compare($left, $right, int $scale = 6): int
    {
        $leftNormalized = self::normalize($left, $scale);
        $rightNormalized = self::normalize($right, $scale);
        $leftNegative = strpos($leftNormalized, '-') === 0;
        $rightNegative = strpos($rightNormalized, '-') === 0;

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $leftInt = self::scaledInteger($leftNormalized);
        $rightInt = self::scaledInteger($rightNormalized);
        $comparison = self::compareIntegerStrings($leftInt, $rightInt);

        return $leftNegative ? -$comparison : $comparison;
    }

    private static function scaledInteger(string $decimal): string
    {
        $digits = str_replace(['-', '.'], '', $decimal);

        return ltrim($digits, '0') ?: '0';
    }

    private static function compareIntegerStrings(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }

        return $left <=> $right;
    }

    private static function addScaled(string $digits, string $increment): string
    {
        $carry = 0;
        $result = '';
        $i = strlen($digits) - 1;
        $j = strlen($increment) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $digits[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $increment[$j--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function requireBcMath(): void
    {
        if (!function_exists('bcadd')) {
            throw new RuntimeException('Recipe decimal math requires the PHP bcmath extension.');
        }
    }
}
