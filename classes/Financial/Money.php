<?php

require_once __DIR__ . '/Decimal.php';

final class Money
{
    public const SCALE = 2;

    private string $amount;

    private function __construct(string $amount)
    {
        $this->amount = $amount;
    }

    public static function from($amount, bool $allowNegative = false): self
    {
        return new self(FinancialDecimal::normalize($amount, self::SCALE, $allowNegative));
    }

    /**
     * Boundary adapter for legacy callers that still pass PHP numbers.
     * Certified request paths must use from() with decimal strings.
     */
    public static function fromLegacy($amount, bool $allowNegative = false): self
    {
        if (is_int($amount)) {
            $amount = sprintf('%d.00', $amount);
        } elseif (is_float($amount)) {
            $amount = sprintf('%.2F', $amount);
        }

        return self::from($amount, $allowNegative);
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public function add(self $other): self
    {
        return new self(FinancialDecimal::add($this->amount, $other->amount, self::SCALE));
    }

    public function subtract(self $other): self
    {
        return new self(FinancialDecimal::subtract($this->amount, $other->amount, self::SCALE));
    }

    public function isPositive(): bool
    {
        return FinancialDecimal::compare($this->amount, '0.00', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return FinancialDecimal::compare($this->amount, '0.00', self::SCALE) < 0;
    }

    public function compare(self $other): int
    {
        return FinancialDecimal::compare($this->amount, $other->amount, self::SCALE);
    }

    public function toString(): string
    {
        return $this->amount;
    }
}
