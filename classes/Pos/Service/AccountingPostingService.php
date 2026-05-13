<?php

require_once __DIR__ . '/../../Sync/DocumentCounterService.php';

class AccountingPostingService
{
    private $counterService;

    public function __construct(?DocumentCounterService $counterService = null)
    {
        $this->counterService = $counterService ?: new DocumentCounterService();
    }

    public function postTablePaymentReceipt(mysqli $conn, array $request, array $context = []): array
    {
        $orderId = $this->requiredPositiveInt($request, 'order_id');
        $amount = $this->requiredPositiveAmount($request, ['amount', 'amount_paid', 'paid']);
        $safeAccountId = $this->requiredPositiveInt($request, 'safe_account_id');
        $customerAccountId = (int) ($request['customer_account_id'] ?? $request['acc2_id'] ?? 0);
        $employeeId = (int) ($request['emp_id'] ?? $context['emp_id'] ?? 0);
        $userId = $this->contextUserId($request, $context);
        $tenant = (int) ($context['tenant'] ?? $request['tenant'] ?? 0);
        $branch = (int) ($context['branch'] ?? $request['branch'] ?? 0);
        $date = $this->paymentDate($request);
        $tableName = trim((string) ($request['table_name'] ?? ''));

        $receiptId = $this->insertReceiptHeader(
            $conn,
            $orderId,
            $amount,
            $safeAccountId,
            $customerAccountId,
            $employeeId,
            $userId,
            $date,
            $tableName
        );
        $journalId = $this->nextJournalId($conn, $tenant, $branch);
        $journalHeadId = $this->insertJournalHead($conn, $journalId, $receiptId, $orderId, $amount, $date, $tableName, $userId);
        $entryCount = $this->insertPaymentEntries($conn, $journalHeadId, $orderId, $amount, $safeAccountId, $customerAccountId);

        return [
            'receipt_id' => $receiptId,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
            'entry_count' => $entryCount,
            'amount' => $amount,
        ];
    }

    private function insertReceiptHeader(
        mysqli $conn,
        int $orderId,
        float $amount,
        int $safeAccountId,
        int $customerAccountId,
        int $employeeId,
        int $userId,
        string $date,
        string $tableName
    ): int {
        $infoText = 'سند قبض - سداد طاولة: ' . $tableName . ' - فاتورة رقم ' . $orderId;
        $stmt = $conn->prepare("
            INSERT INTO ot_head (
                pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, fat_net, cost_center, profit, user, op2
            ) VALUES (1, 1, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
        ");
        $stmt->bind_param(
            'ssiiiddii',
            $infoText,
            $date,
            $employeeId,
            $safeAccountId,
            $customerAccountId,
            $amount,
            $amount,
            $userId,
            $orderId
        );
        $stmt->execute();
        $receiptId = (int) $conn->insert_id;
        $stmt->close();

        return $receiptId;
    }

    private function insertJournalHead(mysqli $conn, int $journalId, int $receiptId, int $orderId, float $amount, string $date, string $tableName, int $userId): int
    {
        $details = 'سند قبض - سداد طاولة ' . $tableName;
        $stmt = $conn->prepare("
            INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iidssii', $journalId, $receiptId, $amount, $date, $details, $userId, $orderId);
        $stmt->execute();
        $journalHeadId = (int) $conn->insert_id;
        $stmt->close();

        return $journalHeadId;
    }

    private function insertPaymentEntries(mysqli $conn, int $journalHeadId, int $orderId, float $amount, int $safeAccountId, int $customerAccountId): int
    {
        $stmt = $conn->prepare("
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, ?, 0, 0, ?)
        ");
        $stmt->bind_param('iidi', $journalHeadId, $safeAccountId, $amount, $orderId);
        $stmt->execute();
        $stmt->close();
        $entryCount = 1;

        if ($customerAccountId > 0) {
            $stmt = $conn->prepare("
                INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
                VALUES (?, ?, 0, ?, 1, ?)
            ");
            $stmt->bind_param('iidi', $journalHeadId, $customerAccountId, $amount, $orderId);
            $stmt->execute();
            $stmt->close();
            $entryCount++;
        }

        return $entryCount;
    }

    private function nextJournalId(mysqli $conn, int $tenant, int $branch): int
    {
        $seed = $this->maxJournalId($conn, $tenant, $branch);
        $this->counterService->ensureCounterRow($conn, $tenant, $branch, 'journal_id', 'journal:default', $seed);

        return $this->counterService->nextJournalId($conn, $tenant, $branch);
    }

    private function maxJournalId(mysqli $conn, int $tenant, int $branch): int
    {
        if ($this->columnExists($conn, 'journal_heads', 'tenant') && $this->columnExists($conn, 'journal_heads', 'branch')) {
            $stmt = $conn->prepare("
                SELECT COALESCE(MAX(journal_id), 0) AS max_id
                FROM journal_heads
                WHERE COALESCE(tenant, 0) = ?
                  AND COALESCE(branch, 0) = ?
            ");
            $stmt->bind_param('ii', $tenant, $branch);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return (int) ($row['max_id'] ?? 0);
        }

        $row = $conn->query("SELECT COALESCE(MAX(journal_id), 0) AS max_id FROM journal_heads")->fetch_assoc();

        return (int) ($row['max_id'] ?? 0);
    }

    private function paymentDate(array $request): string
    {
        $date = trim((string) ($request['payment_date'] ?? $request['date'] ?? ''));

        return $date !== '' ? $date : date('Y-m-d');
    }

    private function requiredPositiveInt(array $request, string $key): int
    {
        if (!array_key_exists($key, $request)) {
            throw new InvalidArgumentException(strtoupper($key) . '_REQUIRED');
        }

        $value = (int) $request[$key];
        if ($value < 1) {
            throw new InvalidArgumentException(strtoupper($key) . '_REQUIRED');
        }

        return $value;
    }

    private function requiredPositiveAmount(array $request, array $keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request)) {
                $amount = (float) $request[$key];
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
    }

    private function contextUserId(array $request, array $context): int
    {
        $userId = (int) ($request['user_id'] ?? $context['user_id'] ?? 1);
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }

        return $userId;
    }

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");

        return $result && $result->num_rows > 0;
    }
}
