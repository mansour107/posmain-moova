<?php

require_once __DIR__ . '/../../Sync/DocumentCounterService.php';
require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
require_once __DIR__ . '/../../Financial/Money.php';

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
        $idempotencyKey = trim((string) ($request['idempotency_key'] ?? ''));

        if ($customerAccountId < 1) {
            throw new InvalidArgumentException('CUSTOMER_ACCOUNT_REQUIRED');
        }

        if ($idempotencyKey !== '') {
            $existing = JournalPostingService::findByIdempotencyKey($conn, $idempotencyKey);
            if ($existing !== null) {
                if (
                    (string) ($existing['source_type'] ?? '') !== 'payment'
                    || Money::from((string) ($existing['total'] ?? '0'))->compare(Money::from($amount)) !== 0
                ) {
                    throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
                }

                return [
                    'receipt_id' => (int) ($existing['op_id'] ?? 0),
                    'journal_id' => (int) ($existing['journal_id'] ?? 0),
                    'journal_head_id' => (int) $existing['id'],
                    'entry_count' => 2,
                    'amount' => $amount,
                    'replayed' => true,
                ];
            }
        }

        $receiptId = $this->insertReceiptHeader(
            $conn,
            $orderId,
            $amount,
            $safeAccountId,
            $customerAccountId,
            $employeeId,
            $userId,
            $date,
            $tableName,
            $tenant,
            $branch
        );
        $journalId = $this->nextJournalId($conn, $tenant, $branch);
        $journalHeadId = JournalPostingService::postBalancedHead($conn, (string) $journalId, $amount, $date, 'سند قبض - سداد طاولة ' . $tableName, $userId, [
            ['account_id' => $safeAccountId, 'debit' => $amount, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
            ['account_id' => $customerAccountId, 'debit' => '0.00', 'credit' => $amount, 'tybe' => 1, 'op2' => $orderId],
        ], [
            'op_id' => $receiptId,
            'op2' => $orderId,
            'source_type' => 'payment',
            'source_id' => $receiptId,
            'posting_kind' => 'payment_receipt',
            'idempotency_key' => $idempotencyKey,
            'tenant' => $tenant,
            'branch' => $branch,
        ]);

        return [
            'receipt_id' => $receiptId,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
            'entry_count' => 2,
            'amount' => $amount,
        ];
    }

    private function insertReceiptHeader(
        mysqli $conn,
        int $orderId,
        string $amount,
        int $safeAccountId,
        int $customerAccountId,
        int $employeeId,
        int $userId,
        string $date,
        string $tableName,
        int $tenant,
        int $branch
    ): int {
        $infoText = 'سند قبض - سداد طاولة: ' . $tableName . ' - فاتورة رقم ' . $orderId;
        $stmt = $conn->prepare("
            INSERT INTO ot_head (
                pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, fat_net, cost_center, profit, user, op2
            ) VALUES (1, 1, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
        ");
        $stmt->bind_param(
            'ssiiissii',
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
        if ($this->columnExists($conn, 'ot_head', 'tenant')
            && $this->columnExists($conn, 'ot_head', 'branch')) {
            $scope = $conn->prepare('UPDATE ot_head SET tenant = ?, branch = ? WHERE id = ?');
            $scope->bind_param('iii', $tenant, $branch, $receiptId);
            $scope->execute();
            $scope->close();
        }

        return $receiptId;
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

    private function requiredPositiveAmount(array $request, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request)) {
                $amount = Money::fromLegacy($request[$key]);
                if ($amount->isPositive()) {
                    return $amount->toString();
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
