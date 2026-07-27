<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryValuationAccountingService.php';
require_once __DIR__ . '/InventoryAccountingReconciliationService.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../Maintenance/DataRepairRunLedger.php';
require_once __DIR__ . '/../Sync/DocumentCounterService.php';
require_once __DIR__ . '/../Sync/OperationalSyncEventService.php';

final class InventoryValuationCutoverService
{
    private const REPAIR_TYPE = 'inventory_valuation_cutover';

    public function plan(mysqli $conn, array $options): array
    {
        $scope = [
            'pos_tenant' => (int) ($options['pos_tenant'] ?? 0),
            'pos_branch' => (int) ($options['pos_branch'] ?? 0),
            'store_id' => (int) ($options['store_id'] ?? 0),
        ];
        $inventoryAccountId = (int) ($options['inventory_asset_account_id'] ?? 0);
        $offsetAccountId = (int) ($options['offset_account_id'] ?? 0);
        $valuation = (new InventoryValuationAccountingService())->review($conn, $scope, $inventoryAccountId);
        $movementAccounting = (new InventoryAccountingReconciliationService())->review($conn, [
            'pos_tenant' => $scope['pos_tenant'],
            'pos_branch' => $scope['pos_branch'],
            'store_id' => $scope['store_id'],
            'limit' => 500,
        ]);
        $blockers = [];
        if ((int) ($valuation['negative_quantity_count'] ?? 0) > 0) {
            $blockers[] = 'negative_inventory_quantities_require_count_or_review';
        }
        if (empty($movementAccounting['ok'])) {
            $blockers[] = 'inventory_movement_accounting_reconciliation_not_ready';
        }
        if ($offsetAccountId < 1 || !$this->activeAccountExists($conn, $offsetAccountId)) {
            $blockers[] = 'inventory_cutover_offset_account_missing_or_inactive';
        }
        if ($offsetAccountId === $inventoryAccountId && $offsetAccountId > 0) {
            $blockers[] = 'inventory_cutover_accounts_must_be_distinct';
        }
        $difference = (string) ($valuation['difference_2dp'] ?? '0.00');
        $manifest = [
            'scope' => $scope,
            'inventory_asset_account_id' => $inventoryAccountId,
            'offset_account_id' => $offsetAccountId,
            'valuation_raw_6dp' => (string) ($valuation['valuation_raw_6dp'] ?? '0.000000'),
            'valuation_rounded_2dp' => (string) ($valuation['valuation_rounded_2dp'] ?? '0.00'),
            'inventory_asset_gl_balance_2dp' => (string) ($valuation['inventory_asset_gl_balance_2dp'] ?? '0.00'),
            'difference_2dp' => $difference,
            'negative_quantity_count' => (int) ($valuation['negative_quantity_count'] ?? 0),
            'movement_accounting_problem_count' => (int) ($movementAccounting['problem_count'] ?? 0),
            'cutover_date' => (string) ($options['cutover_date'] ?? date('Y-m-d')),
            'approved_by' => trim((string) ($options['approved_by'] ?? '')),
            'approval_reason' => trim((string) ($options['approval_reason'] ?? '')),
            'blockers' => $blockers,
        ];
        if ($difference !== '0.00' && ($manifest['approved_by'] === '' || $manifest['approval_reason'] === '')) {
            $manifest['blockers'][] = 'inventory_cutover_journal_requires_accountant_approval';
        }

        return $manifest + [
            'ok' => $manifest['blockers'] === [],
            'mode' => 'plan',
            'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'valuation_review' => $valuation,
            'movement_accounting_review' => $movementAccounting,
            'journal_required' => $difference !== '0.00',
        ];
    }

