<?php

class DrawerLedgerPostingService
{
    private const SHIFT_EXPENSE_ACCOUNT_CODE = '511901';
    private const SHIFT_PAYIN_ACCOUNT_CODE = '121901';

    public function canPost(mysqli $conn): bool
    {
        foreach (['ot_head', 'journal_heads', 'journal_entries', 'acc_head'] as $table) {
            $result = $conn->query("SHOW TABLES LIKE '{$table}'");
            if (!$result instanceof mysqli_result || $result->num_rows < 1) {
                return false;
            }
        }

        return true;
    }

    public function resolveFundAccountId(mysqli $conn, array $drawerSession): int
    {
        $fundId = (int) ($drawerSession['fund_account_id'] ?? 0);
        if ($fundId > 0 && $this->accountExists($conn, $fundId)) {
            return $fundId;
        }

        require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';
        posmain_ensure_pos_default_accounts($conn);
        $settings = posmain_load_pos_settings_row($conn);
        $defaults = posmain_resolve_pos_defaults($conn, $settings);

        return (int) ($defaults['fund_id'] ?? 0);
    }

    private const SHIFT_SAFE_DROP_ACCOUNT_CODE = '101902';

    public function postSafeDrop(
        mysqli $conn,
        float $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        if ($amount <= 0) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $safeAccountId = $this->ensureShiftSafeDropAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-SAFE-DROP', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 2, $amount, $safeAccountId, $fundAccountId, $info, $userId);
    }

    public function postPayOut(
        mysqli $conn,
        float $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        if ($amount <= 0) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $expenseAccountId = $this->ensureShiftExpenseAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-PAYOUT', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 2, $amount, $expenseAccountId, $fundAccountId, $info, $userId);
    }

    public function postPayIn(
        mysqli $conn,
        float $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        if ($amount <= 0) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $sourceAccountId = $this->ensureShiftPayInAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-PAYIN', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 1, $amount, $fundAccountId, $sourceAccountId, $info, $userId);
    }

