<?php

require_once __DIR__ . '/../../Recipe/RecipeDecimal.php';

class LegacyOrderLinePresentationService
{
    public function presentSaleLine(array $row): array
    {
        $unitValue = $this->positiveDecimal($row['u_val'] ?? '1');
        $stockQuantity = $this->stockQuantity($row['qty_in'] ?? '0', $row['qty_out'] ?? '0');
        $sellQuantity = RecipeDecimal::compare($stockQuantity, '0') > 0
            ? $this->divideScaledDecimal($stockQuantity, $unitValue)
            : RecipeDecimal::zero();

        return [
            'qty' => $sellQuantity,
            'price' => $this->multiplyScaledDecimal($row['price'] ?? '0', $unitValue),
            'u_val' => $unitValue,
        ];
    }

    public function inputValue($decimal, int $scale = 6): string
    {
        $normalized = RecipeDecimal::normalize($decimal, $scale);
        if (strpos($normalized, '.') === false) {
            return $normalized;
        }

        return rtrim(rtrim($normalized, '0'), '.') ?: '0';
    }

    private function stockQuantity($qtyIn, $qtyOut): string
    {
        $qtyIn = RecipeDecimal::normalize($qtyIn);
        $qtyOut = RecipeDecimal::normalize($qtyOut);

        return RecipeDecimal::compare($qtyOut, $qtyIn) >= 0
            ? $this->subtractScaledDecimal($qtyOut, $qtyIn)
            : $this->subtractScaledDecimal($qtyIn, $qtyOut);
    }

    private function positiveDecimal($value): string
    {
        $decimal = RecipeDecimal::normalize($value);

        return RecipeDecimal::compare($decimal, '0') > 0 ? $decimal : '1.000000';
    }

    private function divideScaledDecimal(string $left, string $right): string
    {
        if (RecipeDecimal::compare($right, '0') <= 0) {
            return RecipeDecimal::zero();
        }

        $dividend = $this->scaledInteger($left) . '000000';
        $divisor = $this->scaledInteger($right);

        return $this->decimalFromScaledInteger($this->divideIntegerRounded($dividend, $divisor));
    }

    private function multiplyScaledDecimal($left, $right): string
    {
        $leftInt = $this->scaledInteger(RecipeDecimal::normalize($left));
        $rightInt = $this->scaledInteger(RecipeDecimal::normalize($right));

        return RecipeDecimal::normalize($this->decimalFromScaledInteger($this->multiplyIntegerStrings($leftInt, $rightInt), 12));
    }

    private function subtractScaledDecimal(string $left, string $right): string
    {
        return $this->decimalFromScaledInteger(
            $this->subtractIntegerStrings(
                $this->scaledInteger($left),
                $this->scaledInteger($right)
            )
        );
    }

    private function scaledInteger(string $decimal): string
    {
        $digits = str_replace(['-', '.'], '', RecipeDecimal::normalize($decimal));

        return ltrim($digits, '0') ?: '0';
    }

    private function decimalFromScaledInteger(string $scaled, int $scale = 6): string
    {
        $scaled = ltrim($scaled, '0') ?: '0';
        if (strlen($scaled) <= $scale) {
            return RecipeDecimal::normalize('0.' . str_pad($scaled, $scale, '0', STR_PAD_LEFT), $scale);
        }

        return RecipeDecimal::normalize(
            substr($scaled, 0, -$scale) . '.' . substr($scaled, -$scale),
            $scale
        );
    }

    private function divideIntegerRounded(string $dividend, string $divisor): string
    {
        $dividend = ltrim($dividend, '0') ?: '0';
        $divisor = ltrim($divisor, '0') ?: '0';
        if ($divisor === '0') {
            return '0';
        }

        $quotient = '';
        $remainder = '0';
        $length = strlen($dividend);
        for ($i = 0; $i < $length; $i++) {
            $remainder = ltrim($remainder . $dividend[$i], '0') ?: '0';
            $digit = 0;
            while ($this->compareIntegerStrings($remainder, $divisor) >= 0) {
                $remainder = $this->subtractIntegerStrings($remainder, $divisor);
                $digit++;
            }
            $quotient .= (string) $digit;
        }

        if ($this->compareIntegerStrings($this->addIntegerStrings($remainder, $remainder), $divisor) >= 0) {
            $quotient = $this->addIntegerStrings($quotient, '1');
        }

        return ltrim($quotient, '0') ?: '0';
    }

    private function multiplyIntegerStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $mul = ((int) $left[$i]) * ((int) $right[$j]);
                $sum = $mul + $result[$i + $j + 1];
                $result[$i + $j + 1] = $sum % 10;
                $result[$i + $j] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private function compareIntegerStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }

        return $left <=> $right;
    }

    private function subtractIntegerStrings(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0) {
            $digit = (int) $left[$i] - $borrow;
            $subtrahend = $j >= 0 ? (int) $right[$j] : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) ($digit - $subtrahend) . $result;
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function addIntegerStrings(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $left[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $right[$j--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}
