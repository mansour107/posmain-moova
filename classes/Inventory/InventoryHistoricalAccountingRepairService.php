<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryAccountingService.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';

/**
 * Posts missing journals for real operational inventory movements.
 *
 * Legacy backfill and append-only stock-state correction movements are kept
 * visible in the manifest, but are never converted into new commercial
 * journals. Posting those rows would double-book historical COGS or invent
 * gains/losses for a data repair.
 */
final class InventoryHistoricalAccountingRepairService
{
    private const REPAIR_TYPE = 'inventory_historical_accounting_repair';

    /** @var array<string,string[]> */
    private const REQUIRED_ACCOUNTS = [
        'purchase' => ['inventory_asset_account_id', 'purchase_clearing_account_id'],
        'purchase_return' => ['inventory_asset_account_id', 'purchase_clearing_account_id'],
        'sale_direct' => ['inventory_asset_account_id', 'cogs_account_id'],
        'refund_reversal' => ['inventory_asset_account_id', 'cogs_account_id'],
        'waste' => ['inventory_asset_account_id', 'waste_expense_account_id'],
        'adjustment' => ['inventory_asset_account_id', 'adjustment_gain_loss_account_id'],
    ];

    public function plan(mysqli $conn, array $options): array
    {
        $accounts = $this->normalizeAccounts($options['accounts'] ?? []);
        $rows = $this->candidateRows($conn, $options);
        $entries = [];
        $excluded = [];
        $blockers = [];
        $decisions = $this->normalizeDecisions($options['reviewed_decisions'] ?? []);
        $usedDecisions = [];

        foreach ($rows as $row) {
            $classification = $this->classification($conn, $row);
            if ($classification === 'operational') {
                $decisionRequirement = $this->decisionRequirement($conn, $row);
                if ($decisionRequirement !== null) {
                    $movementId = (int) $row['id'];
                    $decision = $decisions[$movementId] ?? null;
                    if (!$this->validPostDecision($decision, $decisionRequirement)) {
                        $blockers[] = [
                            'code' => $decisionRequirement,
                            'movement_id' => $movementId,
                            'movement_type' => (string) $row['movement_type'],
                        ];
                        continue;
                    }
                    $usedDecisions[$movementId] = $decision;
                    $entries[] = $this->manifestRow($row) + ['reviewed_decision' => $decision];
                    continue;
                }
                $entries[] = $this->manifestRow($row);
                continue;
            }
            if ($classification === 'invalid_stock_reclassification') {
                $blockers[] = [
                    'code' => 'invalid_stock_reclassification_requires_manual_review',
                    'movement_id' => (int) $row['id'],
                    'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
                ];
            }
            $excluded[] = $this->manifestRow($row) + ['classification' => $classification];
        }
        foreach ($decisions as $movementId => $decision) {
            if (!isset($usedDecisions[$movementId])) {
                $blockers[] = [
                    'code' => 'unused_historical_accounting_decision',
                    'movement_id' => $movementId,
                ];
            }
        }

        foreach ($this->requiredAccountKeys($entries) as $key) {
            $accountId = (int) ($accounts[$key] ?? 0);
            if ($accountId < 1) {
                $blockers[] = ['code' => 'required_account_mapping_missing', 'account_key' => $key];
                continue;
            }
            if (!$this->activeAccountExists($conn, $accountId)) {
                $blockers[] = [
                    'code' => 'required_account_mapping_invalid',
                    'account_key' => $key,
                    'account_id' => $accountId,
                ];
            }
        }

        $manifest = [
            'scope' => [
                'pos_tenant' => isset($options['pos_tenant']) ? (int) $options['pos_tenant'] : null,
                'pos_branch' => isset($options['pos_branch']) ? (int) $options['pos_branch'] : null,
                'store_id' => isset($options['store_id']) ? (int) $options['store_id'] : null,
            ],
            'accounts' => $accounts,
            'reviewed_decisions' => $usedDecisions,
            'entries' => $entries,
            'excluded_count' => count($excluded),
            'excluded_fingerprint' => hash('sha256', $this->canonicalJson($excluded)),
            'blockers' => $blockers,
        ];

        return $manifest + [
            'ok' => $blockers === [],
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'summary' => [
                'entry_count' => count($entries),
                'excluded_count' => count($excluded),
                'journal_total' => $this->sumCosts($entries),
            ],
            'sample_excluded' => array_slice($excluded, 0, 20),
        ];
    }