    private function postVoucher(
        mysqli $conn,
        int $proTybe,
        float $amount,
        int $debitAccountId,
        int $creditAccountId,
        string $info,
        int $userId
    ): int {
        $voucherNumber = $this->nextVoucherNumber($conn, $proTybe);
        $today = date('Y-m-d');
        $amountFormatted = number_format($amount, 3, '.', '');
        $userValue = (string) $userId;

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_id', 'i', $voucherNumber);
        if ($this->tableColumnExists($conn, 'ot_head', 'branch_id')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'branch_id', 'i', 1);
        }
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_tybe', 'i', $proTybe);
        if ($this->tableColumnExists($conn, 'ot_head', 'is_finance')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'is_finance', 'i', 1);
        }
        if ($this->tableColumnExists($conn, 'ot_head', 'is_journal')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'is_journal', 'i', 1);
        }
        if ($this->tableColumnExists($conn, 'ot_head', 'journal_tybe')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'journal_tybe', 'i', $proTybe);
        }
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'info', 's', $info);
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_date', 's', $today);
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_num', 'i', $voucherNumber);
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'acc1', 'i', $debitAccountId);
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'acc2', 'i', $creditAccountId);
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_value', 'd', $amount);
        if ($this->tableColumnExists($conn, 'ot_head', 'cost_center')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'cost_center', 'i', 0);
        }
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'user', 's', $userValue);
        if ($this->tableColumnExists($conn, 'ot_head', 'isdeleted')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'isdeleted', 'i', 0);
        }

        $otSql = 'INSERT INTO ot_head (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $otStmt = $conn->prepare($otSql);
        $otStmt->bind_param($types, ...$values);
        $otStmt->execute();
        $otHeadId = (int) $conn->insert_id;
        $otStmt->close();

        $journalNumber = $this->nextJournalNumber($conn);
        $journalDetails = 'سند شيفت POS — ' . $info;
        $journalStmt = $conn->prepare('
            INSERT INTO journal_heads (journal_id, total, jdate, details, op2, user)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $journalStmt->bind_param('idssis', $journalNumber, $amount, $today, $journalDetails, $otHeadId, $userId);
        $journalStmt->execute();
        $journalHeadId = (int) $conn->insert_id;
        $journalStmt->close();

        $debitStmt = $conn->prepare('
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, ?, 0, 0, ?)
        ');
        $debitStmt->bind_param('iidi', $journalHeadId, $debitAccountId, $amount, $otHeadId);
        $debitStmt->execute();
        $debitStmt->close();

        $creditStmt = $conn->prepare('
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, 0, ?, 1, ?)
        ');
        $creditStmt->bind_param('iidi', $journalHeadId, $creditAccountId, $amount, $otHeadId);
        $creditStmt->execute();
        $creditStmt->close();

        if ($this->tableExists($conn, 'process')) {
            $conn->query("INSERT INTO `process` (`type`) VALUES ('add voucher')");
        }

        return $otHeadId;
    }

    /**
     * @param list<string> $columns
     * @param list<string> $placeholders
     * @param list<mixed> $values
     */
    private function appendVoucherColumn(
        array &$columns,
        array &$placeholders,
        string &$types,
        array &$values,
        string $column,
        string $type,
        $value
    ): void {
        $columns[] = $column;
        $placeholders[] = '?';
        $types .= $type;
        $values[] = $value;
    }

    private function ensureShiftExpenseAccount(mysqli $conn): int
    {
        require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';

        return posmain_insert_acc_head_if_missing($conn, [
            'code' => self::SHIFT_EXPENSE_ACCOUNT_CODE,
            'aname' => 'مصروفات الشيفت',
            'parent_id' => 0,
            'is_basic' => 0,
            'is_fund' => 0,
        ]);
    }

    private function ensureShiftPayInAccount(mysqli $conn): int
    {
        require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';

        return posmain_insert_acc_head_if_missing($conn, [
            'code' => self::SHIFT_PAYIN_ACCOUNT_CODE,
            'aname' => 'إيداعات الدرج',
            'parent_id' => 0,
            'is_basic' => 0,
            'is_fund' => 0,
        ]);
    }

    private function ensureShiftSafeDropAccount(mysqli $conn): int
    {
        require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';

        return posmain_insert_acc_head_if_missing($conn, [
            'code' => self::SHIFT_SAFE_DROP_ACCOUNT_CODE,
            'aname' => 'خزنة الدرج',
            'parent_id' => 0,
            'is_basic' => 0,
            'is_fund' => 0,
        ]);
    }

    private function nextVoucherNumber(mysqli $conn, int $proTybe): int
    {
        $stmt = $conn->prepare('SELECT COALESCE(MAX(pro_id), 0) + 1 AS next_id FROM ot_head WHERE pro_tybe = ?');
        $stmt->bind_param('i', $proTybe);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['next_id'] ?? 1);
    }

    private function nextJournalNumber(mysqli $conn): int
    {
        $result = $conn->query('SELECT COALESCE(MAX(journal_id), 0) + 1 AS next_id FROM journal_heads');
        $row = $result ? $result->fetch_assoc() : null;

        return (int) ($row['next_id'] ?? 1);
    }

    private function buildInfo(string $prefix, int $drawerSessionId, string $reason): string
    {
        $info = trim($prefix . ' #' . $drawerSessionId);
        $reason = trim($reason);
        if ($reason !== '') {
            $info .= ' — ' . $reason;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($info, 0, 200);
        }

        return substr($info, 0, 200);
    }

    private function accountExists(mysqli $conn, int $accountId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM acc_head WHERE id = ? AND isdeleted = 0 LIMIT 1');
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @var array<string, bool> */
    private static array $tableColumnCache = [];

    private function tableColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = preg_replace('/[^a-z0-9_]/i', '', $table);
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return false;
        }

        $key = spl_object_hash($conn) . ':' . $safeTable . ':' . $safeColumn;
        if (array_key_exists($key, self::$tableColumnCache)) {
            return self::$tableColumnCache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        self::$tableColumnCache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;

        return self::$tableColumnCache[$key];
    }
}
