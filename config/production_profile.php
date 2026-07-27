<?php

if (!function_exists('posmain_production_profile_matrix')) {
    /**
     * Default production capability matrix by role.
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
                'legacy_mirror' => false,
                'strict_stock' => true,
                'reservations' => true,
                'accounting' => true,
                'availability' => true,
            ],
            'recipe' => [
                'mode' => 'full',
                'shadow_ledger' => false,
                'reservations' => true,
                'consumption' => true,
                'accounting' => true,
                'availability' => true,
                'moova_sync' => !$isCloud,
                'strict_stock' => true,
            ],
            'single_store' => true,
            'financial' => [
                'certified_mode' => true,
                'tax_enabled' => false,
            ],
            'sync' => [
                'cloud_pull_enabled' => false,
                'cloud_to_branch_publish_enabled' => false,
            ],
        ];
    }
}

if (!function_exists('posmain_production_profile_apply')) {
    /**
     * Apply production defaults into resolved config.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    function posmain_production_profile_apply(array $config): array
    {
        $role = strtolower(trim((string) ($config['role'] ?? 'branch')));
        $matrix = posmain_production_profile_matrix($role);
        $warnings = is_array($config['production_profile_warnings'] ?? null) ? $config['production_profile_warnings'] : [];
        $inventoryCertified = !empty($config['inventory']['cutover_certified']);
        $recipeCertified = !empty($config['recipe']['rollout_certified']);

        $requestedInventoryMode = strtolower((string) ($config['inventory']['ledger_mode'] ?? 'off'));
        if ($inventoryCertified) {
            $config['inventory']['ledger_mode'] = (string) $matrix['inventory']['ledger_mode'];
            $config['inventory']['legacy_mirror'] = false;
            $config['inventory']['strict_stock'] = !empty($matrix['inventory']['strict_stock']);
            $config['inventory']['reservations'] = !empty($matrix['inventory']['reservations']);
            $config['inventory']['accounting'] = !empty($matrix['inventory']['accounting']);
            $config['inventory']['availability'] = !empty($matrix['inventory']['availability']);
        } else {
            // A production flag must never silently turn an uncut legacy
            // database into the live ledger. Shadow preserves comparison
            // evidence while preventing the new ledger from becoming the
            // authoritative stock read.
            $config['inventory']['ledger_mode'] = 'shadow';
            $config['inventory']['legacy_mirror'] = true;
            $config['inventory']['strict_stock'] = false;
            $config['inventory']['reservations'] = false;
            // Shadow evidence must never create production journals.
            $config['inventory']['accounting'] = false;
            $config['inventory']['availability'] = false;
            $warnings[] = 'production_profile_inventory_blocked_until_cutover_certified';
            if ($requestedInventoryMode === 'live') {
                $warnings[] = 'production_profile_uncertified_live_inventory_request_rejected';
            }
        }
        $config['financial']['certified_mode'] = true;
        if (!empty($config['tax']['enabled'])) {
            $warnings[] = 'production_profile_tax_forced_off';
        }
        $config['tax']['enabled'] = false;
        $config['tax']['rate'] = '0.00';
        $config['tax']['inclusive'] = false;
        if (!empty($config['sync']['cloud_pull_enabled'])) {
            $warnings[] = 'production_profile_automatic_cloud_pull_disabled';
        }
        if (!empty($config['sync']['cloud_to_branch_publish_enabled'])) {
            $warnings[] = 'production_profile_automatic_reverse_publish_disabled';
        }
        $config['sync']['cloud_pull_enabled'] = false;
        $config['sync']['cloud_to_branch_publish_enabled'] = false;

        $requestedRecipeMode = strtolower((string) ($config['recipe']['mode'] ?? 'off'));
        if ($inventoryCertified && $recipeCertified) {
            $config['recipe']['mode'] = (string) $matrix['recipe']['mode'];
            $config['recipe']['enabled'] = true;
            $config['recipe']['shadow_ledger'] = false;
            foreach (['reservations', 'consumption', 'accounting', 'availability'] as $flag) {
                $config['recipe'][$flag] = !empty($matrix['recipe'][$flag]);
            }
        } else {
            // Recipe definitions remain inspectable but cannot reserve,
            // consume, value, or hide menu items before both inventory
            // cutover and recipe rollout evidence are certified.
            $config['recipe']['mode'] = 'read_only';
            $config['recipe']['enabled'] = true;
            $config['recipe']['shadow_ledger'] = true;
            foreach (['reservations', 'consumption', 'accounting', 'availability', 'moova_sync', 'strict_stock'] as $flag) {
                $config['recipe'][$flag] = false;
            }
            $warnings[] = !$inventoryCertified
                ? 'production_profile_recipe_blocked_until_inventory_cutover_certified'
                : 'production_profile_recipe_blocked_until_rollout_certified';
            if ($requestedRecipeMode === 'full') {
                $warnings[] = 'production_profile_uncertified_full_recipe_request_rejected';
            }
        }
        if (!$inventoryCertified || !$recipeCertified) {
            $config['recipe']['moova_sync'] = false;
        } elseif ($role === 'cloud' && !empty($config['recipe']['moova_sync'])) {
            $warnings[] = 'production_profile_cloud_recipe_moova_sync_disabled_by_role';
            $config['recipe']['moova_sync'] = false;
        } elseif ($role !== 'cloud' && empty($config['recipe']['moova_sync']) && !empty($matrix['recipe']['moova_sync'])) {
            $config['recipe']['moova_sync'] = true;
        }

        $config['production_profile'] = [
            'enabled' => true,
            'role' => $role,
            'matrix' => $matrix,
            'inventory_cutover_certified' => $inventoryCertified,
            'recipe_rollout_certified' => $recipeCertified,
            'inventory_activation_blocked' => !$inventoryCertified,
            'recipe_activation_blocked' => !($inventoryCertified && $recipeCertified),
        ];
        if ($warnings) {
            $config['production_profile_warnings'] = array_values(array_unique($warnings));
        }

        return $config;
    }
}
