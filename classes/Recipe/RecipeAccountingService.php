<?php

require_once __DIR__ . '/../Sync/DocumentCounterService.php';
require_once __DIR__ . '/DTO/RecipeScope.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipeSettingsService.php';
require_once __DIR__ . '/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../Financial/Money.php';
require_once __DIR__ . '/../Financial/RoundingPolicy.php';
require_once __DIR__ . '/../Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../../includes/pos_default_accounts.php';

class RecipeAccountingService
{
    private $flags;
    private $counterService;
    private $movements;
    private $settings;
    private OperationalSyncEventService $syncEvents;
    private array $itemCategoryCache = [];

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?DocumentCounterService $counterService = null,
        ?InventoryMovementRepository $movements = null,
        ?RecipeSettingsService $settings = null,
        ?OperationalSyncEventService $syncEvents = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->counterService = $counterService ?: new DocumentCounterService();
        $this->movements = $movements ?: new InventoryMovementRepository();
        $this->settings = $settings ?: new RecipeSettingsService();
        $this->syncEvents = $syncEvents ?: new OperationalSyncEventService();
    }

    public function postSaleCogs(mysqli $conn, array $orderContext, array $consumptionMovements): array
    {
        $scope = $this->scopeFromContext($orderContext);
        $sellableItemId = (int) ($orderContext['sellable_item_id'] ?? 0);
        if (!$this->flags->isAccountingEnabledForItem($scope, $sellableItemId, $this->itemCategoryId($conn, $sellableItemId, $orderContext))) {
            return $this->noop('recipe accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $consumptionMovements, ['recipe_consumption']);
        if (!$rows) {
            return $this->noop('no recipe consumption movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $this->captureJournalResult($conn, $existing, $orderContext);
        }

        $total = $this->movementTotal($rows);
        if (!RecipeDecimal::isPositive($total)) {
            return $this->noop('recipe consumption total is zero');
        }

        $cogsAccountId = $this->requiredAccount($conn, $orderContext, 'cogs_account_id');
        $inventoryAccountId = $this->requiredInventoryAccount($conn, $orderContext);
        $details = (string) ($orderContext['details'] ?? ('Recipe COGS for order ' . (int) ($orderContext['order_id'] ?? 0)));

        return $this->postBalancedJournal($conn, $scope, [
            'details' => $details,
            'total' => $total,
            'user_id' => (int) ($orderContext['user_id'] ?? $orderContext['created_by'] ?? 0),
            'op_id' => (int) ($orderContext['order_id'] ?? 0),
            'entries' => [
                ['account_id' => $cogsAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Recipe COGS'],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Recipe inventory credit'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ], $orderContext);
    }

    public function postProductionBatch(mysqli $conn, array $batchContext, array $inputMovements, array $outputMovements): array
    {
        $scope = $this->scopeFromContext($batchContext);
        $outputItemId = (int) ($batchContext['output_item_id'] ?? 0);
        if (!$this->flags->isAccountingEnabledForItem($scope, $outputItemId, $this->itemCategoryId($conn, $outputItemId, $batchContext))) {
            return $this->noop('recipe accounting is disabled');
        }

        $inputs = $this->loadMovements($conn, $inputMovements, ['production_input']);
        $outputs = $this->loadMovements($conn, $outputMovements, ['production_output']);
        $rows = array_merge($inputs, $outputs);
        if (!$inputs || !$outputs) {
            return $this->noop('production input and output movements are required');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $this->captureJournalResult($conn, $existing, $batchContext);
        }

        $inputTotal = $this->movementTotal($inputs);
        $outputTotal = $this->movementTotal($outputs);
        if (!RecipeDecimal::isPositive($inputTotal) && !RecipeDecimal::isPositive($outputTotal)) {
            return $this->noop('production movement total is zero');
        }

        $rawAccountId = $this->requiredAccount($conn, $batchContext, 'raw_inventory_account_id');
        $preparedAccountId = $this->requiredAccount($conn, $batchContext, 'prepared_inventory_account_id');
        $entries = [
            ['account_id' => $preparedAccountId, 'debit' => $outputTotal, 'credit' => '0.000000', 'info' => 'Production output'],
            ['account_id' => $rawAccountId, 'debit' => '0.000000', 'credit' => $inputTotal, 'info' => 'Production input'],
        ];

        $comparison = RecipeDecimal::compare($inputTotal, $outputTotal);
        if ($comparison !== 0) {
            $varianceAccountId = $this->requiredAccount($conn, $batchContext, 'production_variance_account_id');
            if ($comparison > 0) {
                $entries[] = [
                    'account_id' => $varianceAccountId,
                    'debit' => RecipeDecimal::subtract($inputTotal, $outputTotal),
                    'credit' => '0.000000',
                    'info' => 'Production variance',
                ];
            } else {
                $entries[] = [
                    'account_id' => $varianceAccountId,
                    'debit' => '0.000000',
                    'credit' => RecipeDecimal::subtract($outputTotal, $inputTotal),
                    'info' => 'Production variance',
                ];
            }
        }

        return $this->postBalancedJournal($conn, $scope, [
            'details' => (string) ($batchContext['details'] ?? ('Recipe production batch ' . (string) ($batchContext['batch_uuid'] ?? $batchContext['batch_id'] ?? ''))),
            'total' => RecipeDecimal::compare($inputTotal, $outputTotal) >= 0 ? $inputTotal : $outputTotal,
            'user_id' => (int) ($batchContext['user_id'] ?? $batchContext['created_by'] ?? 0),
            'op_id' => (int) ($batchContext['batch_id'] ?? 0),
            'entries' => $entries,
            'movement_ids' => array_column($rows, 'id'),
        ], $batchContext);
    }

    public function postWaste(mysqli $conn, array $wasteContext, array $wasteMovements): array
    {
        $scope = $this->scopeFromContext($wasteContext);
        $itemId = (int) ($wasteContext['item_id'] ?? 0);
        if (!$this->flags->isAccountingEnabledForItem($scope, $itemId, $this->itemCategoryId($conn, $itemId, $wasteContext))) {
            return $this->noop('recipe accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $wasteMovements, ['waste']);
        if (!$rows) {
            return $this->noop('no waste movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $this->captureJournalResult($conn, $existing, $wasteContext);
        }

        $total = $this->movementTotal($rows);
        $wasteAccountId = $this->requiredAccount($conn, $wasteContext, 'waste_expense_account_id');
        $inventoryAccountId = $this->requiredInventoryAccount($conn, $wasteContext);

        return $this->postBalancedJournal($conn, $scope, [
            'details' => (string) ($wasteContext['details'] ?? 'Recipe waste'),
            'total' => $total,
            'user_id' => (int) ($wasteContext['user_id'] ?? $wasteContext['created_by'] ?? 0),
            'op_id' => (int) ($wasteContext['waste_id'] ?? 0),
            'entries' => [
                ['account_id' => $wasteAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Recipe waste'],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Recipe waste inventory credit'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ], $wasteContext);
    }

    public function postStockAdjustment(mysqli $conn, array $adjustmentContext, array $adjustmentMovements): array
    {
        $scope = $this->scopeFromContext($adjustmentContext);
        $itemId = (int) ($adjustmentContext['item_id'] ?? 0);
        if (!$this->flags->isAccountingEnabledForItem($scope, $itemId, $this->itemCategoryId($conn, $itemId, $adjustmentContext))) {
            return $this->noop('recipe accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $adjustmentMovements, ['adjustment']);
        if (!$rows) {
            return $this->noop('no stock adjustment movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $this->captureJournalResult($conn, $existing, $adjustmentContext);
        }

        $hasQtyIn = false;
        $hasQtyOut = false;
        foreach ($rows as $row) {
            $hasQtyIn = $hasQtyIn || RecipeDecimal::isPositive($row['qty_in'] ?? '0');
            $hasQtyOut = $hasQtyOut || RecipeDecimal::isPositive($row['qty_out'] ?? '0');
        }
        if ($hasQtyIn === $hasQtyOut) {
            throw new InvalidArgumentException('Recipe stock adjustment accounting requires one movement direction.');
        }

        $total = $this->movementTotal($rows);
        $inventoryAccountId = $this->requiredInventoryAccount($conn, $adjustmentContext);
        $varianceAccountId = $this->requiredAccount($conn, $adjustmentContext, 'production_variance_account_id');
        $entries = $hasQtyIn
            ? [
                ['account_id' => $inventoryAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Recipe stock adjustment inventory debit'],
                ['account_id' => $varianceAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Recipe stock adjustment variance credit'],
            ]
            : [
                ['account_id' => $varianceAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Recipe stock adjustment variance debit'],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Recipe stock adjustment inventory credit'],
            ];

        return $this->postBalancedJournal($conn, $scope, [
            'details' => (string) ($adjustmentContext['details'] ?? 'Recipe stock adjustment'),
            'total' => $total,
            'user_id' => (int) ($adjustmentContext['user_id'] ?? $adjustmentContext['created_by'] ?? 0),
            'op_id' => (int) ($adjustmentContext['adjustment_id'] ?? 0),
            'entries' => $entries,
            'movement_ids' => array_column($rows, 'id'),
        ], $adjustmentContext);
    }

    public function postRefundReversal(mysqli $conn, array $refundContext, array $reversalMovements): array
    {
        $scope = $this->scopeFromContext($refundContext);
        $sellableItemId = (int) ($refundContext['sellable_item_id'] ?? $refundContext['item_id'] ?? 0);
        if (!$this->flags->isAccountingEnabledForItem($scope, $sellableItemId, $this->itemCategoryId($conn, $sellableItemId, $refundContext))) {
            return $this->noop('recipe accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $reversalMovements, ['refund_reversal']);
        if (!$rows) {
            return $this->noop('no refund reversal movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $this->captureJournalResult($conn, $existing, $refundContext);
        }

        $total = $this->movementTotal($rows);
        $cogsAccountId = $this->requiredAccount($conn, $refundContext, 'cogs_account_id');
        $inventoryAccountId = $this->requiredInventoryAccount($conn, $refundContext);

        return $this->postBalancedJournal($conn, $scope, [
            'details' => (string) ($refundContext['details'] ?? ('Recipe refund reversal for order ' . (int) ($refundContext['order_id'] ?? 0))),
            'total' => $total,
            'user_id' => (int) ($refundContext['user_id'] ?? $refundContext['created_by'] ?? 0),
            'op_id' => (int) ($refundContext['order_id'] ?? 0),
            'entries' => [
                ['account_id' => $inventoryAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Recipe inventory return'],
                ['account_id' => $cogsAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Recipe COGS reversal'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ], $refundContext);
    }

    private function postBalancedJournal(mysqli $conn, RecipeScope $scope, array $journal, array $syncContext): array
    {
        $ownsTransaction = !$this->connectionInTransaction($conn);
        $savepoint = 'recipe_accounting_post';
        if ($ownsTransaction) {
            $conn->begin_transaction();
        } else {
            $conn->query('SAVEPOINT ' . $savepoint);
        }

        try {
            $this->assertJournalPrecisionIsSafe($conn);
            $entries = $journal['entries'] ?? [];
            $this->assertBalancedEntries($entries);

            $journalId = $this->nextJournalId($conn, $scope->posTenant, $scope->posBranch);
            $total = Money::from(RoundingPolicy::halfUp(RecipeDecimal::normalize($journal['total'] ?? '0')))->toString();
            $postedEntries = [];
            foreach ($entries as $index => $entry) {
                $postedEntries[] = [
                    'account_id' => (int) $entry['account_id'],
                    'debit' => Money::from(RoundingPolicy::halfUp(RecipeDecimal::normalize($entry['debit'] ?? '0')))->toString(),
                    'credit' => Money::from(RoundingPolicy::halfUp(RecipeDecimal::normalize($entry['credit'] ?? '0')))->toString(),
                    'tybe' => $index,
                    'op2' => (int) ($journal['op2'] ?? $journal['op_id'] ?? 0),
                ];
            }
            $journalHeadId = JournalPostingService::postBalancedHead(
                $conn,
                (string) $journalId,
                $total,
                (string) ($journal['jdate'] ?? date('Y-m-d')),
                (string) ($journal['details'] ?? 'Recipe accounting'),
                (int) ($journal['user_id'] ?? 0),
                $postedEntries,
                [
                    'op_id' => (int) ($journal['op_id'] ?? 0),
                    'op2' => (int) ($journal['op2'] ?? 0),
                    'tenant' => $scope->posTenant,
                    'branch' => $scope->posBranch,
                    'source_type' => 'recipe_movement',
                    'source_id' => (int) (($journal['movement_ids'][0] ?? $journal['op_id'] ?? 0)),
                    'posting_kind' => (string) ($journal['posting_kind'] ?? 'recipe_accounting'),
                    'idempotency_key' => (string) ($journal['idempotency_key'] ?? ('recipe:' . md5(json_encode($journal['movement_ids'] ?? [])))),
                ]
            );
            $entryIds = [];
            $entryResult = $conn->query('SELECT id FROM journal_entries WHERE journal_id = ' . (int) $journalHeadId . ' ORDER BY id ASC');
            while ($row = $entryResult->fetch_assoc()) {
                $entryIds[] = (int) $row['id'];
            }
            $movementIds = array_values(array_map('intval', $journal['movement_ids'] ?? []));
            $this->movements->attachJournal($conn, $movementIds, $journalHeadId);

            $result = $this->captureJournalResult($conn, [
                'noop' => false,
                'journal_id' => $journalId,
                'journal_head_id' => $journalHeadId,
                'entry_ids' => $entryIds,
                'entry_count' => count($entryIds),
                'movement_ids' => $movementIds,
                'total' => $total,
            ], $syncContext);

            if ($ownsTransaction) {
                $conn->commit();
            } else {
                $conn->query('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            } else {
                $conn->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $conn->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    private function captureJournalResult(mysqli $conn, array $result, array $context): array
    {
        if (!empty($result['noop'])) {
            return $result;
        }

        $journalHeadId = (int) ($result['journal_head_id'] ?? 0);
        $movementIds = array_values(array_filter(
            array_map('intval', $result['movement_ids'] ?? []),
            static fn(int $id): bool => $id > 0
        ));
        if ($journalHeadId < 1 || $movementIds === []) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_RESULT_INVALID');
        }

        $syncConfig = $context['sync_config'] ?? $context['config'] ?? $this->flags->appConfig();
        $this->syncEvents->recordInventoryAccountingSnapshot($conn, $journalHeadId, $movementIds, [
            'config' => $syncConfig,
            'source_system' => (string) ($context['sync_source_system'] ?? 'recipe_accounting'),
            'event_type' => (string) ($context['sync_event_type'] ?? 'recipe.accounting_journal_saved'),
        ]);

        return $result;
    }

    private function connectionInTransaction(mysqli $conn): bool
    {
        $row = $conn->query('SELECT @@session.in_transaction AS active')->fetch_assoc();

        return (int) ($row['active'] ?? 0) === 1;
    }

    private function insertJournalHead(mysqli $conn, int $journalId, RecipeScope $scope, array $journal): int
    {
        throw new RuntimeException('JOURNAL_DIRECT_WRITE_FORBIDDEN');
    }

    private function insertJournalEntries(mysqli $conn, int $journalHeadId, RecipeScope $scope, array $journal): array
    {
        throw new RuntimeException('JOURNAL_DIRECT_WRITE_FORBIDDEN');
    }

    private function loadMovements(mysqli $conn, array $movements, array $allowedTypes): array
    {
        $ids = [];
        foreach ($movements as $movement) {
            if (is_array($movement)) {
                $id = (int) ($movement['id'] ?? 0);
            } else {
                $id = (int) $movement;
            }
            if ($id < 1) {
                throw new InvalidArgumentException('Recipe accounting movement id must be positive.');
            }

            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        if (!$ids) {
            return [];
        }

        $rows = $this->movements->findByIds($conn, $ids);
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($rowsById[$id])) {
                throw new RuntimeException('Recipe accounting movement id is missing.');
            }
            $row = $rowsById[$id];
            if (!in_array((string) ($row['movement_type'] ?? ''), $allowedTypes, true)) {
                throw new RuntimeException('Recipe accounting movement type is invalid for this posting.');
            }
            $ordered[] = $row;
        }

        return $ordered;
    }

    private function existingJournalResult(mysqli $conn, array $movements): ?array
    {
        $journalIds = [];
        foreach ($movements as $movement) {
            $journalId = (int) ($movement['accounting_journal_id'] ?? 0);
            if ($journalId < 1) {
                return null;
            }
            $journalIds[$journalId] = true;
        }
        if (count($journalIds) !== 1) {
            throw new RuntimeException('Recipe movements are linked to multiple accounting journals.');
        }

        $journalHeadId = (int) array_key_first($journalIds);
        $stmt = $conn->prepare('SELECT journal_id, total FROM journal_heads WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $journalHeadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('Recipe movement accounting journal is missing.');
        }

        return [
            'noop' => false,
            'existing' => true,
            'journal_id' => (int) $row['journal_id'],
            'journal_head_id' => $journalHeadId,
            'entry_count' => $this->entryCount($conn, $journalHeadId),
            'movement_ids' => array_map('intval', array_column($movements, 'id')),
            'total' => RecipeDecimal::normalize($row['total'] ?? '0'),
        ];
    }

    private function movementTotal(array $movements): string
    {
        $total = RecipeDecimal::zero();
        foreach ($movements as $movement) {
            $total = RecipeDecimal::add($total, (string) ($movement['total_cost'] ?? '0'));
        }

        return $total;
    }

    private function assertBalancedEntries(array $entries): void
    {
        if (!$entries) {
            throw new InvalidArgumentException('Recipe accounting journal requires at least one entry.');
        }

        $debits = RecipeDecimal::zero();
        $credits = RecipeDecimal::zero();
        foreach ($entries as $entry) {
            $accountId = (int) ($entry['account_id'] ?? 0);
            if ($accountId < 1) {
                throw new InvalidArgumentException('Recipe accounting entry requires a valid account.');
            }
            $debits = RecipeDecimal::add($debits, (string) ($entry['debit'] ?? '0'));
            $credits = RecipeDecimal::add($credits, (string) ($entry['credit'] ?? '0'));
        }

        if (RecipeDecimal::compare($debits, $credits) !== 0) {
            throw new RuntimeException('Recipe accounting journal is not balanced.');
        }
        if (!RecipeDecimal::isPositive($debits)) {
            throw new RuntimeException('Recipe accounting journal total must be positive.');
        }
    }

    private function assertJournalPrecisionIsSafe(mysqli $conn): void
    {
        foreach ([['journal_entries', 'debit'], ['journal_entries', 'credit']] as $column) {
            $type = strtolower($this->columnType($conn, $column[0], $column[1]));
            if (!preg_match('/\b(decimal|numeric)\b/', $type)) {
                throw new RuntimeException('Recipe accounting requires decimal-safe journal entry columns before posting.');
            }
        }
    }

    private function nextJournalId(mysqli $conn, int $posTenant, int $posBranch): int
    {
        $seed = $this->maxJournalId($conn, $posTenant, $posBranch);
        $this->counterService->ensureCounterRow($conn, $posTenant, $posBranch, 'journal_id', 'journal:recipe', $seed);

        return $this->counterService->nextJournalId($conn, $posTenant, $posBranch, 'recipe');
    }

    private function maxJournalId(mysqli $conn, int $posTenant, int $posBranch): int
    {
        if ($this->columnExists($conn, 'journal_heads', 'tenant') && $this->columnExists($conn, 'journal_heads', 'branch')) {
            $stmt = $conn->prepare("
SELECT COALESCE(MAX(journal_id), 0) AS max_id
FROM journal_heads
WHERE COALESCE(tenant, 0) = ?
  AND COALESCE(branch, 0) = ?");
            $stmt->bind_param('ii', $posTenant, $posBranch);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int) ($row['max_id'] ?? 0);
        }

        $row = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads')->fetch_assoc();

        return (int) ($row['max_id'] ?? 0);
    }

    private function requiredInventoryAccount(mysqli $conn, array $context): int
    {
        // Prefer explicit context/config mappings first so a specific raw/prepared
        // override is not shadowed by chart resolution of inventory_account_id.
        $configured = $this->settings->inventoryAccountId($context);
        if ($configured > 0) {
            return $configured;
        }

        $inventoryType = strtolower(trim((string) ($context['recipe_inventory_account_type'] ?? '')));
        $keys = $inventoryType === 'prepared'
            ? ['inventory_account_id', 'prepared_inventory_account_id', 'raw_inventory_account_id', 'packaging_inventory_account_id']
            : ['inventory_account_id', 'raw_inventory_account_id', 'prepared_inventory_account_id', 'packaging_inventory_account_id'];

        foreach ($keys as $key) {
            $value = function_exists('posmain_find_recipe_chart_account_id')
                ? posmain_find_recipe_chart_account_id($conn, $key)
                : 0;
            if ($value > 0) {
                return $value;
            }
        }

        throw new InvalidArgumentException('Recipe accounting inventory account is required.');
    }

    private function requiredAccount(mysqli $conn, array $context, string $key): int
    {
        $value = $this->resolveAccountId($conn, $context, $key);
        if ($value < 1) {
            throw new InvalidArgumentException('Recipe accounting account is required: ' . $key);
        }

        return $value;
    }

    private function resolveAccountId(mysqli $conn, array $context, string $key): int
    {
        $configured = $this->settings->accountId($key, $context);
        if (function_exists('posmain_resolve_recipe_accounting_account_id')) {
            return posmain_resolve_recipe_accounting_account_id($conn, $key, $configured);
        }

        return $configured > 0 ? $configured : 0;
    }

    private function scopeFromContext(array $context): RecipeScope
    {
        return new RecipeScope(
            (int) ($context['pos_tenant'] ?? $context['tenant'] ?? 0),
            (int) ($context['pos_branch'] ?? $context['branch'] ?? 0),
            $context['branch_uuid'] ?? null,
            (int) ($context['store_id'] ?? 0),
            (string) ($context['channel'] ?? 'pos'),
            (string) ($context['order_type'] ?? 'takeaway'),
            'recipe'
        );
    }

    private function itemCategoryId(mysqli $conn, int $itemId, array $context): ?int
    {
        $categoryId = (int) (
            $context['item_category_id']
            ?? $context['sellable_item_category_id']
            ?? $context['category_id']
            ?? $context['group1']
            ?? 0
        );

        if ($categoryId > 0) {
            return $categoryId;
        }
        if ($itemId < 1) {
            return null;
        }

        $databaseRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
        $database = (string) ($databaseRow['db_name'] ?? '');
        $cacheKey = $database . ':' . $itemId;
        if (array_key_exists($cacheKey, $this->itemCategoryCache)) {
            return $this->itemCategoryCache[$cacheKey];
        }
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            $this->itemCategoryCache[$cacheKey] = null;

            return null;
        }

        $stmt = $conn->prepare('SELECT group1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lookupCategoryId = (int) ($row['group1'] ?? 0);
        $this->itemCategoryCache[$cacheKey] = $lookupCategoryId > 0 ? $lookupCategoryId : null;

        return $this->itemCategoryCache[$cacheKey];
    }

    private function noop(string $reason): array
    {
        return [
            'noop' => true,
            'reason' => $reason,
            'journal_id' => null,
            'journal_head_id' => null,
            'entry_count' => 0,
            'movement_ids' => [],
        ];
    }

    private function entryCount(mysqli $conn, int $journalHeadId): int
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM journal_entries WHERE journal_id = ?');
        $stmt->bind_param('i', $journalHeadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?");
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }

    private function columnType(mysqli $conn, string $tableName, string $columnName): string
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            throw new RuntimeException("Missing required journal column {$tableName}.{$columnName}.");
        }

        return (string) $row['Type'];
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        if (!$params) {
            return;
        }

        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }

        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }

        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}
