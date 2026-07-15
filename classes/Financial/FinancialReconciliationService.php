<?php

require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/FinancialCertificationBaselineService.php';

/**
 * Exact-money financial reconciliations.
 *
 * `differences` must all be zero for ok=true.
 * `liabilities` (e.g. pending external refunds) are reported separately: they are
 * expected open work, not bookkeeping mismatches.
 */
final class FinancialReconciliationService
{
    /**
     * @return array{ok:bool,checked_at_utc:string,differences:array<string,int>,liabilities:array<string,int>,blockers:array<int,string>}
     */
    public function runAll(mysqli $conn): array
    {
        $checks = [
            'invoice_vs_lines' => fn (): int => $this->invoiceVersusLines($conn),
            'invoice_vs_payments_refunds' => fn (): int => $this->invoiceVersusPaymentsAndRefunds($conn),
            'payment_methods_vs_accounts' => fn (): int => $this->paymentMethodsVersusAccounts($conn),
            'cash_vs_drawer' => fn (): int => $this->cashVersusDrawer($conn),
            'journal_debit_vs_credit' => fn (): int => $this->journalDebitVersusCredit($conn),
            'journal_vs_sources' => fn (): int => $this->journalVersusSources($conn),
            'account_balance_vs_journals' => fn (): int => $this->accountBalanceVersusJournals($conn),
            'inventory_vs_movements' => fn (): int => $this->inventoryVersusMovements($conn),
            'vat_vs_tax_snapshots' => fn (): int => $this->vatVersusTaxSnapshots($conn),
            'orphaned_journals' => fn (): int => $this->orphanedJournals($conn),
        ];
        $differences = [];
        $errors = [];
        foreach ($checks as $name => $check) {
            try {
                $differences[$name] = $check();
            } catch (Throwable $exception) {
                $differences[$name] = 0;
                $errors[$name] = $exception->getMessage();
            }
        }
        try {
            $liabilities = ['pending_external_refunds' => $this->pendingExternalRefunds($conn)];
        } catch (Throwable $exception) {
            $liabilities = ['pending_external_refunds' => 0];
            $errors['pending_external_refunds'] = $exception->getMessage();
        }
        $historical = null;
        try {
            $historical = (new FinancialCertificationBaselineService())->active($conn);
        } catch (Throwable $exception) {
            $errors['historical_baseline'] = $exception->getMessage();
        }

        $blockers = [];
        foreach ($differences as $name => $count) {
            if ($count !== 0) {
                $blockers[] = $name;
            }
        }
        if ($errors !== []) {
            $blockers[] = 'reconciliation_check_failed';
        }

        return [
            'ok' => $blockers === [],
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'differences' => $differences,
            'liabilities' => $liabilities,
            'errors' => $errors,
            'historical' => $historical,
            'blockers' => $blockers,
            'certified_mode' => true,
        ];
    }

    public function invoiceVersusLines(mysqli $conn): int
    {
        $this->requireTables($conn, ['ot_head', 'fat_details']);
        if (!$this->columnExists($conn, 'fat_details', 'posted_net')) {
            throw new RuntimeException('POSTED_LINE_SNAPSHOTS_REQUIRED');
        }
        $sql = "
            SELECT COUNT(*) AS c FROM (
                SELECT oh.id
                FROM ot_head oh
                LEFT JOIN fat_details fd
                  ON fd.fatid = oh.id AND COALESCE(fd.isdeleted, 0) = 0
                WHERE oh.pro_tybe = 9
                  AND COALESCE(oh.isdeleted, 0) = 0
                  AND COALESCE(oh.payment_status, '') = 'paid'
                GROUP BY oh.id, oh.fat_net
                HAVING SUM(CASE WHEN fd.id IS NOT NULL AND fd.posted_net IS NULL THEN 1 ELSE 0 END) > 0
                    OR ROUND(COALESCE(oh.fat_net, 0), 2) <> ROUND(COALESCE(SUM(fd.posted_net), 0), 2)
            ) finalized_line_differences
        ";
        return $this->countRows($conn, $sql);
    }

