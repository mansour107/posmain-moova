<?php

require_once __DIR__ . '/Money.php';

/**
 * Posted-source financial reports for certification.
 * Sales/tenders/GL derive from finalized invoices, credit notes, payments, and journals.
 * VAT report is available but inactive while tax remains disabled.
 */
final class FinancialPostedReportsService
{
    /**
     * @return array{gross:string,discount:string,net:string,tax:string,refunded:string,invoice_count:int,credit_note_count:int,rows:array<int,array>}
     */
    public function salesFromFinalizedDocuments(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        $sales = ['gross' => Money::zero(), 'discount' => Money::zero(), 'net' => Money::zero(), 'tax' => Money::zero(), 'refunded' => Money::zero()];
        $rows = [];
        $invoiceCount = 0;
        if ($this->tableExists($conn, 'ot_head')) {
            $hasPosted = $this->columnExists($conn, 'fat_details', 'posted_net');
            $stmt = $conn->prepare("
                SELECT oh.id, oh.fat_net, oh.fat_tax, oh.pro_date,
                       " . ($hasPosted
                            ? "COALESCE(SUM(fd.posted_gross), 0) AS gross,
                               COALESCE(SUM(fd.posted_line_discount + fd.posted_order_discount), 0) AS discount,
                               COALESCE(SUM(fd.posted_net), 0) AS net,
                               COALESCE(SUM(fd.posted_tax), 0) AS tax"
                            : "COALESCE(oh.fat_net, 0) AS gross,
                               0 AS discount,
                               COALESCE(oh.fat_net, 0) AS net,
                               COALESCE(oh.fat_tax, 0) AS tax") . "
                FROM ot_head oh
                LEFT JOIN fat_details fd ON fd.fatid = oh.id AND COALESCE(fd.isdeleted, 0) = 0
                WHERE oh.pro_tybe = 9
                  AND COALESCE(oh.isdeleted, 0) = 0
                  AND COALESCE(oh.payment_status, '') IN ('paid', 'partial', 'unpaid', 'open', '')
                  AND oh.pro_date BETWEEN ? AND ?
                GROUP BY oh.id, oh.fat_net, oh.fat_tax, oh.pro_date
                ORDER BY oh.id ASC
            ");
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $invoiceCount++;
                $net = Money::from((string) $row['net'])->toString();
                $tax = Money::from((string) $row['tax'])->toString();
                $gross = Money::from((string) $row['gross'])->toString();
                $discount = Money::from((string) $row['discount'])->toString();
                $sales['net'] = $sales['net']->add(Money::from($net));
                $sales['tax'] = $sales['tax']->add(Money::from($tax));
                $sales['gross'] = $sales['gross']->add(Money::from($gross));
                $sales['discount'] = $sales['discount']->add(Money::from($discount));
                $rows[] = [
                    'document_type' => 'invoice',
                    'document_id' => (int) $row['id'],
                    'date' => (string) $row['pro_date'],
                    'gross' => $gross,
                    'discount' => $discount,
                    'tax' => $tax,
                    'net' => $net,
                    'drilldown_url' => 'check_orders.php?id=' . (int) $row['id'],
                ];
            }
            $stmt->close();
        }

        $creditNoteCount = 0;
        if ($this->tableExists($conn, 'credit_notes')) {
            $stmt = $conn->prepare("
                SELECT id, total_amount, created_at
                FROM credit_notes
                WHERE status = 'posted'
                  AND DATE(created_at) BETWEEN ? AND ?
                ORDER BY id ASC
            ");
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $creditNoteCount++;
                $amount = Money::from((string) $row['total_amount'])->toString();
                $sales['refunded'] = $sales['refunded']->add(Money::from($amount));
                $sales['net'] = $sales['net']->subtract(Money::from($amount));
                $rows[] = [
                    'document_type' => 'credit_note',
                    'document_id' => (int) $row['id'],
                    'date' => substr((string) $row['created_at'], 0, 10),
                    'gross' => '0.00',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'net' => '-' . $amount,
                    'drilldown_url' => 'credit_notes.php?id=' . (int) $row['id'],
                ];
            }
            $stmt->close();
        }

        return [
            'gross' => $sales['gross']->toString(),
            'discount' => $sales['discount']->toString(),
            'net' => $sales['net']->toString(),
            'tax' => $sales['tax']->toString(),
            'refunded' => $sales['refunded']->toString(),
            'invoice_count' => $invoiceCount,
            'credit_note_count' => $creditNoteCount,
            'rows' => $rows,
        ];
    }

