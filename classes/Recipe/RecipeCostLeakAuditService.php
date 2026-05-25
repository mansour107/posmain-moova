<?php

require_once __DIR__ . '/RecipeFeatureFlags.php';

class RecipeCostLeakAuditService
{
    private const SENSITIVE_KEYS = [
        'cost',
        'cost_price',
        'stock_value',
        'profit',
        'margin',
        'unit_cost',
        'total_cost',
        'ingredient_cost_json',
        'purchase_price',
        'last_price',
        'recipe_cost_snapshot',
        'recipe_cost_snapshot_id',
        'moving_average_cost',
        'last_purchase_cost',
        'supplier_cost',
        'internal_cost_per_sell_unit',
    ];

    private const PUBLIC_CLASSIFICATIONS = [
        'customer facing api',
        'moova facing api',
        'public ish menu payload',
        'public',
        'customer',
        'moova',
    ];

    public function sensitiveKeys(): array
    {
        return self::SENSITIVE_KEYS;
    }

    public function sensitivePaths(array $payload): array
    {
        $paths = [];
        $this->collectSensitivePaths($payload, '', $paths);

        return $paths;
    }

    public function hasSensitiveFields(array $payload): bool
    {
        return $this->sensitivePaths($payload) !== [];
    }

    public function sanitizePayload(array $payload, string $classification, ?RecipeFeatureFlags $flags = null): array
    {
        if (!$this->shouldMask($classification, $flags)) {
            return $payload;
        }

        return $this->maskSensitiveKeys($payload);
    }

    private function shouldMask(string $classification, ?RecipeFeatureFlags $flags): bool
    {
        $normalized = strtolower(trim(str_replace(['_', '-'], ' ', $classification)));
        if (in_array($normalized, self::PUBLIC_CLASSIFICATIONS, true)) {
            return $flags === null || !$flags->canExposeCostsToPayload('internal_recipe_analytics');
        }

        if ($normalized === 'internal recipe analytics' && $flags !== null) {
            return !$flags->canExposeCostsToPayload('internal_recipe_analytics');
        }

        return false;
    }

    private function maskSensitiveKeys(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->maskSensitiveKeys($value) : $value;
        }

        return $sanitized;
    }

    private function collectSensitivePaths(array $payload, string $prefix, array &$paths): void
    {
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if ($this->isSensitiveKey((string) $key)) {
                $paths[] = $path;
            }

            if (is_array($value)) {
                $this->collectSensitivePaths($value, $path, $paths);
            }
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);
        $sensitive = array_map(function (string $sensitiveKey): string {
            return $this->normalizeKey($sensitiveKey);
        }, self::SENSITIVE_KEYS);

        return in_array($normalized, $sensitive, true);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($key))) ?: '';
    }
}
