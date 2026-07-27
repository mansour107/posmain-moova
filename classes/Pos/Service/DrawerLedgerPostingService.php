<?php

require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Sync/DocumentCounterService.php';

class DrawerLedgerPostingService
{
    private const SHIFT_EXPENSE_ACCOUNT_CODE = '511901';
    private const SHIFT_PAYIN_ACCOUNT_CODE = '121901';
    private const SHIFT_OVER_SHORT_ACCOUNT_CODE = '511902';

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
        $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        $amount = $this->positiveMoney($amount);
        if (!Money::from($amount)->isPositive()) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $safeAccountId = $this->ensureShiftSafeDropAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-SAFE-DROP', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 2, $amount, $safeAccountId, $fundAccountId, $info, $userId, $drawerSessionId);
    }

    public function postPayOut(
        mysqli $conn,
        $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        $amount = $this->positiveMoney($amount);
        if (!Money::from($amount)->isPositive()) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $expenseAccountId = $this->ensureShiftExpenseAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-PAYOUT', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 2, $amount, $expenseAccountId, $fundAccountId, $info, $userId, $drawerSessionId);
    }

    public function postPayIn(
        mysqli $conn,
        $amount,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        $amount = $this->positiveMoney($amount);
        if (!Money::from($amount)->isPositive()) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }

        $sourceAccountId = $this->ensureShiftPayInAccount($conn);
        $info = $this->buildInfo('POS-SHIFT-PAYIN', $drawerSessionId, $reason);

        return $this->postVoucher($conn, 1, $amount, $fundAccountId, $sourceAccountId, $info, $userId, $drawerSessionId);
    }

    /**
     * Book an accepted drawer count variance against the fund account.
     *
     * Signed amount, positive = over (counted > expected):
     *   over  → debit fund, credit over/short account (cash gain)
     *   short → debit over/short account, credit fund (cash loss)
     * The fund's ledger balance is trued to the physically counted cash;
     * the over/short account carries the gain/loss for reporting.
     */
    public function postCashOverShort(
        mysqli $conn,
        $signedVariance,
        string $reason,
        int $userId,
        int $fundAccountId,
        int $drawerSessionId
    ): int {
        if (!$this->canPost($conn)) {
            throw new RuntimeException('LEDGER_SUBSYSTEM_UNAVAILABLE');
        }
        if ($fundAccountId < 1) {
            throw new RuntimeException('FUND_ACCOUNT_REQUIRED');
        }
        $variance = Money::from($signedVariance, true);
        $isOver = $variance->isPositive();
        $amount = $isOver
            ? $variance->toString()
            : ltrim($variance->toString(), '-');
        if (!Money::from($amount)->isPositive()) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED');
        }

        $overShortAccountId = $this->ensureShiftOverShortAccount($conn);
        $info = $this->buildInfo(
            $isOver ? 'POS-SHIFT-CASH-OVER' : 'POS-SHIFT-CASH-SHORT',
            $drawerSessionId,
            $reason
        );

        if ($isOver) {
            return $this->postVoucher($conn, 1, $amount, $fundAccountId, $overShortAccountId, $info, $userId, $drawerSessionId);
        }

        return $this->postVoucher($conn, 2, $amount, $overShortAccountId, $fundAccountId, $info, $userId, $drawerSessionId);
    }

    private function postVoucher(
        mysqli $conn,
        int $proTybe,
        string $amount,
        int $debitAccountId,
        int $creditAccountId,
        string $info,
        int $userId,
        int $drawerSessionId
    ): int {
        $scope = $this->drawerScope($conn, $drawerSessionId);
        $voucherNumber = $this->nextVoucherNumber($conn, $proTybe, $scope['tenant'], $scope['branch']);
        $today = date('Y-m-d');
        $amountFormatted = Money::from($amount)->toString();
        $userValue = (string) $userId;

        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_id', 'i', $voucherNumber);
        if ($this->tableColumnExists($conn, 'ot_head', 'branch_id')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'branch_id', 'i', max(1, $scope['branch']));
        }
        if ($this->tableColumnExists($conn, 'ot_head', 'tenant')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'tenant', 'i', $scope['tenant']);
        }
        if ($this->tableColumnExists($conn, 'ot_head', 'branch')) {
            $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'branch', 'i', $scope['branch']);
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
        $this->appendVoucherColumn($columns, $placeholders, $types, $values, 'pro_value', 's', $amountFormatted);
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

        $journalNumber = $this->nextJournalNumber($conn, $scope['tenant'], $scope['branch']);
        $journalDetails = 'سند شيفت POS — ' . $info;
        $postedAmount = $amountFormatted;
        $journalHeadId = JournalPostingService::postBalancedHead(
            $conn,
            (string) $journalNumber,
            $postedAmount,
            $today,
            $journalDetails,
            $userId,
            [
                ['account_id' => $debitAccountId, 'debit' => $postedAmount, 'credit' => '0.00', 'tybe' => 0, 'op2' => $otHeadId],
                ['account_id' => $creditAccountId, 'debit' => '0.00', 'credit' => $postedAmount, 'tybe' => 1, 'op2' => $otHeadId],
            ],
            [
                'op_id' => $otHeadId,
                'op2' => $otHeadId,
                'source_type' => 'drawer_voucher',
                'source_id' => $otHeadId,
                'posting_kind' => 'drawer_ledger',
                'idempotency_key' => 'drawer-voucher:' . $otHeadId,
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
            ]
        );

        if ($this->tableExists($conn, 'process')) {
            $conn->query("INSERT INTO `process` (`type`) VALUES ('add voucher')");
        }

        return $otHeadId;
    }

    private function positiveMoney($amount): string
    {
        try {
            return Money::from($amount)->toString();
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('LEDGER_AMOUNT_REQUIRED', 0, $exception);
        }
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

    private function ensureShiftOverShortAccount(mysqli $conn): int
    {
        require_once dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';

        return posmain_insert_acc_head_if_missing($conn, [
            'code' => self::SHIFT_OVER_SHORT_ACCOUNT_CODE,
            'aname' => 'فروقات عد الدرج (عجز/زيادة)',
            'parent_id' => 0,
            'is_basic' => 0,
            'is_fund' => 0,
        ]);
    }

    private function nextVoucherNumber(mysqli $conn, int $proTybe, int $tenant, int $branch): int
    {
        $scopeSql = '';
        if ($this->tableColumnExists($conn, 'ot_head', 'tenant') && $this->tableColumnExists($conn, 'ot_head', 'branch')) {
            $scopeSql = ' AND COALESCE(tenant, 0) = ? AND COALESCE(branch, 0) = ?';
        }
        $stmt = $conn->prepare('SELECT COALESCE(MAX(pro_id), 0) AS current_id FROM ot_head WHERE pro_tybe = ?' . $scopeSql);
        if ($scopeSql !== '') {
            $stmt->bind_param('iii', $proTybe, $tenant, $branch);
        } else {
            $stmt->bind_param('i', $proTybe);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $counter = new DocumentCounterService();
        $counter->ensureCounterRow($conn, $tenant, $branch, 'pro_id', 'pro_tybe:' . $proTybe, (int) ($row['current_id'] ?? 0));

        return $counter->nextProId($conn, $proTybe, $tenant, $branch);
    }

    private function nextJournalNumber(mysqli $conn, int $tenant, int $branch): int
    {
        $scopeSql = '';
        if ($this->tableColumnExists($conn, 'journal_heads', 'tenant') && $this->tableColumnExists($conn, 'journal_heads', 'branch')) {
            $scopeSql = ' WHERE COALESCE(tenant, 0) = ' . $tenant . ' AND COALESCE(branch, 0) = ' . $branch;
        }
        $result = $conn->query('SELECT COALESCE(MAX(journal_id), 0) AS current_id FROM journal_heads' . $scopeSql);
        $row = $result ? $result->fetch_assoc() : [];
        $counter = new DocumentCounterService();
        $counter->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:default', (int) ($row['current_id'] ?? 0));

        return $counter->nextJournalId($conn, $tenant, $branch);
    }

    /** @return array{tenant:int,branch:int} */
    private function drawerScope(mysqli $conn, int $drawerSessionId): array
    {
        if ($drawerSessionId < 1 || !$this->tableExists($conn, 'drawer_sessions')) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }
        $stmt = $conn->prepare('SELECT tenant, branch FROM drawer_sessions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $drawerSessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        return [
            'tenant' => max(0, (int) ($row['tenant'] ?? 0)),
            'branch' => max(0, (int) ($row['branch'] ?? 0)),
        ];
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
