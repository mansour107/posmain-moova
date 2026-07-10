<?php

require_once __DIR__ . '/Decimal.php';

final class TaxRate
{
    public const SCALE = 6;
    private string $percent;

    private function __construct(string $percent) { $this->percent = $percent; }
    public static function from($percent): self { return new self(FinancialDecimal::normalize($percent, self::SCALE)); }
    public function toString(): string { return $this->percent; }
}
