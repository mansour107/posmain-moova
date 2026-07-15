<?php

require_once __DIR__ . '/../../Sync/BranchIdentity.php';

/**
 * Immutable, one-to-one close snapshot for a drawer session.
 *
 * Drawer cash, status and variance stay canonical on drawer_sessions; this
 * child stores only report values that would otherwise need to be recomputed.
 */
class DrawerSessionCloseSummaryService
{
    /**
     * Provision only this additive table for mixed-version deployments.
     * Call before starting a money transaction because MySQL DDL commits
     * implicitly. The regular migration runner remains authoritative for all
     * upgrades and for retiring legacy structures.
     */
    public function ensureSchema(mysqli $conn): void
    {
        if ($this->tableExists($conn, 'drawer_session_close_summaries')) {
            return;
        }

        require_once dirname(__DIR__, 2) . '/Sync/SchemaManager.php';
        $statement = (new SyncSchemaManager())->plannedStatements()['drawer_session_close_summaries'] ?? null;
        if (!is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('DRAWER_CLOSE_SUMMARY_SCHEMA_UNAVAILABLE');
        }
        $conn->query($statement);
        if (!$this->tableExists($conn, 'drawer_session_close_summaries')) {
            throw new RuntimeException('DRAWER_CLOSE_SUMMARY_SCHEMA_MISSING');
        }
    }

    public function createForSession(mysqli $conn, int $drawerSessionId, array $data): array
    {
        if ($drawerSessionId < 1) {
            throw new InvalidArgumentException('DRAWER_SESSION_REQUIRED');
        }
        if (!$this->tableExists($conn, 'drawer_session_close_summaries')) {
            throw new RuntimeException('DRAWER_CLOSE_SUMMARY_SCHEMA_MISSING');
        }

        $existing = $this->findBySessionId($conn, $drawerSessionId);
        if ($existing) {
            throw new RuntimeException('DRAWER_CLOSE_SUMMARY_ALREADY_EXISTS');
        }

        $uuid = SyncBranchIdentity::generateUuidV4();
        $shiftNumber = trim((string) ($data['shift_number'] ?? ''));
        $totalOrders = max(0, (int) ($data['total_orders'] ?? 0));
        $totalSales = $this->decimal($data['total_sales'] ?? 0);
        $cashSales = $this->decimal($data['cash_sales'] ?? 0);
        $nonCashSales = $this->decimal($data['non_cash_sales'] ?? 0);
        $discountTotal = $this->decimal($data['discount_total'] ?? 0);
        $returnTotal = $this->decimal($data['return_total'] ?? 0);
        $expenseTotal = $this->decimal($data['expense_total'] ?? 0);
        $expenseNotes = $this->nullableText($data['expense_notes'] ?? null, 500);
        $expectedNonCash = $this->nullableDecimal($data['expected_non_cash'] ?? null);
        $countedNonCash = $this->nullableDecimal($data['counted_non_cash'] ?? null);
        $nonCashDifference = $this->nullableDecimal($data['non_cash_difference'] ?? null);
        $closePath = substr(trim((string) ($data['close_path'] ?? 'close_shift.php')), 0, 120);
        $reportSnapshot = $this->json($data['report_snapshot'] ?? null);
        $paymentSummary = $this->json($data['payment_summary'] ?? null);

        $stmt = $conn->prepare(
            'INSERT INTO drawer_session_close_summaries
                (uuid, drawer_session_id, shift_number, total_orders, total_sales,
                 cash_sales, non_cash_sales, discount_total, return_total,
                 expense_total, expense_notes, expected_non_cash, counted_non_cash,
                 non_cash_difference, close_path, report_snapshot_json, payment_summary_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'sisisssssssssssss',
            $uuid,
            $drawerSessionId,
            $shiftNumber,
            $totalOrders,
            $totalSales,
            $cashSales,
            $nonCashSales,
            $discountTotal,
            $returnTotal,
            $expenseTotal,
            $expenseNotes,
            $expectedNonCash,
            $countedNonCash,
            $nonCashDifference,
            $closePath,
            $reportSnapshot,
            $paymentSummary
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->findById($conn, $id) ?: [];
    }

    public function findById(mysqli $conn, int $id): ?array
    {
        return $this->find($conn, 'id', $id);
    }

    public function findBySessionId(mysqli $conn, int $drawerSessionId): ?array
    {
        return $this->find($conn, 'drawer_session_id', $drawerSessionId);
    }

    private function find(mysqli $conn, string $column, int $value): ?array
    {
        if ($value < 1 || !$this->tableExists($conn, 'drawer_session_close_summaries')) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM drawer_session_close_summaries WHERE {$column} = ? LIMIT 1");
        $stmt->bind_param('i', $value);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function decimal($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function nullableDecimal($value): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal($value);
    }

    private function nullableText($value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function json($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $value;
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('DRAWER_CLOSE_SUMMARY_JSON_INVALID');
        }

        return $encoded;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
