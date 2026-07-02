<?php

if (!function_exists('posmain_single_store_mode_enabled')) {
    /**
     * Single-store enforcement is always on for every shop deployment.
     */
    function posmain_single_store_mode_enabled(): bool
    {
        return true;
    }
}

if (!function_exists('posmain_settings_row_for_operational_store')) {
    function posmain_settings_row_for_operational_store(mysqli $conn): array
    {
        if (!function_exists('posmain_settings_column_exists') || !posmain_settings_column_exists($conn, 'def_pos_store')) {
            return [];
        }

        $result = $conn->query('SELECT id, def_pos_store FROM settings ORDER BY id ASC LIMIT 1');
        if (!$result || $result->num_rows === 0) {
            return [];
        }

        return $result->fetch_assoc() ?: [];
    }
}

if (!function_exists('posmain_operational_store_id')) {
    /**
     * Return settings.def_pos_store when it is a valid stock account.
     * is_operational_store is derived metadata only — never used as input here.
     */
    function posmain_operational_store_id(mysqli $conn): int
    {
        if (!function_exists('posmain_resolve_default_account_id')) {
            require_once __DIR__ . '/pos_default_accounts.php';
        }

        $settings = posmain_settings_row_for_operational_store($conn);
        $preferred = (int) ($settings['def_pos_store'] ?? 0);

        return posmain_resolve_default_account_id($conn, $preferred, 'is_stock = 1');
    }
}

