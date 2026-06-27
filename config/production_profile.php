<?php

if (!function_exists('posmain_production_profile_enabled')) {
    function posmain_production_profile_enabled(): bool
    {
        return function_exists('posmain_bool')
            && posmain_bool(posmain_env('POSMAIN_USE_PRODUCTION_PROFILE', '0'), false);
    }
}

if (!function_exists('posmain_production_profile_matrix')) {
    /**
     * Target production capability matrix by role. Env keys remain compatibility overrides.
     *
     * @return array<string,mixed>
     */
    function posmain_production_profile_matrix(string $role): array
    {
        $role = strtolower(trim($role));
        $isCloud = $role === 'cloud';

        return [
            'inventory' => [
                'ledger_mode' => 'live',
                'legacy_mirror' => true,
            ],
            'recipe' => [
                'mode' => 'full',
                'shadow_ledger' => true,
                'reservations' => true,
                'consumption' => true,
                'accounting' => true,
                'availability' => true,
                'moova_sync' => !$isCloud,
                'strict_stock' => false,
            ],
            'single_store' => true,
        ];
    }
}

if (!function_exists('posmain_production_profile_apply')) {
    /**
     * Merge production profile defaults into resolved config without deleting env overrides.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    function posmain_production_profile_apply(array $config): array
    {
        if (!posmain_production_profile_enabled()) {
            return $config;
        }

        $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
        $matrix = posmain_production_profile_matrix($role);
        $warnings = is_array($config['production_profile_warnings'] ?? null) ? $config['production_profile_warnings'] : [];

        foreach (['off', 'shadow', 'bridge'] as $legacyMode) {
            if (strtolower((string) ($config['inventory']['ledger_mode'] ?? '')) === $legacyMode) {
                $config['inventory']['ledger_mode'] = (string) $matrix['inventory']['ledger_mode'];
            }
        }
        if (empty($config['inventory']['legacy_mirror']) && !empty($matrix['inventory']['legacy_mirror'])) {
            $config['inventory']['legacy_mirror'] = true;
        }

        $recipeMode = strtolower((string) ($config['recipe']['mode'] ?? 'off'));
        if ($recipeMode !== 'full' && in_array($recipeMode, ['off', 'schema_only', 'read_only', 'shadow', 'reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'], true)) {
            $config['recipe']['mode'] = (string) $matrix['recipe']['mode'];
            $config['recipe']['enabled'] = true;
        }
        foreach (['reservations', 'consumption', 'accounting', 'availability', 'shadow_ledger'] as $flag) {
            if (empty($config['recipe'][$flag]) && !empty($matrix['recipe'][$flag])) {
                $config['recipe'][$flag] = true;
            }
        }
        if ($role === 'cloud' && !empty($config['recipe']['moova_sync'])) {
            $warnings[] = 'production_profile_cloud_recipe_moova_sync_disabled_by_role';
            $config['recipe']['moova_sync'] = false;
        } elseif ($role !== 'cloud' && empty($config['recipe']['moova_sync']) && !empty($matrix['recipe']['moova_sync'])) {
            $config['recipe']['moova_sync'] = true;
        }

        $config['production_profile'] = [
            'enabled' => true,
            'role' => $role,
            'matrix' => $matrix,
        ];
        if ($warnings) {
            $config['production_profile_warnings'] = array_values(array_unique($warnings));
        }

        return $config;
    }
}
