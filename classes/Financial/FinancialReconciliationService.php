<?php

require_once __DIR__ . '/Money.php';

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
        $differences = [
            'invoice_vs_lines' => $this->invoiceVersusLines($conn),
            'invoice_vs_payments_refunds' => $this->invoiceVersusPaymentsAndRefunds($conn),
            'payment_methods_vs_accounts' => $this->paymentMethodsVersusAccounts($conn),
            'cash_vs_drawer' => $this->cashVersusDrawer($conn),
            'journal_debit_vs_credit' => $this->journalDebitVersusCredit($conn),
            'journal_vs_sources' => $this->journalVersusSources($conn),
            'account_balance_vs_journals' => $this->accountBalanceVersusJournals($conn),
            'inventory_vs_movements' => $this->inventoryVersusMovements($conn),
            'vat_vs_tax_snapshots' => $this->vatVersusTaxSnapshots($conn),
            'orphaned_journals' => $this->orphanedJournals($conn),
        ];
        $liabilities = [
            'pending_external_refunds' => $this->pendingExternalRefunds($conn),
        ];

        $blockers = [];
        foreach ($differences as $name => $count) {
            if ($count !== 0) {
                $blockers[] = $name;
            }
        }

        return [
            'ok' => $blockers === [],
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'differences' => $differences,
            'liabilities' => $liabilities,
            'blockers' => $blockers,
            'certified_mode' => true,
        ];
    }

    public function invoiceVersusLines(mysqli $conn): int
    {
        if (!$this->tableExists($conn, 'ot_head') || !$this->tableExists($conn, 'fat_details')) {
            return 0;
        }
        $hasSnapshots = $this->columnExists($conn, 'fat_details', 'posted_net');
        $lineExpr = $hasSnapshots
            ? 'COALESCE(SUM(fd.posted_net), 0)'
            : 'COALESCE(SUM(fd.det_value), 0)';
        $sql = "
            SELECT COUNT(*) AS c
            FROM ot_head oh
            LEFT JOIN fat_details fd
              ON fd.fatid = oh.id AND COALESCE(fd.isdeleted, 0) = 0
            WHERE oh.pro_tybe = 9
              AND COALESCE(oh.isdeleted, 0) = 0
              AND COALESCE(oh.payment_status, '') IN ('paid', 'partial', 'unpaid', 'open', '')
            GROUP BY oh.id, oh.fat_net
            HAVING ROUND(COALESCE(oh.fat_net, 0), 2) <> ROUND({$lineExpr}, 2)
        ";
        return $this->countRows($conn, $sql);
    }

    public function invoiceVersusPaymentsAndRefunds(mysqli $conn): int
    {
        if (!$this->tableExists($conn, 'ot_head') || !$this->tableExists($conn, 'order_payments')) {
            return 0;
        }
        $hasRefunds = $this->tableExists($conn, 'payment_refunds');
        $refundExpr = $hasRefunds
            ? '(SELECT COALESCE(SUM(pr.amount), 0) FROM payment_refunds pr WHERE pr.original_order_id = oh.id AND COALESCE(pr.status, \"posted\") = \"posted\")'
            : '0';
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
        if (!$this->tableExists($conn, 'payment_methods')) {
            return 0;
        }
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
        if (!$this->tableExists($conn, 'order_payments') || !$this->tableExists($conn, 'drawer_movements')) {
            return 0;
        }
        if (!$this->tableExists($conn, 'payment_methods')) {
            return 0;
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM order_payments op
            INNER JOIN payment_methods pm ON pm.code = op.payment_method AND pm.type = 'cash'
            LEFT JOIN drawer_movements dm
              ON dm.ref_payment_id = op.id
             AND dm.movement_type IN ('sale_cash', 'payment_cash')
            WHERE dm.id IS NULL
        ";
        if (!$this->columnExists($conn, 'drawer_movements', 'ref_payment_id')) {
            return 0;
        }

        return $this->countRows($conn, $sql);
    }

    public function journalDebitVersusCredit(mysqli $conn): int
    {
        if (!$this->tableExists($conn, 'journal_entries')) {
            return 0;
        }
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
        if (!$this->tableExists($conn, 'journal_heads') || !$this->columnExists($conn, 'journal_heads', 'source_type')) {
            return 0;
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
        if (!$this->tableExists($conn, 'acc_head') || !$this->tableExists($conn, 'journal_entries')) {
            return 0;
        }
        if (!$this->columnExists($conn, 'acc_head', 'balance')) {
            return 0;
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
        if (!$this->tableExists($conn, 'inventory_item_balances') || !$this->tableExists($conn, 'inventory_movements')) {
            return 0;
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM inventory_item_balances b
            LEFT JOIN (
                SELECT item_id, store_id,
                       ROUND(SUM(qty_in - qty_out), 6) AS qty
                FROM inventory_movements
                GROUP BY item_id, store_id
            ) m ON m.item_id = b.item_id AND m.store_id = b.store_id
            WHERE ROUND(COALESCE(b.on_hand_qty, 0), 6) <> ROUND(COALESCE(m.qty, 0), 6)
        ";

        return $this->countRows($conn, $sql);
    }

    public function vatVersusTaxSnapshots(mysqli $conn): int
    {
        if (!$this->tableExists($conn, 'ot_head') || !$this->columnExists($conn, 'fat_details', 'posted_tax')) {
            return 0;
        }
        $sql = "
            SELECT COUNT(*) AS c
            FROM ot_head oh
            LEFT JOIN fat_details fd
              ON fd.fatid = oh.id AND COALESCE(fd.isdeleted, 0) = 0
            WHERE oh.pro_tybe = 9
              AND COALESCE(oh.isdeleted, 0) = 0
            GROUP BY oh.id, oh.fat_tax
            HAVING ROUND(COALESCE(oh.fat_tax, 0), 2) <> ROUND(COALESCE(SUM(fd.posted_tax), 0), 2)
        ";

        return $this->countRows($conn, $sql);
    }

    public function orphanedJournals(mysqli $conn): int
    {
        if (!$this->tableExists($conn, 'journal_heads') || !$this->columnExists($conn, 'journal_heads', 'source_type')) {
            return 0;
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
        if (!$this->tableExists($conn, 'payment_refunds') || !$this->columnExists($conn, 'payment_refunds', 'status')) {
            return 0;
        }
        $row = $conn->query("
            SELECT COUNT(*) AS c FROM payment_refunds WHERE status = 'pending_external'
        ")->fetch_assoc();

        return (int) ($row['c'] ?? 0);
    }

    private function countRows(mysqli $conn, string $sql): int
    {
        try {
            $result = $conn->query($sql);
            if ($result === false) {
                return 1;
            }
            $count = 0;
            while ($result->fetch_assoc()) {
                $count++;
            }

            return $count;
        } catch (Throwable $e) {
            return 1;
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