    public function rehearse(mysqli $conn, array $options): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn, $options);
            if (!$plan['ok']) {
                throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_PLAN_BLOCKED');
            }
            $posted = $this->postEntries($conn, $plan, $options);
            $conn->rollback();

            return $plan + [
                'mode' => 'rehearse',
                'rehearsed' => true,
                'posted' => $posted,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function apply(
        mysqli $conn,
        array $options,
        string $reviewedManifestHash,
        string $backupFile
    ): array {
        $this->assertBackup($backupFile);
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_REVIEWED_MANIFEST_REQUIRED');
        }

        $ledger = new DataRepairRunLedger();
        $prior = $ledger->find($conn, self::REPAIR_TYPE, $reviewedManifestHash);
        if ($prior !== null) {
            $prior['replayed'] = true;

            return $prior;
        }

        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn, $options);
            if (!hash_equals((string) $plan['manifest_hash'], $reviewedManifestHash)) {
                throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_LIVE_ROWS_CHANGED');
            }
            if (!$plan['ok']) {
                throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_PLAN_BLOCKED');
            }
            $posted = $this->postEntries($conn, $plan, $options);
            $result = [
                'ok' => true,
                'mode' => 'apply',
                'manifest_hash' => (string) $plan['manifest_hash'],
                'summary' => $plan['summary'],
                'posted' => $posted,
                'replayed' => false,
            ];
            $ledger->record($conn, self::REPAIR_TYPE, $reviewedManifestHash, $result);
            $conn->commit();

            return $result;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    private function postEntries(mysqli $conn, array $plan, array $options): array
    {
        $appConfig = is_array($options['app_config'] ?? null)
            ? $options['app_config']
            : (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $appConfig['inventory'] = array_merge(
            is_array($appConfig['inventory'] ?? null) ? $appConfig['inventory'] : [],
            [
                'ledger_mode' => 'live',
                'accounting' => true,
                'accounts' => $plan['accounts'],
            ]
        );
        $accounting = new InventoryAccountingService(new InventoryFeatureFlags($appConfig));
        $posted = [];

        foreach ($plan['entries'] as $entry) {
            $movementId = (int) $entry['id'];
            $context = [
                'pos_tenant' => (int) $entry['pos_tenant'],
                'pos_branch' => (int) $entry['pos_branch'],
                'store_id' => (int) $entry['store_id'],
                'order_id' => (int) ($entry['order_id'] ?? 0),
                'operation_id' => (int) ($entry['source_id'] ?? 0),
                'user_id' => (int) ($options['created_by'] ?? 0),
                'jdate' => substr((string) $entry['created_at'], 0, 10),
                'details' => 'Historical inventory accounting repair movement ' . $movementId,
                'sync_config' => $appConfig,
            ] + $plan['accounts'];

            switch ((string) $entry['movement_type']) {
                case 'purchase':
                    $result = $accounting->postPurchaseReceipt($conn, $context, [$movementId]);
                    break;
                case 'purchase_return':
                    $result = $accounting->postPurchaseReturn($conn, $context, [$movementId]);
                    break;
                case 'sale_direct':
                    $result = $accounting->postSaleCogs($conn, $context, [$movementId]);
                    break;
                case 'refund_reversal':
                    $result = $accounting->postRefundReversal($conn, $context, [$movementId]);
                    break;
                case 'waste':
                    $result = $accounting->postWaste($conn, $context, [$movementId]);
                    break;
                case 'adjustment':
                    $result = $accounting->postAdjustment($conn, $context, [$movementId]);
                    break;
                default:
                    throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_MOVEMENT_TYPE_UNSUPPORTED');
            }
            if (!empty($result['noop']) || (int) ($result['journal_head_id'] ?? 0) < 1) {
                throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_JOURNAL_NOT_POSTED');
            }
            $posted[] = [
                'movement_id' => $movementId,
                'journal_head_id' => (int) $result['journal_head_id'],
                'total' => (string) $result['total'],
            ];
        }

        return $posted;
    }

    private function candidateRows(mysqli $conn, array $options): array
    {
        foreach (['inventory_movements', 'acc_head', 'journal_heads', 'journal_entries', 'data_repair_runs'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('INVENTORY_ACCOUNTING_REPAIR_REQUIRES_TABLE:' . $table);
            }
        }
        $conditions = [
            "movement_type IN ('purchase','purchase_return','sale_direct','refund_reversal','waste','adjustment')",
            'COALESCE(total_cost, 0) > 0',
            '(accounting_journal_id IS NULL OR accounting_journal_id = 0)',
        ];
        $params = [];
        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $column) {
            if (!isset($options[$column]) || (int) $options[$column] < 0) {
                continue;
            }
            $conditions[] = '`' . $column . '` = ?';
            $params[] = (int) $options[$column];
        }
        $sql = 'SELECT id,pos_tenant,pos_branch,store_id,item_id,movement_type,source_type,source_id,'
            . 'order_id,qty_in,qty_out,unit_cost,total_cost,reversed_movement_id,idempotency_key,created_at '
            . 'FROM inventory_movements WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id';
        $stmt = $conn->prepare($sql);
        if ($params) {
            $types = str_repeat('i', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function classification(mysqli $conn, array $row): string
    {
        $key = (string) ($row['idempotency_key'] ?? '');
        if (preg_match('/^migration:fat_details:\d+:(?:reviewed:)?v1$/', $key)) {
            return 'legacy_backfill';
        }
        foreach ([
            'migration:fat_details:duplicate-neutralization:',
            'migration:fat_details:deleted-neutralization:',
            'non-stock-neutralize:',
            'scope-reclass:',
        ] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 'stock_state_correction';
            }
        }
        if ((string) $row['movement_type'] === 'sale_direct' && $this->isExactUnpaidPairMember($conn, $row)) {
            return 'unpaid_draft_stock_reclassification';
        }
        if (str_starts_with($key, 'inventory-unpaid-sale-reclass:v1:')) {
            return $this->isExactUnpaidPairMember($conn, $row)
                ? 'unpaid_draft_stock_reclassification'
                : 'invalid_stock_reclassification';
        }

        return 'operational';
    }

    private function decisionRequirement(mysqli $conn, array $row): ?string
    {
        if ((string) $row['movement_type'] === 'adjustment') {
            return 'historical_adjustment_requires_reviewed_post_decision';
        }
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId < 1 || !$this->tableExists($conn, 'ot_head')) {
            return null;
        }
        $stmt = $conn->prepare("SELECT payment_status,invoice_status,order_status,closed,isdeleted
            FROM ot_head WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$order) {
            return 'historical_order_state_missing_requires_reviewed_post_decision';
        }
        $payment = strtolower((string) ($order['payment_status'] ?? ''));
        $invoice = strtolower((string) ($order['invoice_status'] ?? ''));
        $status = strtolower((string) ($order['order_status'] ?? ''));
        $deleted = (int) ($order['isdeleted'] ?? 0) !== 0;
        if ($deleted || in_array($payment, ['refunded', 'voided', 'cancelled'], true)
            || in_array($invoice, ['refunded', 'voided', 'cancelled'], true)
            || in_array($status, ['refunded', 'voided', 'cancelled'], true)) {
            return 'historical_refund_stock_disposition_requires_reviewed_post_decision';
        }
        if ((string) $row['movement_type'] === 'sale_direct'
            && !($payment === 'paid' && $invoice === 'completed' && $status === 'completed')) {
            return 'unsettled_historical_sale_requires_reviewed_post_decision';
        }

        return null;
    }

    private function normalizeDecisions($input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $normalized = [];
        foreach ($input as $key => $decision) {
            if (!is_array($decision)) {
                continue;
            }
            $movementId = (int) ($decision['movement_id'] ?? $key);
            if ($movementId < 1) {
                continue;
            }
            $normalized[$movementId] = [
                'movement_id' => $movementId,
                'action' => strtolower(trim((string) ($decision['action'] ?? ''))),
                'approved_by' => trim((string) ($decision['approved_by'] ?? '')),
                'reason' => trim((string) ($decision['reason'] ?? '')),
                'stock_disposition' => strtolower(trim((string) ($decision['stock_disposition'] ?? ''))),
            ];
        }
        ksort($normalized);

        return $normalized;
    }

    private function validPostDecision(?array $decision, string $requirement): bool
    {
        if ($decision === null
            || ($decision['action'] ?? '') !== 'post'
            || ($decision['approved_by'] ?? '') === ''
            || ($decision['reason'] ?? '') === '') {
            return false;
        }
        if ($requirement === 'historical_refund_stock_disposition_requires_reviewed_post_decision') {
            return in_array((string) ($decision['stock_disposition'] ?? ''), ['restocked', 'waste_no_restock'], true);
        }

        return true;
    }

    private function isExactUnpaidPairMember(mysqli $conn, array $row): bool
    {
        $id = (int) $row['id'];
        if ((string) $row['movement_type'] === 'refund_reversal') {
            $sourceId = (int) ($row['reversed_movement_id'] ?? 0);
            $expectedKey = 'inventory-unpaid-sale-reclass:v1:' . $sourceId . ':restore';
            if ($sourceId < 1 || (string) $row['idempotency_key'] !== $expectedKey) {
                return false;
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inventory_movements
                WHERE id=? AND movement_type='sale_direct'
                  AND item_id=? AND store_id=? AND qty_out=? AND total_cost=?
                  AND (accounting_journal_id IS NULL OR accounting_journal_id=0)");
            $itemId = (int) $row['item_id'];
            $storeId = (int) $row['store_id'];
            $qty = (string) $row['qty_in'];
            $cost = (string) $row['total_cost'];
            $stmt->bind_param('iiiss', $sourceId, $itemId, $storeId, $qty, $cost);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inventory_movements
                WHERE reversed_movement_id=? AND movement_type='refund_reversal'
                  AND idempotency_key=? AND item_id=? AND store_id=?
                  AND qty_in=? AND total_cost=?
                  AND (accounting_journal_id IS NULL OR accounting_journal_id=0)");
            $expectedKey = 'inventory-unpaid-sale-reclass:v1:' . $id . ':restore';
            $itemId = (int) $row['item_id'];
            $storeId = (int) $row['store_id'];
            $qty = (string) $row['qty_out'];
            $cost = (string) $row['total_cost'];
            $stmt->bind_param('isiiss', $id, $expectedKey, $itemId, $storeId, $qty, $cost);
        }
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count === 1;
    }

    private function manifestRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'pos_tenant' => (int) $row['pos_tenant'],
            'pos_branch' => (int) $row['pos_branch'],
            'store_id' => (int) $row['store_id'],
            'item_id' => (int) $row['item_id'],
            'movement_type' => (string) $row['movement_type'],
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_id' => isset($row['source_id']) ? (int) $row['source_id'] : null,
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : null,
            'qty_in' => (string) $row['qty_in'],
            'qty_out' => (string) $row['qty_out'],
            'total_cost' => (string) $row['total_cost'],
            'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function requiredAccountKeys(array $entries): array
    {
        $required = [];
        foreach ($entries as $entry) {
            foreach (self::REQUIRED_ACCOUNTS[(string) $entry['movement_type']] ?? [] as $key) {
                $required[$key] = true;
            }
        }

        return array_keys($required);
    }

    private function normalizeAccounts(array $accounts): array
    {
        $normalized = [];
        foreach (array_unique(array_merge(...array_values(self::REQUIRED_ACCOUNTS))) as $key) {
            $normalized[$key] = (int) ($accounts[$key] ?? 0);
        }

        return $normalized;
    }

    private function activeAccountExists(mysqli $conn, int $accountId): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM acc_head WHERE id=? AND COALESCE(isdeleted,0)=0');
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count === 1;
    }

    private function sumCosts(array $entries): string
    {
        $total = '0.000000';
        foreach ($entries as $entry) {
            $total = InventoryDecimal::add($total, (string) $entry['total_cost']);
        }

        return $total;
    }

    private function assertBackup(string $backupFile): void
    {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            throw new RuntimeException('READABLE_DATABASE_BACKUP_REQUIRED');
        }
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    private function canonicalJson(array $value): string
    {
        $walk = function (array $data) use (&$walk): array {
            if (!array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as $key => $item) {
                if (is_array($item)) {
                    $data[$key] = $walk($item);
                }
            }

            return $data;
        };

        return json_encode($walk($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
