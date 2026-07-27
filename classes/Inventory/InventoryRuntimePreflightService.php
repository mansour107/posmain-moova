<?php

require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/../Sync/SchemaManager.php';

class InventoryRuntimePreflightService
{
    private const REQUIRED_TABLES = [
        'inventory_movements',
        'inventory_item_balances',
        'journal_heads',
        'journal_entries',
        'document_counters',
        'acc_head',
        'sync_outbox',
    ];

    private const REQUIRED_ACCOUNT_KEYS = [
        'inventory_asset_account_id',
        'purchase_clearing_account_id',
        'cogs_account_id',
        'waste_expense_account_id',
        'adjustment_gain_loss_account_id',
    ];

    public function check(mysqli $conn, InventoryFeatureFlags $flags): array
    {
        $config = $flags->config();
        $blockers = [];
        $missingTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!$this->tableExists($conn, $table)) {
                $missingTables[] = $table;
            }
        }
        if ($missingTables) {
            $blockers[] = 'inventory_runtime_schema_missing_tables';
        }

        $pending = (new SyncSchemaManager())->pendingStatements($conn);
        if ($pending) {
            $blockers[] = 'inventory_runtime_schema_pending_migrations';
        }

        $live = $flags->canWriteLedger();
        if ($live && !$flags->isAccountingEnabled()) {
            $blockers[] = 'inventory_runtime_live_requires_accounting';
        }
        if ($live && !$flags->isReservationEnabled()) {
            $blockers[] = 'inventory_runtime_live_requires_reservations';
        }
        if ($live && !$flags->isAvailabilityEnabled()) {
            $blockers[] = 'inventory_runtime_live_requires_availability';
        }
        if ($live && $flags->shouldMirrorLegacyStock()) {
            $blockers[] = 'inventory_runtime_live_legacy_mirror_must_be_disabled';
        }

        $accountRows = [];
        $accounts = is_array($config['accounts'] ?? null) ? $config['accounts'] : [];
        foreach (self::REQUIRED_ACCOUNT_KEYS as $key) {
            $accountId = (int) ($accounts[$key] ?? 0);
            if ($key === 'inventory_asset_account_id' && $accountId < 1) {
                $accountId = (int) ($accounts['inventory_account_id'] ?? 0);
            }
            $active = $accountId > 0 && $this->activeAccountExists($conn, $accountId);
            $accountRows[$key] = [
                'account_id' => $accountId,
                'active' => $active,
            ];
            if ($live && !$active) {
                $blockers[] = 'inventory_runtime_account_missing_or_inactive:' . $key;
            }
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'ok' => $blockers === [],
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $flags->mode(),
            'accounting_enabled' => $flags->isAccountingEnabled(),
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
