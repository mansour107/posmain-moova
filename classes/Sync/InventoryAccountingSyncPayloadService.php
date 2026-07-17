<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../Financial/Money.php';

class InventoryAccountingSyncPayloadService
{
    private const HEAD_FIELDS = [
        'id', 'journal_id', 'total', 'jdate', 'op_id', 'pro_tybe', 'details', 'op2',
        'isdeleted', 'user', 'tenant', 'branch', 'source_type', 'source_id',
        'posting_kind', 'idempotency_key', 'reversal_of_journal_id',
    ];
    private const ENTRY_FIELDS = [
        'id', 'journal_id', 'account_id', 'debit', 'credit', 'tybe', 'op2', 'op_id',
        'isdeleted', 'tenant', 'branch',
    ];
    private const ACCOUNT_FIELDS = [
        'id', 'code', 'deletable', 'editable', 'aname', 'constant', 'is_stock',
        'is_fund', 'rentable', 'parent_id', 'nature', 'kind', 'is_basic',
        'isdeleted', 'tenant', 'branch',
    ];
    private const MOVEMENT_FIELDS = [
        'id', 'movement_uuid', 'pos_tenant', 'pos_branch', 'branch_uuid',
        'accounting_journal_id', 'movement_type', 'source_type', 'source_id',
    ];

