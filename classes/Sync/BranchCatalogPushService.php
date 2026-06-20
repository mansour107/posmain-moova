<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/BranchSyncWorker.php';
require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/OperationalSyncEventService.php';
require_once __DIR__ . '/SyncOutboxEventService.php';

class BranchCatalogPushService
{
    public function pushToHosted(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $this->assertCanPush($config);
        $pushConfig = $this->pushConfig($config);
        $identity = (new SyncBranchIdentity())->ensure($conn, $pushConfig);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));

        $includeCatalog = array_key_exists('catalog', $options) ? !empty($options['catalog']) : true;
        $includeTables = array_key_exists('tables', $options) ? !empty($options['tables']) : true;
        $includeOrders = array_key_exists('orders', $options) ? !empty($options['orders']) : true;
        $includeOperational = array_key_exists('operational', $options) ? !empty($options['operational']) : true;
        $includeDeleted = !empty($options['include_deleted']);
        $forceResend = !empty($options['force_resend']);
        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;

        $queue = [
            'catalog' => 0,
            'catalog_inventory_refs' => 0,
            'tables' => 0,
            'orders' => 0,
            'item_categories' => 0,
            'inventory_balances' => 0,
            'inventory_stock_levels' => 0,
            'inventory_movements' => 0,
            'recipes' => 0,
            'employees' => 0,
            'pulse_logs' => 0,
            'pulse_types' => 0,
            'queued' => 0,
            'skipped' => 0,
            'resent' => 0,
        ];

        $outbox = new SyncOutboxEventService();
        if ($includeCatalog) {
            foreach ($this->activeIds($conn, 'myitems', $includeDeleted, $limit) as $itemId) {
                $queue['catalog']++;
                $result = $outbox->recordMenuItemSnapshot($conn, $itemId, [
                    'event_type' => 'menu.item_saved',
                    'source_system' => 'settings_supported_data_push',
                    'config' => $pushConfig,
                ]);
                $this->trackQueuedRow($conn, $result, $forceResend, $queue);
            }
        }

        if ($includeTables) {
            foreach ($this->activeIds($conn, 'tables', $includeDeleted, $limit) as $tableId) {
                $queue['tables']++;
                $result = $outbox->recordTableSnapshot($conn, $tableId, [
                    'event_type' => 'table.updated',
                    'source_system' => 'settings_supported_data_push',
                    'active_order_id' => '__auto__',
                    'config' => $pushConfig,
                ]);
                $this->trackQueuedRow($conn, $result, $forceResend, $queue);
            }
        }

        if ($includeOrders) {
            $where = 'WHERE pro_tybe = 9';
            if (!$includeDeleted && $this->columnExists($conn, 'ot_head', 'isdeleted')) {
                $where .= ' AND COALESCE(isdeleted, 0) = 0';
            }
            $sql = "SELECT id FROM ot_head {$where} ORDER BY id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '');
            $resultSet = $conn->query($sql);
            while ($row = $resultSet->fetch_assoc()) {
                $queue['orders']++;
                $result = $outbox->recordOrderSnapshot($conn, (int) $row['id'], [
                    'event_type' => 'order.saved',
                    'source_system' => 'settings_supported_data_push',
                    'config' => $pushConfig,
                ]);
                $this->trackQueuedRow($conn, $result, $forceResend, $queue);
            }
        }

        if ($includeOperational) {
            $this->queueOperationalSnapshots($conn, $pushConfig, $forceResend, $limit, $queue);
            $this->queueInventoryReferencedMenuItems($conn, $outbox, $pushConfig, $forceResend, $limit, $queue);
        }

        $dispatchOptions = array_merge(['drain_outbox' => true], $options);
        $dispatch = $this->dispatchOutbox($conn, $pushConfig, $dispatchOptions);

        return [
            'branch_uuid' => $branchUuid,
            'cloud_base_url' => rtrim(trim((string) ($identity['cloud_base_url'] ?? ($pushConfig['branch']['cloud_base_url'] ?? ''))), '/'),
            'supported_domains' => [
                'menu_items',
                'tables',
                'order_history',
                'item_categories',
                'inventory_balances',
                'inventory_stock_levels',
                'inventory_movements',
                'recipes',
                'recipe_costs',
                'employees',
                'pulse_logs',
                'pulse_types',
            ],
            'unsupported_domains' => ['user_credentials', 'sync_secrets', 'login_passwords'],
            'queue' => $queue,
            'dispatch' => $dispatch,
            'pending_outbox' => $this->countPendingOutbox($conn, $branchUuid),
        ];
    }

    private function assertCanPush(array $config): void
    {
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            throw new InvalidArgumentException('Data sync is only available on the local branch POS.');
        }

        if (empty($config['sync']['branch_sync_enabled'])) {
            throw new InvalidArgumentException('Enable local branch sync before syncing data to hosted.');
        }

        $branchUuid = trim((string) ($config['branch']['uuid'] ?? ''));
        $cloudBaseUrl = trim((string) ($config['branch']['cloud_base_url'] ?? ''));
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');

        if ($branchUuid === '' || $cloudBaseUrl === '' || $branchSecret === '') {
            throw new InvalidArgumentException('Branch UUID, cloud URL, and sync secret are required before syncing data to hosted.');
        }
    }

    private function pushConfig(array $config): array
    {
        $config['sync']['menu_sync_enabled'] = true;
        $config['sync']['operational_sync_enabled'] = true;
        $config['sync']['outbox_enabled'] = true;
        $config['sync']['worker_enabled'] = true;
        $config['sync']['branch_sync_enabled'] = true;

        return $config;
    }

    private function activeIds(mysqli $conn, string $table, bool $includeDeleted, int $limit): array
    {
        $where = '';
        if (!$includeDeleted && $this->columnExists($conn, $table, 'isdeleted')) {
            $where = ' WHERE COALESCE(isdeleted, 0) = 0';
        }
        $sql = "SELECT id FROM `{$table}`{$where} ORDER BY id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '');
        $result = $conn->query($sql);
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function queueOperationalSnapshots(mysqli $conn, array $pushConfig, bool $forceResend, int $limit, array &$queue): void
    {
        $operational = new OperationalSyncEventService();
        $domainCounters = [
            'item_category' => 'item_categories',
            'inventory_balance' => 'inventory_balances',
            'inventory_stock_level' => 'inventory_stock_levels',
            'inventory_movement' => 'inventory_movements',
            'employee' => 'employees',
            'pulse_log' => 'pulse_logs',
            'pulse_type' => 'pulse_types',
        ];

        foreach (OperationalSyncDomains::bulkRowDomains() as $domain => $definition) {
            $counter = $domainCounters[$domain] ?? null;
            if ($counter === null) {
                continue;
            }

            $table = (string) $definition['table'];
            if (!$this->tableExists($conn, $table)) {
                continue;
            }

            foreach ($this->activeIds($conn, $table, false, $limit) as $rowId) {
                $queue[$counter]++;
                $result = $operational->recordRowSnapshot($conn, $domain, $rowId, [
                    'source_system' => 'settings_supported_data_push',
                    'config' => $pushConfig,
                ]);
                $this->trackQueuedRow($conn, $result, $forceResend, $queue);
            }
        }

        if ($this->tableExists($conn, 'recipe_headers')) {
            $sql = 'SELECT id FROM recipe_headers ORDER BY id ASC' . ($limit > 0 ? ' LIMIT ' . $limit : '');
            $resultSet = $conn->query($sql);
            while ($row = $resultSet->fetch_assoc()) {
                $queue['recipes']++;
                $result = $operational->recordRecipeSnapshot($conn, (int) $row['id'], [
                    'source_system' => 'settings_supported_data_push',
                    'config' => $pushConfig,
                ]);
                $this->trackQueuedRow($conn, $result, $forceResend, $queue);
            }
        }
    }

    private function queueInventoryReferencedMenuItems(
        mysqli $conn,
        SyncOutboxEventService $outbox,
        array $pushConfig,
        bool $forceResend,
        int $limit,
        array &$queue
    ): void {
        if (!$this->tableExists($conn, 'myitems')) {
            return;
        }

        foreach ($this->inventoryReferencedItemIds($conn, $limit) as $itemId) {
            $queue['catalog_inventory_refs']++;
            $result = $outbox->recordMenuItemSnapshot($conn, $itemId, [
                'event_type' => 'menu.item_saved',
                'source_system' => 'settings_supported_data_push',
                'config' => $pushConfig,
            ]);
            $this->trackQueuedRow($conn, $result, $forceResend, $queue);
        }
    }

    private function inventoryReferencedItemIds(mysqli $conn, int $limit): array
    {
        $parts = [];
        foreach (['inventory_item_balances', 'inventory_item_stock_levels', 'inventory_movements'] as $table) {
            if (!$this->tableExists($conn, $table) || !$this->columnExists($conn, $table, 'item_id')) {
                continue;
            }
            $parts[] = "SELECT item_id FROM `{$table}` WHERE item_id > 0";
        }

        if ($parts === []) {
            return [];
        }

        $sql = '
            SELECT DISTINCT refs.item_id
            FROM (' . implode(' UNION ', $parts) . ') refs
            INNER JOIN myitems i ON i.id = refs.item_id
            ORDER BY refs.item_id ASC' . ($limit > 0 ? ' LIMIT ' . $limit : '');
        $result = $conn->query($sql);
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['item_id'];
        }

        return $ids;
    }

    private function trackQueuedRow(mysqli $conn, ?array $result, bool $forceResend, array &$queue): void
    {
        if (!$result || empty($result['outbox_id'])) {
            $queue['skipped']++;
            return;
        }

        $queue['queued']++;

        if (!$forceResend) {
            return;
        }

        $outboxId = (int) $result['outbox_id'];
        $conn->query("
            UPDATE sync_outbox
            SET status = 'pending',
                attempts = 0,
                locked_by = NULL,
                locked_until = NULL,
                next_retry_at = NULL,
                last_error = NULL,
                synced_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = {$outboxId}
        ");
        $queue['resent']++;
    }

    private function dispatchOutbox(mysqli $conn, array $config, array $options): array
    {
        $batchSize = max(1, min(100, (int) ($options['batch_size'] ?? 50)));
        $drainOutbox = !empty($options['drain_outbox']);
        $maxBatches = $drainOutbox
            ? PHP_INT_MAX
            : max(1, min(50, (int) ($options['max_batches'] ?? 20)));
        $worker = new BranchSyncWorker();
        $summary = [
            'batches' => 0,
            'claimed' => 0,
            'synced' => 0,
            'failed' => 0,
            'dead' => 0,
            'skipped' => null,
            'drained_outbox' => $drainOutbox,
        ];

        for ($i = 0; $i < $maxBatches; $i++) {
            $metrics = $worker->runOnce($conn, $config, ['batch_size' => $batchSize]);
            $summary['batches']++;
            $summary['claimed'] += (int) ($metrics['claimed'] ?? 0);
            $summary['synced'] += (int) ($metrics['synced'] ?? 0);
            $summary['failed'] += (int) ($metrics['failed'] ?? 0);
            $summary['dead'] += (int) ($metrics['dead'] ?? 0);

            if (!empty($metrics['skipped'])) {
                $summary['skipped'] = (string) $metrics['skipped'];
                break;
            }

            if ((int) ($metrics['claimed'] ?? 0) === 0) {
                break;
            }
        }

        return $summary;
    }

    private function countPendingOutbox(mysqli $conn, string $branchUuid): int
    {
        if ($branchUuid === '' || !$this->tableExists($conn, 'sync_outbox')) {
            return 0;
        }

        $escaped = $conn->real_escape_string($branchUuid);
        $row = $conn->query("
            SELECT COUNT(*) AS c
            FROM sync_outbox
            WHERE branch_uuid = '{$escaped}'
              AND status IN ('pending', 'failed', 'syncing')
              AND (next_retry_at IS NULL OR next_retry_at <= NOW(6))
        ")->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $escapedTable = $conn->real_escape_string($table);
        $escapedColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");

        return $result && $result->num_rows > 0;
    }
}
