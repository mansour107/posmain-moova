<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/JournalPostingGuard.php';

final class JournalPostingService
{
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
        JournalPostingGuard::assertBalancedEntries($entries);

        $stmt = $conn->prepare('
            INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id, op2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $opId = (int) ($meta['op_id'] ?? 0);
        $op2 = (int) ($meta['op2'] ?? 0);
        $totalFloat = (float) $total;
        $stmt->bind_param('sdsssii', $journalId, $totalFloat, $jdate, $details, $userId, $opId, $op2);
        $stmt->execute();
        $headId = (int) $conn->insert_id;
        $stmt->close();

        foreach ($entries as $entry) {
            $debit = (float) ($entry['debit'] ?? 0);
            $credit = (float) ($entry['credit'] ?? 0);
            $accountId = (string) ($entry['account_id'] ?? '');
            $tybe = (int) ($entry['tybe'] ?? 0);
            $entryOp2 = (int) ($entry['op2'] ?? $op2);
            $entryStmt = $conn->prepare('
                INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $entryStmt->bind_param('isddii', $headId, $accountId, $debit, $credit, $tybe, $entryOp2);
            $entryStmt->execute();
            $entryStmt->close();
        }

        return $headId;
    }
}