    public function build(mysqli $conn, string $branchUuid, int $journalHeadId, array $movementIds): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!SyncBranchIdentity::isUuid($branchUuid) || $journalHeadId < 1) {
            throw new InvalidArgumentException('INVENTORY_JOURNAL_SYNC_IDENTITY_INVALID');
        }

        $head = $this->fetchOne($conn, 'journal_heads', $journalHeadId);
        if (!$head) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HEAD_MISSING');
        }
        $head = $this->selectFields($head, self::HEAD_FIELDS);

        $entries = $this->fetchByForeignKey($conn, 'journal_entries', 'journal_id', $journalHeadId);
        $entries = array_map(fn(array $row): array => $this->selectFields($row, self::ENTRY_FIELDS), $entries);

        $accountIds = [];
        foreach ($entries as $entry) {
            $accountId = (int) ($entry['account_id'] ?? 0);
            if ($accountId > 0) {
                $accountIds[$accountId] = $accountId;
            }
        }
        $accounts = $this->accountIdentitiesWithAncestors($conn, array_values($accountIds));
        $movements = $this->movementReferences($conn, $branchUuid, $journalHeadId, $movementIds);

        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($entries as $entry) {
            $debit = $debit->add(Money::from((string) ($entry['debit'] ?? '0')));
            $credit = $credit->add(Money::from((string) ($entry['credit'] ?? '0')));
        }

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'inventory_journal_bundle',
            'domain' => 'inventory_journal',
            'branch_uuid' => $branchUuid,
            // Inventory journals are immutable. Derive this metadata from the
            // immutable journal date so an idempotent outbox-healing replay
            // reproduces byte-for-byte equivalent content.
            'captured_at_utc' => $this->stableCapturedAtUtc($head),
            'sync_revision' => 1,
            'journal_head' => $head,
            'journal_entries' => $entries,
            'accounts' => $accounts,
            'movements' => $movements,
            'totals' => [
                'entry_count' => count($entries),
                'debit' => $debit->toString(),
                'credit' => $credit->toString(),
            ],
        ];
        $payload['payload_hash'] = hash('sha256', self::encodeJson($payload));
        $this->assertValid($payload, $branchUuid);

        return $payload;
    }

    public function assertValid(array $payload, string $branchUuid, array $event = []): void
    {
        $branchUuid = strtolower(trim($branchUuid));
        $allowedTop = [
            'schema_version', 'snapshot_type', 'domain', 'branch_uuid', 'captured_at_utc',
            'sync_revision', 'journal_head', 'journal_entries', 'accounts', 'movements',
            'totals', 'payload_hash',
        ];
        if (array_diff(array_keys($payload), $allowedTop) !== []
            || array_diff($allowedTop, array_keys($payload)) !== []
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['snapshot_type'] ?? '') !== 'inventory_journal_bundle'
            || (string) ($payload['domain'] ?? '') !== 'inventory_journal'
            || strtolower(trim((string) ($payload['branch_uuid'] ?? ''))) !== $branchUuid
            || !SyncBranchIdentity::isUuid($branchUuid)
            || (int) ($payload['sync_revision'] ?? 0) !== 1
        ) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_PAYLOAD_INVALID');
        }

        foreach (['journal_head', 'journal_entries', 'accounts', 'movements', 'totals'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_PAYLOAD_INVALID');
            }
        }

        $hashPayload = $payload;
        $expectedHash = trim((string) ($hashPayload['payload_hash'] ?? ''));
        unset($hashPayload['payload_hash']);
        $actualHash = hash('sha256', self::encodeJson($hashPayload));
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HASH_INVALID');
        }

        $head = $payload['journal_head'];
        $this->assertAllowedFields($head, self::HEAD_FIELDS, 'INVENTORY_JOURNAL_SYNC_HEAD_INVALID');
        $journalHeadId = (int) ($head['id'] ?? 0);
        if ($journalHeadId < 1
            || (int) ($head['journal_id'] ?? 0) < 1
            || !$this->isAllowedProvenancePair($head)
            || trim((string) ($head['idempotency_key'] ?? '')) === ''
            || (string) ($payload['captured_at_utc'] ?? '') !== $this->stableCapturedAtUtc($head)
        ) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HEAD_INVALID');
        }

        $accountMap = [];
        foreach ($payload['accounts'] as $account) {
            if (!is_array($account)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_INVALID');
            }
            $this->assertAllowedFields($account, self::ACCOUNT_FIELDS, 'INVENTORY_JOURNAL_SYNC_ACCOUNT_INVALID');
            $accountId = (int) ($account['id'] ?? 0);
            if ($accountId < 1 || isset($accountMap[$accountId])) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_INVALID');
            }
            $accountMap[$accountId] = $account;
        }

        $entryIds = [];
        $referencedAccounts = [];
        $debit = Money::zero();
        $credit = Money::zero();
        if (count($payload['journal_entries']) < 2) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ENTRIES_REQUIRED');
        }
        foreach ($payload['journal_entries'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ENTRY_INVALID');
            }
            $this->assertAllowedFields($entry, self::ENTRY_FIELDS, 'INVENTORY_JOURNAL_SYNC_ENTRY_INVALID');
            $entryId = (int) ($entry['id'] ?? 0);
            $accountId = (int) ($entry['account_id'] ?? 0);
            $entryDebit = Money::from((string) ($entry['debit'] ?? '0'));
            $entryCredit = Money::from((string) ($entry['credit'] ?? '0'));
            if ($entryId < 1
                || isset($entryIds[$entryId])
                || (int) ($entry['journal_id'] ?? 0) !== $journalHeadId
                || $accountId < 1
                || !isset($accountMap[$accountId])
                || ($entryDebit->isPositive() === $entryCredit->isPositive())
            ) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ENTRY_INVALID');
            }
            $entryIds[$entryId] = true;
            $referencedAccounts[$accountId] = true;
            $debit = $debit->add($entryDebit);
            $credit = $credit->add($entryCredit);
        }
        if (!$debit->isPositive()
            || $debit->compare($credit) !== 0
            || $debit->compare(Money::from((string) ($head['total'] ?? '0'))) !== 0
        ) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_UNBALANCED');
        }
        $this->assertAccountClosure($accountMap, $referencedAccounts);

        $movementIds = [];
        foreach ($payload['movements'] as $movement) {
            if (!is_array($movement)) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID');
            }
            $this->assertAllowedFields($movement, self::MOVEMENT_FIELDS, 'INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID');
            $movementId = (int) ($movement['id'] ?? 0);
            if ($movementId < 1
                || isset($movementIds[$movementId])
                || !SyncBranchIdentity::isUuid((string) ($movement['movement_uuid'] ?? ''))
                || strtolower(trim((string) ($movement['branch_uuid'] ?? ''))) !== $branchUuid
                || (int) ($movement['accounting_journal_id'] ?? 0) !== $journalHeadId
                || (int) ($movement['pos_tenant'] ?? 0) !== (int) ($head['tenant'] ?? 0)
                || (int) ($movement['pos_branch'] ?? 0) !== (int) ($head['branch'] ?? 0)
            ) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID');
            }
            $movementIds[$movementId] = true;
        }
        if ($movementIds === []) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENTS_REQUIRED');
        }

        $totals = $payload['totals'];
        if (array_diff(array_keys($totals), ['entry_count', 'debit', 'credit']) !== []
            || (int) ($totals['entry_count'] ?? -1) !== count($entryIds)
            || $debit->compare(Money::from((string) ($totals['debit'] ?? '0'))) !== 0
            || $credit->compare(Money::from((string) ($totals['credit'] ?? '0'))) !== 0
        ) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_TOTALS_INVALID');
        }

        if ($event !== []) {
            $aggregateUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'journal_heads:' . $journalHeadId);
            if ((string) ($event['aggregate_type'] ?? '') !== 'inventory_journal'
                || strtolower(trim((string) ($event['aggregate_uuid'] ?? ''))) !== $aggregateUuid
                || (int) ($event['aggregate_local_id'] ?? 0) !== $journalHeadId
                || (int) ($event['event_version'] ?? 0) !== 1
            ) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_EVENT_IDENTITY_INVALID');
            }
        }
    }

    private function isAllowedProvenancePair(array $head): bool
    {
        $sourceType = strtolower(trim((string) ($head['source_type'] ?? '')));
        $postingKind = strtolower(trim((string) ($head['posting_kind'] ?? '')));

        return ($sourceType === 'inventory_movement' && $postingKind === 'inventory_accounting')
            || ($sourceType === 'recipe_movement' && $postingKind === 'recipe_accounting');
    }

    private function movementReferences(
        mysqli $conn,
        string $branchUuid,
        int $journalHeadId,
        array $movementIds
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $movementIds), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENTS_REQUIRED');
        }

        $result = $conn->query('SELECT * FROM inventory_movements WHERE id IN (' . implode(',', $ids) . ') ORDER BY id ASC');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rowBranchUuid = strtolower(trim((string) ($row['branch_uuid'] ?? '')));
            if ($rowBranchUuid !== '' && $rowBranchUuid !== $branchUuid) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID');
            }
            $row['branch_uuid'] = $branchUuid;
            $rows[] = $this->selectFields($row, self::MOVEMENT_FIELDS);
        }
        if (array_map(static fn(array $row): int => (int) $row['id'], $rows) !== $ids) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_MISSING');
        }
        foreach ($rows as $row) {
            if ((int) ($row['accounting_journal_id'] ?? 0) !== $journalHeadId) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_MOVEMENT_INVALID');
            }
        }

        return $rows;
    }

    private function accountIdentitiesWithAncestors(mysqli $conn, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $accounts = [];
        $pending = array_fill_keys(array_map('intval', $accountIds), true);
        while ($pending !== []) {
            $ids = array_keys($pending);
            $pending = [];
            $result = $conn->query('SELECT * FROM acc_head WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id ASC');
            while ($row = $result->fetch_assoc()) {
                $accountId = (int) ($row['id'] ?? 0);
                if ($accountId < 1 || isset($accounts[$accountId])) {
                    continue;
                }
                $accounts[$accountId] = $this->selectFields($row, self::ACCOUNT_FIELDS);
                $parentId = (int) ($row['parent_id'] ?? 0);
                if ($parentId > 0 && !isset($accounts[$parentId])) {
                    $pending[$parentId] = true;
                }
            }
        }
        foreach ($accountIds as $accountId) {
            if (!isset($accounts[(int) $accountId])) {
                throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_MISSING');
            }
        }
        ksort($accounts, SORT_NUMERIC);

        return array_values($accounts);
    }

    private function assertAccountClosure(array $accounts, array $referenced): void
    {
        $allowed = $referenced;
        $pending = array_keys($referenced);
        while ($pending !== []) {
            $accountId = (int) array_pop($pending);
            $parentId = (int) ($accounts[$accountId]['parent_id'] ?? 0);
            if ($parentId > 0) {
                if (!isset($accounts[$parentId])) {
                    throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_PARENT_MISSING');
                }
                if (!isset($allowed[$parentId])) {
                    $allowed[$parentId] = true;
                    $pending[] = $parentId;
                }
            }
        }
        if (array_diff_key($accounts, $allowed) !== []) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_ACCOUNT_SCOPE_INVALID');
        }
    }

    private function fetchOne(mysqli $conn, string $table, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchByForeignKey(mysqli $conn, string $table, string $column, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY id ASC");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function selectFields(array $row, array $allowed): array
    {
        $selected = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $row)) {
                $selected[$field] = $row[$field];
            }
        }

        return $selected;
    }

    private function assertAllowedFields(array $row, array $allowed, string $error): void
    {
        if (array_diff(array_keys($row), $allowed) !== []) {
            throw new RuntimeException($error);
        }
    }

    private function stableCapturedAtUtc(array $head): string
    {
        $journalDate = trim((string) ($head['jdate'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journalDate)) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_HEAD_INVALID');
        }

        return $journalDate . 'T00:00:00Z';
    }

    private static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('INVENTORY_JOURNAL_SYNC_JSON_INVALID');
        }

        return $json;
    }
}