    /**
     * Tender report = posted payments − posted/settled refunds.
     *
     * @return array{rows:array<int,array{method:string,paid:string,refunded:string,net:string}>,total_paid:string,total_refunded:string,total_net:string}
     */
    public function tenderReport(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        $byMethod = [];
        if ($this->tableExists($conn, 'order_payments')) {
            $hasCreated = $this->columnExists($conn, 'order_payments', 'created_at');
            $sql = $hasCreated
                ? "SELECT payment_method, COALESCE(SUM(amount),0) AS paid
                   FROM order_payments
                   WHERE DATE(created_at) BETWEEN ? AND ?
                   GROUP BY payment_method"
                : "SELECT op.payment_method, COALESCE(SUM(op.amount),0) AS paid
                   FROM order_payments op
                   INNER JOIN ot_head oh ON oh.id = op.order_id
                   WHERE oh.pro_date BETWEEN ? AND ?
                   GROUP BY op.payment_method";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $method = (string) $row['payment_method'];
                $byMethod[$method] = [
                    'method' => $method,
                    'paid' => Money::from((string) $row['paid'])->toString(),
                    'refunded' => '0.00',
                ];
            }
            $stmt->close();
        }

        if ($this->tableExists($conn, 'payment_refunds')) {
            $hasStatus = $this->columnExists($conn, 'payment_refunds', 'status');
            $statusFilter = $hasStatus ? "AND status IN ('posted','settled')" : '';
            $stmt = $conn->prepare("
                SELECT pm.code AS payment_method, COALESCE(SUM(pr.amount),0) AS refunded
                FROM payment_refunds pr
                LEFT JOIN payment_methods pm ON pm.id = pr.payment_method_id
                WHERE DATE(pr.created_at) BETWEEN ? AND ?
                {$statusFilter}
                GROUP BY pm.code
            ");
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $method = (string) ($row['payment_method'] ?: 'unknown');
                if (!isset($byMethod[$method])) {
                    $byMethod[$method] = ['method' => $method, 'paid' => '0.00', 'refunded' => '0.00'];
                }
                $byMethod[$method]['refunded'] = Money::from((string) $row['refunded'])->toString();
            }
            $stmt->close();
        }

        $rows = [];
        $totalPaid = Money::zero();
        $totalRefunded = Money::zero();
        foreach ($byMethod as $row) {
            $paid = Money::from($row['paid']);
            $refunded = Money::from($row['refunded']);
            $net = $paid->subtract($refunded)->toString();
            $rows[] = [
                'method' => $row['method'],
                'paid' => $paid->toString(),
                'refunded' => $refunded->toString(),
                'net' => $net,
                'drilldown_url' => 'cash_flow_report.php?method=' . rawurlencode($row['method']),
            ];
            $totalPaid = $totalPaid->add($paid);
            $totalRefunded = $totalRefunded->add($refunded);
        }

        return [
            'rows' => array_values($rows),
            'total_paid' => $totalPaid->toString(),
            'total_refunded' => $totalRefunded->toString(),
            'total_net' => $totalPaid->subtract($totalRefunded)->toString(),
        ];
    }

    /**
     * General ledger from posted journal entries.
     *
     * @return array{rows:array<int,array>,total_debit:string,total_credit:string,balanced:bool}
     */
    public function generalLedger(mysqli $conn, string $dateFrom, string $dateTo): array
    {
        if (!$this->tableExists($conn, 'journal_entries') || !$this->tableExists($conn, 'journal_heads')) {
            return ['rows' => [], 'total_debit' => '0.00', 'total_credit' => '0.00', 'balanced' => true];
        }
        $stmt = $conn->prepare("
            SELECT je.account_id,
                   COALESCE(ah.name, ah.aname, CONCAT('Account ', je.account_id)) AS account_name,
                   COALESCE(SUM(je.debit), 0) AS debit,
                   COALESCE(SUM(je.credit), 0) AS credit
            FROM journal_entries je
            INNER JOIN journal_heads jh ON jh.id = je.journal_id
            LEFT JOIN acc_head ah ON ah.id = je.account_id
            WHERE jh.jdate BETWEEN ? AND ?
            GROUP BY je.account_id, account_name
            ORDER BY je.account_id ASC
        ");
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();
        while ($row = $result->fetch_assoc()) {
            $debit = Money::from((string) $row['debit'])->toString();
            $credit = Money::from((string) $row['credit'])->toString();
            $rows[] = [
                'account_id' => (int) $row['account_id'],
                'account_name' => (string) $row['account_name'],
                'debit' => $debit,
                'credit' => $credit,
                'drilldown_url' => 'acc_report.php?account_id=' . (int) $row['account_id'],
            ];
            $totalDebit = $totalDebit->add(Money::from($debit));
            $totalCredit = $totalCredit->add(Money::from($credit));
        }
        $stmt->close();

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit->toString(),
            'total_credit' => $totalCredit->toString(),
            'balanced' => $totalDebit->compare($totalCredit) === 0,
        ];
    }

    /**
     * Outstanding customer liability from pending_external refunds.
     */
    public function pendingExternalRefundLiability(mysqli $conn): string
    {
        if (!$this->tableExists($conn, 'payment_refunds') || !$this->columnExists($conn, 'payment_refunds', 'status')) {
            return '0.00';
        }
        $row = $conn->query("
            SELECT COALESCE(SUM(amount), 0) AS pending
            FROM payment_refunds
            WHERE status = 'pending_external'
        ")->fetch_assoc();

        return Money::from((string) ($row['pending'] ?? '0'))->toString();
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
