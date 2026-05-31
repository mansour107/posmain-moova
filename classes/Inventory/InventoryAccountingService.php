<?php

require_once __DIR__ . '/InventoryDecimal.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/../Sync/DocumentCounterService.php';
require_once __DIR__ . '/../Recipe/Repository/InventoryMovementRepository.php';

class InventoryAccountingService
{
    private InventoryFeatureFlags $flags;
    private DocumentCounterService $counterService;
    private InventoryMovementRepository $movements;

    public function __construct(
        ?InventoryFeatureFlags $flags = null,
        ?DocumentCounterService $counterService = null,
        ?InventoryMovementRepository $movements = null
    ) {
        $this->flags = $flags ?: new InventoryFeatureFlags();
        $this->counterService = $counterService ?: new DocumentCounterService();
        $this->movements = $movements ?: new InventoryMovementRepository();
    }

    public function postPurchaseReceipt(mysqli $conn, array $context, array $movementIds): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return $this->noop('inventory accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $movementIds, ['purchase']);
        if (!$rows) {
            return $this->noop('no purchase movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $existing;
        }

        $total = $this->movementTotal($rows);
        $inventoryAccountId = $this->requiredAccount($context, 'inventory_asset_account_id');
        $clearingAccountId = $this->purchaseClearingAccount($context);

        return $this->postBalancedJournal($conn, $context, [
            'details' => (string) ($context['details'] ?? 'Inventory purchase receipt'),
            'total' => $total,
            'user_id' => (int) ($context['user_id'] ?? $context['created_by'] ?? 0),
            'op_id' => (int) ($context['receipt_id'] ?? $context['purchase_receipt_id'] ?? 0),
            'entries' => [
                ['account_id' => $inventoryAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Inventory purchase valuation'],
                ['account_id' => $clearingAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Inventory purchase clearing'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ]);
    }

    public function postPurchaseReturn(mysqli $conn, array $context, array $movementIds): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return $this->noop('inventory accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $movementIds, ['purchase_return']);
        if (!$rows) {
            return $this->noop('no purchase return movements to post');
        }
        $this->assertSingleDirection($rows, false);
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $existing;
        }

        $total = $this->movementTotal($rows);
        $inventoryAccountId = $this->requiredAccount($context, 'inventory_asset_account_id');
        $clearingAccountId = $this->purchaseClearingAccount($context);

        return $this->postBalancedJournal($conn, $context, [
            'details' => (string) ($context['details'] ?? 'Inventory purchase return'),
            'total' => $total,
            'user_id' => (int) ($context['user_id'] ?? $context['created_by'] ?? 0),
            'op_id' => (int) ($context['receipt_id'] ?? $context['purchase_receipt_id'] ?? 0),
            'entries' => [
                ['account_id' => $clearingAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Inventory purchase return clearing'],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Inventory purchase return stock credit'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ]);
    }

    public function postSaleCogs(mysqli $conn, array $context, array $movementIds): array
    {
        return $this->postOutboundCost($conn, $context, $movementIds, ['sale_direct'], 'cogs_account_id', 'Inventory COGS');
    }

    public function postWaste(mysqli $conn, array $context, array $movementIds): array
    {
        return $this->postOutboundCost($conn, $context, $movementIds, ['waste'], 'waste_expense_account_id', 'Inventory waste');
    }

    public function postAdjustment(mysqli $conn, array $context, array $movementIds): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return $this->noop('inventory accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $movementIds, ['adjustment']);
        if (!$rows) {
            return $this->noop('no adjustment movements to post');
        }

        $groups = $this->groupAdjustmentMovements($rows);
        if (count($groups) > 1) {
            $results = [];
            foreach ($groups as $group) {
                $results[] = $this->postAdjustmentRows($conn, $context, $group['rows'], $group['has_qty_in']);
            }

            return $this->combineJournalResults($results);
        }

        $group = $groups[0];

        return $this->postAdjustmentRows($conn, $context, $group['rows'], $group['has_qty_in']);
    }

    private function postAdjustmentRows(mysqli $conn, array $context, array $rows, bool $hasQtyIn): array
    {
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $existing;
        }

        $total = $this->movementTotal($rows);
        $inventoryAccountId = $this->requiredAccount($context, 'inventory_asset_account_id');
        $gainLossAccountId = $this->requiredAccount($context, 'adjustment_gain_loss_account_id');
        $entries = $hasQtyIn
            ? [
                ['account_id' => $inventoryAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Inventory adjustment gain'],
                ['account_id' => $gainLossAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Inventory adjustment gain offset'],
            ]
            : [
                ['account_id' => $gainLossAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Inventory adjustment loss'],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Inventory adjustment stock credit'],
            ];

        return $this->postBalancedJournal($conn, $context, [
            'details' => (string) ($context['details'] ?? 'Inventory adjustment'),
            'total' => $total,
            'user_id' => (int) ($context['user_id'] ?? $context['created_by'] ?? 0),
            'op_id' => (int) ($context['operation_id'] ?? $context['count_id'] ?? 0),
            'entries' => $entries,
            'movement_ids' => array_column($rows, 'id'),
        ]);
    }

    private function groupAdjustmentMovements(array $rows): array
    {
        $inbound = [];
        $outbound = [];
        foreach ($rows as $row) {
            $hasQtyIn = InventoryDecimal::isPositive($row['qty_in'] ?? '0');
            $hasQtyOut = InventoryDecimal::isPositive($row['qty_out'] ?? '0');
            if ($hasQtyIn === $hasQtyOut) {
                throw new RuntimeException('Inventory accounting adjustment direction is invalid.');
            }
            if ($hasQtyIn) {
                $inbound[] = $row;
            } else {
                $outbound[] = $row;
            }
        }

        $groups = [];
        if ($inbound) {
            $groups[] = ['has_qty_in' => true, 'rows' => $inbound];
        }
        if ($outbound) {
            $groups[] = ['has_qty_in' => false, 'rows' => $outbound];
        }

        return $groups;
    }

    private function combineJournalResults(array $results): array
    {
        $entryCount = 0;
        $movementIds = [];
        $total = InventoryDecimal::zero();
        $journalIds = [];
        $journalHeadIds = [];

        foreach ($results as $result) {
            $entryCount += (int) ($result['entry_count'] ?? 0);
            $movementIds = array_merge($movementIds, array_map('intval', $result['movement_ids'] ?? []));
            $total = InventoryDecimal::add($total, (string) ($result['total'] ?? '0'));
            if ((int) ($result['journal_id'] ?? 0) > 0) {
                $journalIds[] = (int) $result['journal_id'];
            }
            if ((int) ($result['journal_head_id'] ?? 0) > 0) {
                $journalHeadIds[] = (int) $result['journal_head_id'];
            }
        }

        return [
            'noop' => false,
            'grouped' => true,
            'journal_count' => count($journalHeadIds),
            'journal_ids' => $journalIds,
            'journal_head_ids' => $journalHeadIds,
            'entry_count' => $entryCount,
            'movement_ids' => $movementIds,
            'total' => $total,
            'groups' => $results,
        ];
    }

    public function postRefundReversal(mysqli $conn, array $context, array $movementIds): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return $this->noop('inventory accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $movementIds, ['refund_reversal']);
        if (!$rows) {
            return $this->noop('no refund reversal movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $existing;
        }

        $total = $this->movementTotal($rows);
        $inventoryAccountId = $this->requiredAccount($context, 'inventory_asset_account_id');
        $cogsAccountId = $this->requiredAccount($context, 'cogs_account_id');

        return $this->postBalancedJournal($conn, $context, [
            'details' => (string) ($context['details'] ?? 'Inventory refund reversal'),
            'total' => $total,
            'user_id' => (int) ($context['user_id'] ?? $context['created_by'] ?? 0),
            'op_id' => (int) ($context['order_id'] ?? 0),
            'entries' => [
                ['account_id' => $inventoryAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => 'Inventory return'],
                ['account_id' => $cogsAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => 'Inventory COGS reversal'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ]);
    }

    private function postOutboundCost(mysqli $conn, array $context, array $movementIds, array $types, string $debitAccountKey, string $label): array
    {
        if (!$this->flags->isAccountingEnabled()) {
            return $this->noop('inventory accounting is disabled');
        }

        $rows = $this->loadMovements($conn, $movementIds, $types);
        if (!$rows) {
            return $this->noop('no outbound movements to post');
        }
        if ($existing = $this->existingJournalResult($conn, $rows)) {
            return $existing;
        }

        $total = $this->movementTotal($rows);
        $debitAccountId = $this->requiredAccount($context, $debitAccountKey);
        $inventoryAccountId = $this->requiredAccount($context, 'inventory_asset_account_id');

        return $this->postBalancedJournal($conn, $context, [
            'details' => (string) ($context['details'] ?? $label),
            'total' => $total,
            'user_id' => (int) ($context['user_id'] ?? $context['created_by'] ?? 0),
            'op_id' => (int) ($context['order_id'] ?? $context['operation_id'] ?? 0),
            'entries' => [
                ['account_id' => $debitAccountId, 'debit' => $total, 'credit' => '0.000000', 'info' => $label],
                ['account_id' => $inventoryAccountId, 'debit' => '0.000000', 'credit' => $total, 'info' => $label . ' stock credit'],
            ],
            'movement_ids' => array_column($rows, 'id'),
        ]);
    }

    private function postBalancedJournal(mysqli $conn, array $context, array $journal): array
    {
        $this->assertJournalTables($conn);
        $this->assertBalancedEntries($journal['entries'] ?? []);

        $tenant = (int) ($context['pos_tenant'] ?? $context['tenant'] ?? 0);
        $branch = (int) ($context['pos_branch'] ?? $context['branch'] ?? 0);
        $journalId = $this->nextJournalId($conn, $tenant, $branch);
        $journalHeadId = $this->insertJournalHead($conn, $journalId, $tenant, $branch, $journal);
        $entryIds = $this->insertJournalEntries($conn, $journalHeadId, $tenant, $branch, $journal);
        $movementIds = array_values(array_map('intval', $journal['movement_ids'] ?? []));
        $this->movements->attachJournal($conn, $movementIds, $journalHeadId);

        return [
            'noop' => false,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
            'entry_ids' => $entryIds,
            'entry_count' => count($entryIds),
            'movement_ids' => $movementIds,
            'total' => InventoryDecimal::normalize($journal['total'] ?? '0'),
        ];
    }

    private function insertJournalHead(mysqli $conn, int $journalId, int $tenant, int $branch, array $journal): int
    {
        $columns = [
            'journal_id' => $journalId,
            'total' => InventoryDecimal::normalize($journal['total'] ?? '0'),
            'jdate' => (string) ($journal['jdate'] ?? date('Y-m-d')),
            'details' => (string) ($journal['details'] ?? 'Inventory accounting'),
            'user' => (int) ($journal['user_id'] ?? 0),
        ];
        foreach (['op_id', 'op2', 'pro_tybe'] as $column) {
            if ($this->columnExists($conn, 'journal_heads', $column)) {
                $columns[$column] = (int) ($journal[$column] ?? 0);
            }
        }
        if ($this->columnExists($conn, 'journal_heads', 'tenant')) {
            $columns['tenant'] = $tenant;
        }
        if ($this->columnExists($conn, 'journal_heads', 'branch')) {
            $columns['branch'] = $branch;
        }

        $names = array_keys($columns);
        $sql = 'INSERT INTO journal_heads (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, array_values($columns));
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertJournalEntries(mysqli $conn, int $journalHeadId, int $tenant, int $branch, array $journal): array
    {
        $entryIds = [];
        $index = 0;
        foreach ($journal['entries'] as $entry) {
            $columns = [
                'journal_id' => $journalHeadId,
                'account_id' => (int) $entry['account_id'],
                'debit' => InventoryDecimal::normalize($entry['debit'] ?? '0'),
                'credit' => InventoryDecimal::normalize($entry['credit'] ?? '0'),
                'tybe' => $index,
            ];
            if ($this->columnExists($conn, 'journal_entries', 'info')) {
                $columns['info'] = (string) ($entry['info'] ?? $journal['details'] ?? 'Inventory accounting');
            }
            if ($this->columnExists($conn, 'journal_entries', 'op_id')) {
                $columns['op_id'] = (int) ($journal['op_id'] ?? 0);
            }
            if ($this->columnExists($conn, 'journal_entries', 'op2')) {
                $columns['op2'] = (int) ($journal['op2'] ?? 0);
            }
            if ($this->columnExists($conn, 'journal_entries', 'tenant')) {
                $columns['tenant'] = $tenant;
            }
            if ($this->columnExists($conn, 'journal_entries', 'branch')) {
                $columns['branch'] = $branch;
            }

            $names = array_keys($columns);
            $sql = 'INSERT INTO journal_entries (`' . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $stmt = $conn->prepare($sql);
            $this->bindParams($stmt, array_values($columns));
            $stmt->execute();
            $entryIds[] = (int) $conn->insert_id;
            $stmt->close();
            $index++;
        }

        return $entryIds;
    }

    private function loadMovements(mysqli $conn, array $movementIds, array $allowedTypes): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $movementIds), static fn($id) => $id > 0)));
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
                throw new RuntimeException('Inventory accounting movement id is missing.');
            }
            $row = $rowsById[$id];
            if (!in_array((string) ($row['movement_type'] ?? ''), $allowedTypes, true)) {
                throw new RuntimeException('Inventory accounting movement type is invalid for this posting.');
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
            throw new RuntimeException('Inventory movements are linked to multiple accounting journals.');
        }

        $journalHeadId = (int) array_key_first($journalIds);
        $stmt = $conn->prepare('SELECT journal_id, total FROM journal_heads WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $journalHeadId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('Inventory movement accounting journal is missing.');
        }

        return [
            'noop' => false,
            'existing' => true,
            'journal_id' => (int) $row['journal_id'],
            'journal_head_id' => $journalHeadId,
            'entry_count' => $this->entryCount($conn, $journalHeadId),
            'movement_ids' => array_map('intval', array_column($movements, 'id')),
            'total' => InventoryDecimal::normalize($row['total'] ?? '0'),
        ];
    }

    private function movementTotal(array $movements): string
    {
        $total = InventoryDecimal::zero();
        foreach ($movements as $movement) {
            $total = InventoryDecimal::add($total, (string) ($movement['total_cost'] ?? '0'));
        }
        if (!InventoryDecimal::isPositive($total)) {
            throw new RuntimeException('Inventory accounting journal total must be positive.');
        }

        return $total;
    }

    private function assertSingleDirection(array $movements, bool $expectedQtyIn): void
    {
        foreach ($movements as $movement) {
            $hasQtyIn = InventoryDecimal::isPositive($movement['qty_in'] ?? '0');
            $hasQtyOut = InventoryDecimal::isPositive($movement['qty_out'] ?? '0');
            if ($hasQtyIn === $hasQtyOut || $hasQtyIn !== $expectedQtyIn) {
                throw new RuntimeException('Inventory accounting adjustment direction is invalid.');
            }
        }
    }

    private function hasQtyIn(array $movements): bool
    {
        foreach ($movements as $movement) {
            if (InventoryDecimal::isPositive($movement['qty_in'] ?? '0')) {
                return true;
            }
        }

        return false;
    }

    private function assertBalancedEntries(array $entries): void
    {
        if (!$entries) {
            throw new InvalidArgumentException('Inventory accounting journal requires at least one entry.');
        }

        $debits = InventoryDecimal::zero();
        $credits = InventoryDecimal::zero();
        foreach ($entries as $entry) {
            if ((int) ($entry['account_id'] ?? 0) < 1) {
                throw new InvalidArgumentException('Inventory accounting entry requires a valid account.');
            }
            $debits = InventoryDecimal::add($debits, (string) ($entry['debit'] ?? '0'));
            $credits = InventoryDecimal::add($credits, (string) ($entry['credit'] ?? '0'));
        }
        if (InventoryDecimal::compare($debits, $credits) !== 0) {
            throw new RuntimeException('Inventory accounting journal is not balanced.');
        }
        if (!InventoryDecimal::isPositive($debits)) {
            throw new RuntimeException('Inventory accounting journal total must be positive.');
        }
    }

    private function assertJournalTables(mysqli $conn): void
    {
        foreach (['journal_heads', 'journal_entries', 'document_counters'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                throw new RuntimeException('Inventory accounting requires table: ' . $table);
            }
        }
        foreach ([['journal_entries', 'debit'], ['journal_entries', 'credit']] as $column) {
            $type = strtolower($this->columnType($conn, $column[0], $column[1]));
            if (!preg_match('/\b(decimal|numeric)\b/', $type)) {
                throw new RuntimeException('Inventory accounting requires decimal-safe journal entry columns before posting.');
            }
        }
    }

    private function purchaseClearingAccount(array $context): int
    {
        $supplierAccountId = (int) ($context['supplier_account_id'] ?? 0);
        if ($supplierAccountId > 0) {
            return $supplierAccountId;
        }

        return $this->requiredAccount($context, 'purchase_clearing_account_id');
    }

    private function requiredAccount(array $context, string $key): int
    {
        $value = (int) ($context[$key] ?? 0);
        if ($value < 1) {
            $accounts = $this->accounts();
            $value = (int) ($accounts[$key] ?? 0);
        }
        if ($key === 'inventory_asset_account_id' && $value < 1) {
            $value = (int) ($context['inventory_account_id'] ?? $this->accounts()['inventory_account_id'] ?? 0);
        }
        if ($value < 1) {
            throw new InvalidArgumentException('Inventory accounting account is required: ' . $key);
        }

        return $value;
    }

    private function accounts(): array
    {
        $config = $this->flags->config();
        $accounts = is_array($config['accounts'] ?? null) ? $config['accounts'] : [];

        return array_map('intval', $accounts);
    }

    private function nextJournalId(mysqli $conn, int $tenant, int $branch): int
    {
        $seed = $this->maxJournalId($conn, $tenant, $branch);
        $this->counterService->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:inventory', $seed);

        return $this->counterService->nextJournalId($conn, $tenant, $branch, 'inventory');
    }

    private function maxJournalId(mysqli $conn, int $tenant, int $branch): int
    {
        if ($this->columnExists($conn, 'journal_heads', 'tenant') && $this->columnExists($conn, 'journal_heads', 'branch')) {
            $stmt = $conn->prepare("
SELECT COALESCE(MAX(journal_id), 0) AS max_id
FROM journal_heads
WHERE COALESCE(tenant, 0) = ?
  AND COALESCE(branch, 0) = ?");
            $stmt->bind_param('ii', $tenant, $branch);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int) ($row['max_id'] ?? 0);
        }

        $row = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads')->fetch_assoc();

        return (int) ($row['max_id'] ?? 0);
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

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
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
}