if (!function_exists('posmain_operational_branch_scope')) {
    /**
     * Current shop branch scope from app config.
     *
     * @return array{pos_tenant:int,pos_branch:int,branch_uuid:?string}
     */
    function posmain_operational_branch_scope(): array
    {
        $config = function_exists('posmain_app_config') ? posmain_app_config() : [];
        $branch = is_array($config['branch'] ?? null) ? $config['branch'] : [];

        return [
            'pos_tenant' => (int) ($branch['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($branch['pos_branch'] ?? 0),
            'branch_uuid' => isset($branch['uuid']) ? (string) $branch['uuid'] : null,
        ];
    }
}

if (!function_exists('posmain_assert_operational_store_id')) {
    /**
     * In single-store mode coerce store_id=0 to the operational store and reject any other id.
     */
    function posmain_assert_operational_store_id(mysqli $conn, int $storeId): int
    {
        $operational = posmain_operational_store_id($conn);
        if (!posmain_single_store_mode_enabled()) {
            if ($storeId > 0) {
                return $storeId;
            }

            return $operational > 0 ? $operational : $storeId;
        }

        if ($operational < 1) {
            throw new RuntimeException('OPERATIONAL_STORE_NOT_CONFIGURED');
        }
        if ($storeId > 0 && $storeId !== $operational) {
            throw new InvalidArgumentException('NON_OPERATIONAL_STORE');
        }
        if ($storeId === 0) {
            return $operational;
        }

        return $operational;
    }
}

if (!function_exists('posmain_resolve_store_scope_for_read')) {
    /**
     * Default missing store_id to operational store (read paths: availability, reports).
     */
    function posmain_resolve_store_scope_for_read(mysqli $conn, array $context = []): array
    {
        $explicit = (int) ($context['store_id'] ?? $context['det_store'] ?? 0);
        $operational = posmain_operational_store_id($conn);

        if (posmain_single_store_mode_enabled() && $operational > 0) {
            $context['store_id'] = $operational;
        } elseif ($explicit > 0) {
            $context['store_id'] = $explicit;
        } elseif ($operational > 0) {
            $context['store_id'] = $operational;
        }

        return $context;
    }
}

if (!function_exists('posmain_apply_read_store_filter')) {
    /**
     * Coerce report/dashboard store_id filters to the operational store in single-store mode.
     */
    function posmain_apply_read_store_filter(mysqli $conn, int $storeId): int
    {
        if (!posmain_single_store_mode_enabled()) {
            return max(0, $storeId);
        }

        $scoped = posmain_resolve_store_scope_for_read($conn, ['store_id' => $storeId]);

        return (int) ($scoped['store_id'] ?? 0);
    }
}

if (!function_exists('posmain_resolve_store_scope_for_write')) {
    /**
     * Assert operational store on write paths (inventory, POS checkout).
     */
    function posmain_resolve_store_scope_for_write(mysqli $conn, array $context = []): array
    {
        $explicit = (int) ($context['store_id'] ?? $context['destination_store_id'] ?? $context['det_store'] ?? 0);
        if (posmain_single_store_mode_enabled()) {
            $context['store_id'] = posmain_assert_operational_store_id($conn, $explicit);
            if (array_key_exists('destination_store_id', $context)) {
                $context['destination_store_id'] = $context['store_id'];
            }
            if (array_key_exists('source_store_id', $context)) {
                $context['source_store_id'] = $context['store_id'];
            }
        } elseif ($explicit > 0) {
            $context['store_id'] = $explicit;
        } else {
            $operational = posmain_operational_store_id($conn);
            if ($operational > 0) {
                $context['store_id'] = $operational;
            }
        }

        return $context;
    }
}

if (!function_exists('posmain_list_operational_stores')) {
    /**
     * Stores visible in UI dropdowns (0–1 row in single-store mode).
     *
     * @return array<int, array{id:int, aname:string, code?:string}>
     */
    function posmain_list_operational_stores(mysqli $conn): array
    {
        $operationalId = posmain_operational_store_id($conn);
        if ($operationalId < 1) {
            return [];
        }

        $result = $conn->query(
            'SELECT id, aname, code FROM acc_head
             WHERE id = ' . (int) $operationalId . '
               AND COALESCE(isdeleted, 0) = 0
               AND COALESCE(is_stock, 0) = 1
             LIMIT 1'
        );
        if (!$result || $result->num_rows === 0) {
            return [];
        }

        $row = $result->fetch_assoc();

        return [[
            'id' => (int) ($row['id'] ?? 0),
            'aname' => (string) ($row['aname'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
        ]];
    }
}

if (!function_exists('posmain_sync_operational_store_flags')) {
    /**
     * Mark settings.def_pos_store as the only operational stock account.
     */
    function posmain_sync_operational_store_flags(mysqli $conn): void
    {
        if (!function_exists('posmain_acc_head_has_column') || !posmain_acc_head_has_column($conn, 'is_operational_store')) {
            return;
        }

        $settings = posmain_settings_row_for_operational_store($conn);
        $operationalId = posmain_resolve_default_account_id(
            $conn,
            (int) ($settings['def_pos_store'] ?? 0),
            'is_stock = 1'
        );
        if ($operationalId < 1) {
            return;
        }

        $conn->query('UPDATE acc_head SET is_operational_store = 0 WHERE COALESCE(is_stock, 0) = 1');
        $stmt = $conn->prepare('UPDATE acc_head SET is_operational_store = 1 WHERE id = ?');
        $stmt->bind_param('i', $operationalId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('posmain_inventory_transfers_allowed')) {
    function posmain_inventory_transfers_allowed(): bool
    {
        return !posmain_single_store_mode_enabled();
    }
}

if (!function_exists('posmain_pos_availability_scope')) {
    /**
     * POS/recipe availability scope with operational store_id for stock reads.
     */
    function posmain_pos_availability_scope(mysqli $conn, array $overrides = []): array
    {
        $branchConfig = posmain_operational_branch_scope();
        $scope = array_merge([
            'tenant' => (int) ($branchConfig['pos_tenant'] ?? 0),
            'branch' => (int) ($branchConfig['pos_branch'] ?? 0),
            'channel' => 'pos',
            'order_type' => 'takeaway',
        ], $overrides);

        return posmain_resolve_store_scope_for_read($conn, $scope);
    }
}

if (!function_exists('posmain_inventory_store_select_options')) {
    /**
     * Store rows for inventory/POS dropdowns (single operational store when enforced).
     *
     * @return array<int, array{id:int, aname:string}>
     */
    function posmain_inventory_store_select_options(mysqli $conn): array
    {
        if (posmain_single_store_mode_enabled()) {
            return array_map(static function (array $store): array {
                return [
                    'id' => (int) ($store['id'] ?? 0),
                    'aname' => (string) ($store['aname'] ?? ''),
                ];
            }, posmain_list_operational_stores($conn));
        }

        $rows = [];
        $result = $conn->query(
            'SELECT id, aname FROM acc_head
             WHERE COALESCE(isdeleted, 0) = 0
               AND COALESCE(is_stock, 0) = 1
             ORDER BY aname
             LIMIT 100'
        );
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'aname' => (string) ($row['aname'] ?? ''),
            ];
        }

        return $rows;
    }
}

if (!function_exists('posmain_inventory_enforce_operational_store_write')) {
    /**
     * Normalize inventory write payloads to the operational store; block transfers when disabled.
     *
     * @throws InvalidArgumentException
     */
    function posmain_inventory_enforce_operational_store_write(mysqli $conn, array $payload, string $operation = 'write'): array
    {
        if (!posmain_single_store_mode_enabled()) {
            return $payload;
        }

        if ($operation === 'transfer' || isset($payload['source_store_id'], $payload['destination_store_id'])) {
            if (!posmain_inventory_transfers_allowed()) {
                throw new InvalidArgumentException('INVENTORY_TRANSFERS_DISABLED');
            }
        }

        foreach (['store_id', 'destination_store_id', 'det_store', 'source_store_id'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $payload[$key] = posmain_assert_operational_store_id($conn, (int) $payload[$key]);
        }

        return $payload;
    }
}

if (!function_exists('posmain_inventory_discovered_branch_scopes')) {
    /**
     * Distinct tenant/branch pairs present in inventory tables for this shop DB.
     *
     * @return array<int, array{pos_tenant:int,pos_branch:int}>
     */
    function posmain_inventory_discovered_branch_scopes(mysqli $conn): array
    {
        $scopes = [];
        $seen = [];
        $current = posmain_operational_branch_scope();
        $scopes[] = ['pos_tenant' => (int) $current['pos_tenant'], 'pos_branch' => (int) $current['pos_branch']];
        $seen[(int) $current['pos_tenant'] . ':' . (int) $current['pos_branch']] = true;

        if (!function_exists('inventorySingleStorePreflightTableExists')) {
            return $scopes;
        }
        if (!inventorySingleStorePreflightTableExists($conn, 'inventory_item_balances')) {
            return $scopes;
        }

        $result = $conn->query('SELECT DISTINCT pos_tenant, pos_branch FROM inventory_item_balances ORDER BY pos_tenant, pos_branch');
        while ($result && ($row = $result->fetch_assoc())) {
            $key = (int) ($row['pos_tenant'] ?? 0) . ':' . (int) ($row['pos_branch'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $scopes[] = [
                'pos_tenant' => (int) ($row['pos_tenant'] ?? 0),
                'pos_branch' => (int) ($row['pos_branch'] ?? 0),
            ];
        }

        return $scopes;
    }
}
