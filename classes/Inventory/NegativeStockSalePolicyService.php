<?php

final class NegativeStockSalePolicyService
{
    public const BLOCK = 'block';
    public const ALLOW_WITH_WARNING = 'allow_with_warning';

    public function __construct(?array $config = null)
    {
        // Keep the legacy constructor contract for existing callers. The V1
        // sale policy is product-level and no longer varies by configuration.
    }

    public function resolve(mysqli $conn): string
    {
        // Commercial V1 product policy: stock is an operational signal and
        // may not stop an otherwise valid sale. Keep the legacy setting
        // readable in the schema, but never let it become an effective sale
        // gate.
        return self::ALLOW_WITH_WARNING;
    }

    public function normalize($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, [self::BLOCK, self::ALLOW_WITH_WARNING], true)) {
            throw new InvalidArgumentException('NEGATIVE_STOCK_SALE_POLICY_INVALID');
        }

        // Accept the legacy enum value at input boundaries so older forms and
        // databases remain compatible, while persisting the only effective V1
        // policy.
        return self::ALLOW_WITH_WARNING;
    }
}
