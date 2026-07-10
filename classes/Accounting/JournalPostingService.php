<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/JournalPostingGuard.php';
require_once __DIR__ . '/../Financial/Money.php';

final class JournalPostingService
{
    public static function findByIdempotencyKey(mysqli $conn, string $idempotencyKey): ?array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }
        if (!self::columnExists($conn, 'journal_heads', 'idempotency_key')) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT id, journal_id, total, op_id, source_type, source_id, posting_kind
            FROM journal_heads
            WHERE idempotency_key = ?
            LIMIT 1
        ');
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    public static function postBalancedHead(
        mysqli $conn,
        string $journalId,
        string $total,
        string $jdate,
        string $details,
        int $userId,
        array $entries,
        array $meta = []
    ): int {
        $total = Money::from($total)->toString();
        $idempotencyKey = trim((string) ($meta['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '') {
            $existing = self::findByIdempotencyKey($conn, $idempotencyKey);
            if ($existing !== null) {
                if (Money::from((string) ($existing['total'] ?? '0'))->compare(Money::from($total)) !== 0) {
                    throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
                }

                return (int) $existing['id'];
            }
        }
        foreach ($entries as $index => $entry) {
            $entries[$index]['debit'] = Money::from($entry['debit'] ?? '0')->toString();
            $entries[$index]['credit'] = Money::from($entry['credit'] ?? '0')->toString();
        }
        JournalPostingGuard::assertBalancedEntries($entries, Money::SCALE);

        $opId = (int) ($meta['op_id'] ?? 0);
        $op2 = (int) ($meta['op2'] ?? 0);
        $columns = ['journal_id', 'total', 'jdate', 'details', 'user', 'op_id', 'op2'];
        $values = [$journalId, $total, $jdate, $details, $userId, $opId, $op2];
        foreach (['source_type', 'source_id', 'posting_kind', 'idempotency_key', 'reversal_of_journal_id', 'tenant', 'branch', 'pro_tybe'] as $column) {
            if (self::columnExists($conn, 'journal_heads', $column)) {
                $columns[] = $column;
                if ($column === 'idempotency_key') {
                    $values[] = $idempotencyKey !== '' ? $idempotencyKey : null;
                } elseif (in_array($column, ['tenant', 'branch', 'pro_tybe', 'source_id', 'reversal_of_journal_id'], true)) {
                    $values[] = isset($meta[$column]) ? (int) $meta[$column] : null;
                } else {
                    $values[] = $meta[$column] ?? null;
                }
            }
        }
        $quotedColumns = '`' . implode('`, `', $columns) . '`';
        $stmt = $conn->prepare('INSERT INTO journal_heads (' . $quotedColumns . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')');
        self::bind($stmt, $values);
        $stmt->execute();
        $headId = (int) $conn->insert_id;
        $stmt->close();

        foreach ($entries as $entry) {
            $debit = (string) ($entry['debit'] ?? '0.00');
            $credit = (string) ($entry['credit'] ?? '0.00');
            $accountId = (int) ($entry['account_id'] ?? 0);
            $tybe = (int) ($entry['tybe'] ?? 0);
            $entryOp2 = (int) ($entry['op2'] ?? $op2);
            $entryStmt = $conn->prepare('
                INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $entryStmt->bind_param('iissii', $headId, $accountId, $debit, $credit, $tybe, $entryOp2);
            $entryStmt->execute();
            $entryStmt->close();
        }

        return $headId;
    }

    /**
     * Append-only correction: post a linked reversal that swaps debit/credit.
     * Never updates or deletes the original journal.
     */
    public static function postReversal(
        mysqli $conn,
        int $originalJournalHeadId,
        int $userId,
        string $reason,
        array $meta = []
    ): int {
        $stmt = $conn->prepare('
            SELECT id, journal_id, total, jdate, details, op_id, op2, source_type, source_id, posting_kind
            FROM journal_heads
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $originalJournalHeadId);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$original) {
            throw new InvalidArgumentException('JOURNAL_NOT_FOUND');
        }

        $entriesResult = $conn->query('
            SELECT account_id, debit, credit, tybe, op2
            FROM journal_entries
            WHERE journal_id = ' . (int) $originalJournalHeadId . '
            ORDER BY id ASC
        ');
        $entries = [];
        while ($row = $entriesResult->fetch_assoc()) {
            $entries[] = [
                'account_id' => (int) $row['account_id'],
                'debit' => Money::from((string) $row['credit'])->toString(),
                'credit' => Money::from((string) $row['debit'])->toString(),
                'tybe' => Money::from((string) $row['debit'])->isPositive() ? 1 : 0,
                'op2' => (int) ($row['op2'] ?? 0),
            ];
        }
        if ($entries === []) {
            throw new InvalidArgumentException('JOURNAL_ENTRIES_REQUIRED');
        }

        $reversalJournalId = trim((string) ($meta['journal_id'] ?? ''));
        if ($reversalJournalId === '') {
            throw new InvalidArgumentException('REVERSAL_JOURNAL_ID_REQUIRED');
        }

        return self::postBalancedHead(
            $conn,
            $reversalJournalId,
            Money::from((string) $original['total'])->toString(),
            (string) ($meta['jdate'] ?? date('Y-m-d')),
            'Reversal: ' . $reason,
            $userId,
            $entries,
            [
                'op_id' => (int) ($meta['op_id'] ?? $original['op_id'] ?? 0),
                'op2' => (int) ($meta['op2'] ?? $original['op2'] ?? 0),
                'source_type' => (string) ($meta['source_type'] ?? ($original['source_type'] ?? 'journal_reversal')),
                'source_id' => (int) ($meta['source_id'] ?? $originalJournalHeadId),
                'posting_kind' => (string) ($meta['posting_kind'] ?? 'journal_reversal'),
                'idempotency_key' => (string) ($meta['idempotency_key'] ?? ('reversal:' . $originalJournalHeadId)),
                'reversal_of_journal_id' => $originalJournalHeadId,
            ]
        );
    }

    private static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result !== false && $result->num_rows > 0;
    }

    private static function bind(mysqli_stmt $stmt, array $values): void
    {
        $types = '';
        $bound = [];
        foreach ($values as $index => $value) {
            $types .= is_int($value) ? 'i' : 's';
            $bound[$index] = $value;
        }
        $arguments = [$types];
        foreach ($bound as $index => $_value) {
            $arguments[] = &$bound[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $arguments);
    }
}
