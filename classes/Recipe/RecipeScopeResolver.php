<?php

require_once __DIR__ . '/DTO/RecipeScope.php';

class RecipeScopeResolver
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function resolve(array $context = []): RecipeScope
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];

        return new RecipeScope(
            $this->nonNegativeInt($context['pos_tenant'] ?? $context['tenant'] ?? $branchConfig['pos_tenant'] ?? 0),
            $this->nonNegativeInt($context['pos_branch'] ?? $context['branch'] ?? $branchConfig['pos_branch'] ?? 0),
            $this->nullableString($context['branch_uuid'] ?? $branchConfig['uuid'] ?? null),
            $this->nonNegativeInt($context['store_id'] ?? $context['det_store'] ?? 0),
            (string) ($context['channel'] ?? 'pos'),
            (string) ($context['order_type'] ?? 'takeaway'),
            (string) ($context['source'] ?? $context['source_system'] ?? 'pos')
        );
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
}