    public function rehearse(mysqli $conn, array $options): array
    {
        $conn->begin_transaction();
        try {
            $plan = $this->plan($conn, $options);
            if (!$plan['ok']) {
                throw new RuntimeException('INVENTORY_VALUATION_CUTOVER_PLAN_BLOCKED');
            }
            $journal = $this->postJournal($conn, $plan, $options);
            $conn->rollback();

            return $plan + ['mode' => 'rehearse', 'rehearsed' => true, 'journal' => $journal];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function apply(mysqli $conn, array $options, string $reviewedManifestHash, string $backupFile): array
    {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            throw new RuntimeException('READABLE_DATABASE_BACKUP_REQUIRED');
        }
        $reviewedManifestHash = strtolower(trim($reviewedManifestHash));
        if (!preg_match('/^[a-f0-9]{64}$/', $reviewedManifestHash)) {
            throw new RuntimeException('INVENTORY_VALUATION_CUTOVER_REVIEWED_MANIFEST_REQUIRED');
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
                throw new RuntimeException('INVENTORY_VALUATION_CUTOVER_LIVE_ROWS_CHANGED');
            }
            if (!$plan['ok']) {
                throw new RuntimeException('INVENTORY_VALUATION_CUTOVER_PLAN_BLOCKED');
            }
            $journal = $this->postJournal($conn, $plan, $options);
            $result = [
                'ok' => true,
                'mode' => 'apply',
                'manifest_hash' => (string) $plan['manifest_hash'],
                'journal' => $journal,
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

    private function postJournal(mysqli $conn, array $plan, array $options): array
    {
        $difference = Money::from((string) $plan['difference_2dp'], true);
        if ($difference->compare(Money::zero()) === 0) {
            return ['noop' => true, 'reason' => 'inventory valuation already matches GL'];
        }
        $tenant = (int) $plan['scope']['pos_tenant'];
        $branch = (int) $plan['scope']['pos_branch'];
        $store = (int) $plan['scope']['store_id'];
        $inventoryAccount = (int) $plan['inventory_asset_account_id'];
        $offsetAccount = (int) $plan['offset_account_id'];
        $amount = $difference->isNegative()
            ? Money::zero()->subtract($difference)->toString()
            : $difference->toString();
        $entries = $difference->isNegative()
            ? [
                ['account_id' => $offsetAccount, 'debit' => $amount, 'credit' => '0.00', 'tybe' => 0],
                ['account_id' => $inventoryAccount, 'debit' => '0.00', 'credit' => $amount, 'tybe' => 1],
            ]
            : [
                ['account_id' => $inventoryAccount, 'debit' => $amount, 'credit' => '0.00', 'tybe' => 0],
                ['account_id' => $offsetAccount, 'debit' => '0.00', 'credit' => $amount, 'tybe' => 1],
            ];
        $counter = new DocumentCounterService();
        $seedRow = $conn->query('SELECT COALESCE(MAX(journal_id),0) AS max_id FROM journal_heads')->fetch_assoc();
        $counter->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:default', (int) ($seedRow['max_id'] ?? 0));
        $journalId = $counter->nextJournalId($conn, $tenant, $branch);
        $headId = JournalPostingService::postBalancedHead(
            $conn,
            (string) $journalId,
            $amount,
            (string) $plan['cutover_date'],
            'Inventory valuation cutover: ' . (string) $plan['approval_reason'],
            (int) ($options['created_by'] ?? 0),
            $entries,
            [
                'tenant' => $tenant,
                'branch' => $branch,
                'source_type' => 'inventory_valuation_cutover',
                'source_id' => $store,
                'posting_kind' => 'inventory_valuation_cutover',
                'idempotency_key' => 'inventory-valuation-cutover:' . (string) $plan['manifest_hash'],
            ]
        );
        $config = is_array($options['app_config'] ?? null)
            ? $options['app_config']
            : (function_exists('posmain_app_config') ? posmain_app_config() : []);
        (new OperationalSyncEventService())->recordInventoryAccountingSnapshot($conn, $headId, [], [
            'config' => $config,
            'source_system' => 'inventory_valuation_cutover',
            'event_type' => 'inventory.valuation_cutover_posted',
        ]);

        return ['noop' => false, 'journal_id' => $journalId, 'journal_head_id' => $headId, 'amount' => $amount];
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
