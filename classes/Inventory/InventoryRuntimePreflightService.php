<?php

require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/../Sync/SchemaManager.php';

class InventoryRuntimePreflightService
{
    private const QUANTITY_TABLES = [
        'inventory_movements',
        'inventory_item_balances',
    ];

    private const ACCOUNTING_TABLES = [
        'journal_heads',
        'journal_entries',
        'document_counters',
        'acc_head',
    ];

    private const SYNC_TABLES = [
        'sync_outbox',
    ];

    private const CORE_ACCOUNT_KEYS = [
        'inventory_asset_account_id',
        'cogs_account_id',
    ];

    private const CONDITIONAL_ACCOUNT_KEYS = [
        'purchase_clearing_account_id',
        'waste_expense_account_id',
        'adjustment_gain_loss_account_id',
    ];

    public function check(mysqli $conn, InventoryFeatureFlags $flags): array
    {
        $config = $flags->config();
        $blockers = [];
        $missingTables = [];
        $quantityEnabled = $flags->isQuantityTrackingEnabled();
        $shadowEnabled = $flags->isShadowMode();
        $accountingEnabled = $flags->isAccountingEnabled();
        $reservationsEnabled = $flags->isReservationEnabled();
        $availabilityEnabled = $flags->isAvailabilityEnabled();
        $syncEnabled = $flags->isSyncEnabled();
        $requiredTables = [];
        if ($quantityEnabled || $shadowEnabled || $reservationsEnabled || $availabilityEnabled) {
            $requiredTables = array_merge($requiredTables, self::QUANTITY_TABLES);
        }
        if ($accountingEnabled) {
            $requiredTables = array_merge($requiredTables, self::ACCOUNTING_TABLES);
        }
        if ($syncEnabled) {
            $requiredTables = array_merge($requiredTables, self::SYNC_TABLES);
        }

        foreach (array_values(array_unique($requiredTables)) as $table) {
            if (!$this->tableExists($conn, $table)) {
                $missingTables[] = $table;
            }
        }
        if ($missingTables) {
            $blockers[] = 'inventory_runtime_schema_missing_tables';
        }

        $pending = $flags->isEnabled() ? (new SyncSchemaManager())->pendingStatements($conn) : [];
        if ($pending) {
            $blockers[] = 'inventory_runtime_schema_pending_migrations';
        }

        if ($accountingEnabled && !$quantityEnabled) {
            $blockers[] = 'inventory_runtime_accounting_requires_quantity_tracking';
        }
        if ($reservationsEnabled && !$quantityEnabled) {
            $blockers[] = 'inventory_runtime_reservations_require_quantity_tracking';
        }
        if ($availabilityEnabled && !$quantityEnabled) {
            $blockers[] = 'inventory_runtime_availability_requires_quantity_tracking';
        }
        if ($quantityEnabled && $flags->shouldMirrorLegacyStock()) {
            $blockers[] = 'inventory_runtime_live_legacy_mirror_must_be_disabled';
        }

        $accountRows = [];
        $accounts = is_array($config['accounts'] ?? null) ? $config['accounts'] : [];
        $accountKeys = array_merge(self::CORE_ACCOUNT_KEYS, self::CONDITIONAL_ACCOUNT_KEYS);
        foreach ($accountKeys as $key) {
            $accountId = (int) ($accounts[$key] ?? 0);
            if ($key === 'inventory_asset_account_id' && $accountId < 1) {
                $accountId = (int) ($accounts['inventory_account_id'] ?? 0);
            }
            $active = $accountId > 0 && $this->activeAccountExists($conn, $accountId);
            $accountRows[$key] = [
                'account_id' => $accountId,
                'active' => $active,
            ];
            if ($accountingEnabled && in_array($key, self::CORE_ACCOUNT_KEYS, true) && !$active) {
                $blockers[] = 'inventory_runtime_account_missing_or_inactive:' . $key;
            }
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $flags->mode(),
            'quantity_tracking_enabled' => $quantityEnabled,
            'accounting_enabled' => $accountingEnabled,
            'reservations_enabled' => $reservationsEnabled,
            'availability_enabled' => $availabilityEnabled,
            'sync_enabled' => $syncEnabled,
            'missing_tables' => $missingTables,
            'pending_migration_count' => count($pending),
            'pending_migration_labels' => array_keys($pending),
            'accounts' => $accountRows,
            'blockers' => $blockers,
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function activeAccountExists(mysqli $conn, int $accountId): bool
    {
        if (!$this->tableExists($conn, 'acc_head')) {
            return false;
        }
        $stmt = $conn->prepare('SELECT id FROM acc_head WHERE id = ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }
}