    public function invoiceVersusPaymentsAndRefunds(mysqli $conn): int
    {
        $this->requireTables($conn, ['ot_head', 'order_payments', 'payment_refunds']);
        $refundExpr = '(SELECT COALESCE(SUM(pr.amount), 0) FROM payment_refunds pr WHERE pr.original_order_id = oh.id AND COALESCE(pr.status, \"posted\") = \"posted\")';
        $sql = "
            SELECT COUNT(*) AS c
            FROM ot_head oh
            WHERE oh.pro_tybe = 9
              AND COALESCE(oh.isdeleted, 0) = 0
              AND COALESCE(oh.payment_status, '') = 'paid'
              AND ROUND(COALESCE(oh.fat_net, 0), 2) <> ROUND(
                    (SELECT COALESCE(SUM(op.amount), 0) FROM order_payments op WHERE op.order_id = oh.id)
                    - {$refundExpr}
                  , 2)
        ";
        return $this->countRows($conn, $sql);
    }

    public function paymentMethodsVersusAccounts(mysqli $conn): int
    {
        $this->requireTables($conn, ['payment_methods']);
        $row = $conn->query("
            SELECT COUNT(*) AS c
            FROM payment_methods
            WHERE is_active = 1
              AND (
                account_id IS NULL
                OR type NOT IN ('cash', 'card', 'wallet', 'bank')
                OR (type <> 'cash' AND requires_reference <> 1)
              )
        ")->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }

    public function cashVersusDrawer(mysqli $conn): int
    {
        $this->requireTables($conn, ['order_payments', 'drawer_movements', 'payment_methods', 'drawer_sessions', 'financial_certification_baselines']);
        if (!$this->columnExists($conn, 'drawer_movements', 'payment_id')) {
            throw new RuntimeException('DRAWER_MOVEMENT_PAYMENT_ID_REQUIRED');
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM order_payments op
            INNER JOIN payment_methods pm ON pm.code = op.payment_method AND pm.type = 'cash'
            LEFT JOIN drawer_movements dm
              ON dm.payment_id = op.id
             AND dm.movement_type IN ('sale_cash', 'payment_cash')
            LEFT JOIN drawer_sessions ds ON ds.id = dm.drawer_session_id
            WHERE op.created_at > COALESCE(
                    (SELECT MAX(cutoff_time) FROM financial_certification_baselines WHERE invalidated_at IS NULL),
                    '1970-01-01 00:00:00'
                  )
              AND (dm.id IS NULL OR dm.drawer_session_id IS NULL OR ds.id IS NULL)
        ";

        return $this->countRows($conn, $sql);
    }

    public function journalDebitVersusCredit(mysqli $conn): int
    {
        $this->requireTables($conn, ['journal_entries']);
        $sql = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT journal_id
                FROM journal_entries
                GROUP BY journal_id
                HAVING ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)
            ) unbalanced
        ";

        return $this->countRows($conn, $sql);
    }

    public function journalVersusSources(mysqli $conn): int
    {
        $this->requireTables($conn, ['journal_heads', 'ot_head', 'credit_notes', 'payment_refunds']);
        foreach (['source_type', 'source_id', 'posting_kind'] as $column) {
            if (!$this->columnExists($conn, 'journal_heads', $column)) {
                throw new RuntimeException('JOURNAL_SOURCE_COLUMNS_REQUIRED');
            }
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM journal_heads
            WHERE source_type IS NOT NULL
              AND source_id IS NOT NULL
              AND posting_kind IS NOT NULL
              AND (
                (source_type = 'invoice' AND NOT EXISTS (SELECT 1 FROM ot_head oh WHERE oh.id = source_id))
                OR (source_type = 'credit_note' AND NOT EXISTS (SELECT 1 FROM credit_notes cn WHERE cn.id = source_id))
                OR (source_type = 'payment_refund' AND NOT EXISTS (SELECT 1 FROM payment_refunds pr WHERE pr.id = source_id))
              )
        ";

        return $this->countRows($conn, $sql);
    }

    public function accountBalanceVersusJournals(mysqli $conn): int
    {
        $this->requireTables($conn, ['acc_head', 'journal_entries']);
        if (!$this->columnExists($conn, 'acc_head', 'balance')) {
            throw new RuntimeException('ACCOUNT_BALANCE_COLUMN_REQUIRED');
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM acc_head ah
            LEFT JOIN (
                SELECT account_id, ROUND(SUM(debit) - SUM(credit), 2) AS journal_balance
                FROM journal_entries
                GROUP BY account_id
            ) j ON j.account_id = ah.id
            WHERE ROUND(COALESCE(ah.balance, 0), 2) <> ROUND(COALESCE(j.journal_balance, 0), 2)
        ";

        return $this->countRows($conn, $sql);
    }

    public function inventoryVersusMovements(mysqli $conn): int
    {
        $this->requireTables($conn, ['inventory_item_balances', 'inventory_movements']);
        $sql = "
            SELECT COUNT(*) AS c
            FROM inventory_item_balances b
            LEFT JOIN (
                SELECT pos_tenant, pos_branch, store_id, item_id,
                       ROUND(SUM(qty_in - qty_out), 6) AS qty
                FROM inventory_movements
                GROUP BY pos_tenant, pos_branch, store_id, item_id
            ) m ON m.pos_tenant = b.pos_tenant
               AND m.pos_branch = b.pos_branch
               AND m.store_id = b.store_id
               AND m.item_id = b.item_id
            WHERE ROUND(COALESCE(b.qty_on_hand, 0), 6) <> ROUND(COALESCE(m.qty, 0), 6)
        ";

        return $this->countRows($conn, $sql);
    }

    public function vatVersusTaxSnapshots(mysqli $conn): int
    {
        $this->requireTables($conn, ['ot_head', 'fat_details']);
        if (!$this->columnExists($conn, 'fat_details', 'posted_tax')) {
            throw new RuntimeException('POSTED_TAX_SNAPSHOTS_REQUIRED');
        }
        $sql = "
            SELECT COUNT(*) AS c FROM (
                SELECT oh.id
                FROM ot_head oh
                LEFT JOIN fat_details fd
                  ON fd.fatid = oh.id AND COALESCE(fd.isdeleted, 0) = 0
                WHERE oh.pro_tybe = 9
                  AND COALESCE(oh.isdeleted, 0) = 0
                  AND COALESCE(oh.payment_status, '') = 'paid'
                GROUP BY oh.id, oh.fat_tax
                HAVING SUM(CASE WHEN fd.id IS NOT NULL AND fd.posted_tax IS NULL THEN 1 ELSE 0 END) > 0
                    OR ROUND(COALESCE(oh.fat_tax, 0), 2) <> ROUND(COALESCE(SUM(fd.posted_tax), 0), 2)
            ) finalized_tax_differences
        ";

        return $this->countRows($conn, $sql);
    }

    public function orphanedJournals(mysqli $conn): int
    {
        $this->requireTables($conn, ['journal_heads']);
        foreach (['source_type', 'posting_kind', 'idempotency_key'] as $column) {
            if (!$this->columnExists($conn, 'journal_heads', $column)) {
                throw new RuntimeException('JOURNAL_SOURCE_COLUMNS_REQUIRED');
            }
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM journal_heads
            WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
              AND (source_type IS NULL OR posting_kind IS NULL OR idempotency_key IS NULL)
        ";
        if (!$this->columnExists($conn, 'journal_heads', 'created_at')) {
            $sql = "
                SELECT COUNT(*) AS c
                FROM journal_heads
                WHERE source_type IS NULL OR posting_kind IS NULL OR idempotency_key IS NULL
            ";
        }

        return $this->countRows($conn, $sql);
    }

    public function pendingExternalRefunds(mysqli $conn): int
    {
        $this->requireTables($conn, ['payment_refunds']);
        if (!$this->columnExists($conn, 'payment_refunds', 'status')) {
            throw new RuntimeException('PAYMENT_REFUND_STATUS_REQUIRED');
        }
        $row = $conn->query("
            SELECT COUNT(*) AS c FROM payment_refunds WHERE status = 'pending_external'
        ")->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }

    private function countRows(mysqli $conn, string $sql): int
    {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException('RECONCILIATION_QUERY_FAILED:' . $conn->error);
        }
        $row = $result->fetch_assoc();
        if (!is_array($row) || !array_key_exists('c', $row)) {
            throw new RuntimeException('RECONCILIATION_COUNT_RESULT_INVALID');
        }

        return (int) $row['c'];
    }

    /** @param list<string> $tables */
    private function requireTables(mysqli $conn, array $tables): void
    {
        $missing = [];
        foreach ($tables as $table) {
            if (!$this->tableExists($conn, $table)) {
                $missing[] = $table;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('RECONCILIATION_SCHEMA_MISSING:' . implode(',', $missing));
        }
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");

        return $result !== false && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result !== false && $result->num_rows > 0;
    }
}
