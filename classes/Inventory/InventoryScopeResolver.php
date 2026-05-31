<?php

class InventoryScopeResolver
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function resolve(array $context = []): array
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];

        return [
            'pos_tenant' => $this->nonNegativeInt($context['pos_tenant'] ?? $context['tenant'] ?? $branchConfig['pos_tenant'] ?? 0),
            'pos_branch' => $this->nonNegativeInt($context['pos_branch'] ?? $context['branch'] ?? $branchConfig['pos_branch'] ?? 0),
            'branch_uuid' => $this->nullableString($context['branch_uuid'] ?? $branchConfig['uuid'] ?? null),
            'store_id' => $this->nonNegativeInt($context['store_id'] ?? $context['det_store'] ?? 0),
            'channel' => $this->normalizeToken((string) ($context['channel'] ?? 'pos'), 'pos'),
            'order_type' => $this->normalizeToken((string) ($context['order_type'] ?? 'takeaway'), 'takeaway'),
            'source' => $this->normalizeToken((string) ($context['source'] ?? $context['source_system'] ?? 'pos'), 'pos'),
        ];
    }

    private function nonNegativeInt($value): int
    {
        $int = (int) $value;

        return $int < 0 ? 0 : $int;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizeToken(string $value, string $default): string
    {
        $token = strtolower(trim($value));
        $token = str_replace(['-', ' '], '_', $token);

        return preg_match('/^[a-z0-9_]{1,40}$/', $token) === 1 ? $token : $default;
    }
}
