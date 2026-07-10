<?php

require_once __DIR__ . '/Decimal.php';

final class DecimalQuantity
{
    public const SCALE = 6;
    private string $value;

    private function __construct(string $value) { $this->value = $value; }
    public static function from($value): self { return new self(FinancialDecimal::normalize($value, self::SCALE)); }

    public static function fromLegacy($value): self
    {
        if (is_int($value)) {
            $value = sprintf('%d', $value);
        } elseif (is_float($value)) {
            $value = sprintf('%.6F', $value);
        }

        return self::from($value);
    }

    public function toString(): string { return $this->value; }
}
