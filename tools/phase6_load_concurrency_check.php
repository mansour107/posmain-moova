<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

final class Phase6LoadConcurrencyCheck
{
    private mysqli $db;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,mixed> */
    private array $options;
    private string $runId;
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     */
    public function __construct(mysqli $db, array $config, array $options)
    {
        $this->db = $db;
        $this->config = $config;
        $this->options = $options;
        $this->runId = 'phase6-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $this->requireTables();
        $this->cleanup();
        $this->seedFixtures();

        $scenarios = [
            'cashier_sales' => $this->runCashierSales(),
            'waiter_table_saves' => $this->runWaiterTableSaves(),
            'same_table_conflict' => $this->runSameTableConflict(),
            'duplicate_payment_submit' => $this->runDuplicatePaymentSubmit(),
            'item_search_requests' => $this->runItemSearchRequests(),
        ];

        $ok = true;
        foreach ($scenarios as $scenario) {
            $ok = $ok && !empty($scenario['ok']);
        }

        if (!empty($this->options['cleanup'])) {
            $this->cleanup();
        }

        return [
            'ok' => $ok,
            'run_id' => $this->runId,
            'database' => $this->databaseName(),
            'tenant' => $this->tenant(),
            'branch' => $this->branch(),
            'cleanup' => !empty($this->options['cleanup']),
            'warnings' => $this->warnings,
            'scenarios' => $scenarios,
        ];
    }

    private function requireTables(): void
    {
        $requirements = [
            'document_counters' => ['pos_tenant', 'pos_branch', 'counter_type', 'counter_key', 'current_value'],
            'ot_head' => ['pro_id', 'pro_tybe', 'pro_serial', 'order_type', 'payment_status', 'order_status', 'paid_amount', 'remaining_amount', 'fat_net', 'table_id', 'pro_date', 'accural_date', 'tenant', 'branch', 'isdeleted', 'info'],
            'tables' => ['id', 'tname', 'table_case', 'area_id', 'capacity', 'display_order', 'isdeleted', 'tenant', 'branch'],
            'myitems' => ['id', 'barcode', 'iname', 'price1', 'sprice', 'group1', 'itmqty', 'isdeleted', 'tenant', 'branch'],
        ];

        $missing = [];
        foreach ($requirements as $table => $columns) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
                continue;
            }
            foreach ($columns as $column) {
                if (!$this->hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Phase 6 load check missing required schema: ' . implode(', ', $missing));
        }
    }

    private function seedFixtures(): void
    {
        $this->execute(
            "INSERT INTO document_counters (pos_tenant, pos_branch, counter_type, counter_key, current_value)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE current_value = 0",
            [$this->tenant(), $this->branch(), 'phase6_load_check', $this->runId]
        );

        for ($i = 1; $i <= 6; $i++) {
            $this->execute(
                "INSERT INTO tables (tname, table_case, area_id, capacity, display_order, isdeleted, tenant, branch)
                 VALUES (?, 0, 0, 4, ?, 0, ?, ?)",
                [sprintf('P6-CONC-TABLE-%02d', $i), $i, $this->tenant(), $this->branch()]
            );
        }

        for ($i = 1; $i <= 60; $i++) {
            $this->execute(
                "INSERT INTO myitems (barcode, iname, price1, sprice, group1, itmqty, isdeleted, tenant, branch)
                 VALUES (?, ?, ?, ?, 0, 100, 0, ?, ?)",
                [
                    sprintf('P6CONC-%03d', $i),
                    sprintf('P6-CONC Search Item %03d', $i),
                    10 + ($i % 7),
                    10 + ($i % 7),
                    $this->tenant(),
                    $this->branch(),
                ]
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function runCashierSales(): array
    {
        $jobs = [];
        for ($i = 1; $i <= (int)$this->options['cashiers']; $i++) {
            $index = $i;
            $jobs[] = fn(): array => $this->childCreateSale($index);
        }

        $results = $this->runConcurrent($jobs);
        $proIds = [];
        foreach ($results as $result) {
            if (!empty($result['ok']) && isset($result['pro_id'])) {
                $proIds[] = (int)$result['pro_id'];
            }
        }

        $distinctProIds = count(array_unique($proIds));
        $dbRows = (int)$this->scalar(
            "SELECT COUNT(*) FROM ot_head WHERE pro_serial LIKE ?",
            ['P6-CONC-SALE-' . $this->runId . '-%']
        );
        $dbDistinct = (int)$this->scalar(
            "SELECT COUNT(DISTINCT pro_id) FROM ot_head WHERE pro_serial LIKE ?",
            ['P6-CONC-SALE-' . $this->runId . '-%']
        );
        $expected = (int)$this->options['cashiers'];
        $ok = count($proIds) === $expected && $distinctProIds === $expected && $dbRows === $expected && $dbDistinct === $expected;

        return [
            'ok' => $ok,
            'expected_cashiers' => $expected,
            'successful_sales' => count($proIds),
            'unique_pro_ids' => $distinctProIds,
            'db_rows' => $dbRows,
            'db_unique_pro_ids' => $dbDistinct,
            'duplicate_pro_id' => $distinctProIds !== count($proIds),
            'child_results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runWaiterTableSaves(): array
    {
        $tableIds = $this->fixtureTableIds(1, 3);
        $jobs = [];
        foreach ($tableIds as $offset => $tableId) {
            $jobs[] = fn(): array => $this->childSaveTableOrder($tableId, $offset + 1, false);
        }

        $results = $this->runConcurrent($jobs);
        $success = $this->countResultStatus($results, 'saved');
        $occupied = $this->countOccupiedTables($tableIds);
        $ok = $success === 3 && $occupied === 3;

        return [
            'ok' => $ok,
            'expected_waiters' => 3,
            'successful_table_saves' => $success,
            'occupied_tables' => $occupied,
            'table_stuck_unexpectedly' => $occupied !== 3,
            'child_results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runSameTableConflict(): array
    {
        $tableId = $this->fixtureTableIds(4, 1)[0];
        $this->execute('UPDATE tables SET table_case = 0 WHERE id = ?', [$tableId]);
        $jobs = [
            fn(): array => $this->childSaveTableOrder($tableId, 1, true),
            fn(): array => $this->childSaveTableOrder($tableId, 2, true),
        ];

        $results = $this->runConcurrent($jobs);
        $success = $this->countResultStatus($results, 'saved');
        $conflicts = $this->countResultStatus($results, 'occupied_conflict');
        $occupied = $this->countOccupiedTables([$tableId]);
        $ok = $success === 1 && $conflicts === 1 && $occupied === 1;

        return [
            'ok' => $ok,
            'success_count' => $success,
            'conflict_count' => $conflicts,
            'occupied_tables' => $occupied,
            'child_results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runDuplicatePaymentSubmit(): array
    {
        $tableId = $this->fixtureTableIds(5, 1)[0];
        $this->execute('UPDATE tables SET table_case = 1 WHERE id = ?', [$tableId]);
        $orderId = $this->insertOrder(90001, 'P6-CONC-PAY-' . $this->runId, 'table', $tableId, 120.0, 0.0, 120.0, 'partial', 'open');

        $jobs = [
            fn(): array => $this->childApplyPayment($orderId, 120.0),
            fn(): array => $this->childApplyPayment($orderId, 120.0),
        ];

        $results = $this->runConcurrent($jobs);
        $success = $this->countResultStatus($results, 'paid');
        $duplicates = $this->countResultStatus($results, 'duplicate_ignored');
        $row = $this->fetchOne('SELECT remaining_amount, paid_amount, payment_status, order_status, table_id FROM ot_head WHERE id = ?', [$orderId]);
        $remaining = (float)($row['remaining_amount'] ?? -1);
        $paid = (float)($row['paid_amount'] ?? -1);
        $tableCase = (int)$this->scalar('SELECT table_case FROM tables WHERE id = ?', [(int)$row['table_id']]);
        $ok = $success === 1 && $duplicates === 1 && $remaining >= 0.0 && abs($remaining) < 0.0001 && abs($paid - 120.0) < 0.0001 && $tableCase === 0;

        return [
            'ok' => $ok,
            'success_count' => $success,
            'duplicate_count' => $duplicates,
            'remaining_amount' => $remaining,
            'paid_amount' => $paid,
            'table_case_after_payment' => $tableCase,
            'negative_remaining_amount' => $remaining < 0.0,
            'table_stuck_occupied_after_paid_order' => $tableCase !== 0,
            'child_results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runItemSearchRequests(): array
    {
        $requestCount = (int)$this->options['search_requests'];
        $durations = [];
        $matchedRows = 0;
        for ($i = 1; $i <= $requestCount; $i++) {
            $term = sprintf('P6-CONC Search Item %03d', ($i % 60) + 1);
            $like = '%' . $term . '%';
            $started = microtime(true);
            $stmt = $this->db->prepare(
                'SELECT id, iname FROM myitems WHERE isdeleted = 0 AND (barcode LIKE ? OR iname LIKE ?) ORDER BY id LIMIT 20'
            );
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            $matchedRows += $result->num_rows;
            $stmt->close();
            $durations[] = (microtime(true) - $started) * 1000;
        }

        $average = array_sum($durations) / max(1, count($durations));
        $max = max($durations);
        $threshold = (float)$this->options['max_search_ms'];
        $ok = $matchedRows >= $requestCount && $average <= $threshold;

        return [
            'ok' => $ok,
            'requests' => $requestCount,
            'matched_rows' => $matchedRows,
            'average_ms' => round($average, 3),
            'max_ms' => round($max, 3),
            'threshold_average_ms' => $threshold,
            'response_time_acceptable' => $average <= $threshold,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function childCreateSale(int $index): array
    {
        $conn = $this->newConnection();
        try {
            $conn->begin_transaction();
            $proId = $this->nextProId($conn);
            $serial = sprintf('P6-CONC-SALE-%s-%02d', $this->runId, $index);
            $orderId = $this->insertOrderWithConnection($conn, $proId, $serial, 'takeaway', null, 40.0 + $index, 40.0 + $index, 0.0, 'paid', 'completed');
            $conn->commit();

            return ['ok' => true, 'status' => 'created', 'order_id' => $orderId, 'pro_id' => $proId];
        } catch (Throwable $exception) {
            $conn->rollback();
            return ['ok' => false, 'status' => 'error', 'error' => $exception->getMessage()];
        } finally {
            $conn->close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function childSaveTableOrder(int $tableId, int $index, bool $holdLock): array
    {
        $conn = $this->newConnection();
        try {
            $conn->begin_transaction();
            $tableCase = (int)$this->scalarWithConnection($conn, 'SELECT table_case FROM tables WHERE id = ? FOR UPDATE', [$tableId]);
            if ($tableCase !== 0) {
                $conn->rollback();
                return ['ok' => true, 'status' => 'occupied_conflict', 'table_id' => $tableId];
            }

            if ($holdLock) {
                usleep(150000);
            }

            $this->executeWithConnection($conn, 'UPDATE tables SET table_case = 1 WHERE id = ?', [$tableId]);
            $proId = $this->nextProId($conn);
            $serial = sprintf('P6-CONC-TABLE-%s-%02d-%d', $this->runId, $index, $tableId);
            $orderId = $this->insertOrderWithConnection($conn, $proId, $serial, 'table', $tableId, 55.0, 0.0, 55.0, 'unpaid', 'open');
            $conn->commit();

            return ['ok' => true, 'status' => 'saved', 'table_id' => $tableId, 'order_id' => $orderId, 'pro_id' => $proId];
        } catch (Throwable $exception) {
            $conn->rollback();
            return ['ok' => false, 'status' => 'error', 'error' => $exception->getMessage(), 'table_id' => $tableId];
        } finally {
            $conn->close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function childApplyPayment(int $orderId, float $amount): array
    {
        $conn = $this->newConnection();
        try {
            $conn->begin_transaction();
            $row = $this->fetchOneWithConnection($conn, 'SELECT id, table_id, paid_amount, remaining_amount FROM ot_head WHERE id = ? FOR UPDATE', [$orderId]);
            $remaining = (float)($row['remaining_amount'] ?? 0);
            if ($remaining <= 0.0) {
                $conn->rollback();
                return ['ok' => true, 'status' => 'duplicate_ignored', 'order_id' => $orderId, 'remaining_amount' => $remaining];
            }

            usleep(150000);
            $applied = min($amount, $remaining);
            $newPaid = (float)$row['paid_amount'] + $applied;
            $newRemaining = max(0.0, $remaining - $applied);
            $paymentStatus = $newRemaining <= 0.0001 ? 'paid' : 'partial';
            $orderStatus = $newRemaining <= 0.0001 ? 'completed' : 'open';
            $this->executeWithConnection(
                $conn,
                'UPDATE ot_head SET paid_amount = ?, remaining_amount = ?, payment_status = ?, order_status = ? WHERE id = ?',
                [$newPaid, $newRemaining, $paymentStatus, $orderStatus, $orderId]
            );
            if ($newRemaining <= 0.0001 && (int)$row['table_id'] > 0) {
                $this->executeWithConnection($conn, 'UPDATE tables SET table_case = 0 WHERE id = ?', [(int)$row['table_id']]);
            }
            $conn->commit();

            return ['ok' => true, 'status' => 'paid', 'order_id' => $orderId, 'applied_amount' => $applied, 'remaining_amount' => $newRemaining];
        } catch (Throwable $exception) {
            $conn->rollback();
            return ['ok' => false, 'status' => 'error', 'error' => $exception->getMessage(), 'order_id' => $orderId];
        } finally {
            $conn->close();
        }
    }

    /**
     * @param list<Closure(): array<string,mixed>> $jobs
     * @return list<array<string,mixed>>
     */
    private function runConcurrent(array $jobs): array
    {
        if (!extension_loaded('pcntl')) {
            $this->warnings[] = 'pcntl extension is unavailable; scenario executed sequentially.';
            $results = [];
            foreach ($jobs as $job) {
                $results[] = $job();
            }
            return $results;
        }

        $dir = sys_get_temp_dir() . '/posmain_phase6_' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create concurrency result directory.');
        }

        $pids = [];
        foreach ($jobs as $index => $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed.');
            }
            if ($pid === 0) {
                $result = $job();
                file_put_contents($dir . '/' . $index . '.json', json_encode($result, JSON_UNESCAPED_SLASHES));
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $this->reconnectParentConnection();

        $results = [];
        foreach (array_keys($jobs) as $index) {
            $path = $dir . '/' . $index . '.json';
            $payload = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
            $results[] = is_array($payload) ? $payload : ['ok' => false, 'status' => 'missing_child_result'];
            @unlink($path);
        }
        @rmdir($dir);

        return $results;
    }

    private function nextProId(mysqli $conn): int
    {
        $row = $this->fetchOneWithConnection(
            $conn,
            'SELECT current_value FROM document_counters WHERE pos_tenant = ? AND pos_branch = ? AND counter_type = ? AND counter_key = ? FOR UPDATE',
            [$this->tenant(), $this->branch(), 'phase6_load_check', $this->runId]
        );
        $current = (int)($row['current_value'] ?? 0);
        $next = $current + 1;
        $this->executeWithConnection(
            $conn,
            'UPDATE document_counters SET current_value = ? WHERE pos_tenant = ? AND pos_branch = ? AND counter_type = ? AND counter_key = ?',
            [$next, $this->tenant(), $this->branch(), 'phase6_load_check', $this->runId]
        );

        return $next;
    }

    private function insertOrder(int $proId, string $serial, string $orderType, ?int $tableId, float $net, float $paid, float $remaining, string $paymentStatus, string $orderStatus): int
    {
        return $this->insertOrderWithConnection($this->db, $proId, $serial, $orderType, $tableId, $net, $paid, $remaining, $paymentStatus, $orderStatus);
    }

    private function insertOrderWithConnection(mysqli $conn, int $proId, string $serial, string $orderType, ?int $tableId, float $net, float $paid, float $remaining, string $paymentStatus, string $orderStatus): int
    {
        $this->executeWithConnection(
            $conn,
            "INSERT INTO ot_head (
                pro_id, pro_tybe, pro_serial, order_type, table_id, fat_net, paid_amount,
                remaining_amount, payment_status, order_status, pro_date, accural_date,
                tenant, branch, isdeleted, info
            ) VALUES (?, 9, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE(), ?, ?, 0, ?)",
            [
                $proId,
                $serial,
                $orderType,
                $tableId,
                $net,
                $paid,
                $remaining,
                $paymentStatus,
                $orderStatus,
                $this->tenant(),
                $this->branch(),
                'Phase 6 load/concurrency check',
            ]
        );

        return (int)$conn->insert_id;
    }

    /**
     * @return list<int>
     */
    private function fixtureTableIds(int $offset, int $count): array
    {
        $start = sprintf('P6-CONC-TABLE-%02d', $offset);
        $end = sprintf('P6-CONC-TABLE-%02d', $offset + $count - 1);
        $stmt = $this->db->prepare('SELECT id FROM tables WHERE tname BETWEEN ? AND ? ORDER BY tname LIMIT ' . (int)$count);
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();

        if (count($ids) !== $count) {
            throw new RuntimeException('Unable to resolve Phase 6 fixture tables.');
        }

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    private function countResultStatus(array $results, string $status): int
    {
        $count = 0;
        foreach ($results as $result) {
            if (($result['status'] ?? '') === $status) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<int> $tableIds
     */
    private function countOccupiedTables(array $tableIds): int
    {
        if ($tableIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($tableIds), '?'));
        return (int)$this->scalar(
            "SELECT COUNT(*) FROM tables WHERE id IN ({$placeholders}) AND table_case <> 0",
            $tableIds
        );
    }

    private function cleanup(): void
    {
        if ($this->tableExists('ot_head') && $this->hasColumn('ot_head', 'pro_serial')) {
            $this->execute("DELETE FROM ot_head WHERE pro_serial LIKE 'P6-CONC-%'", []);
        }
        if ($this->tableExists('myitems') && $this->hasColumn('myitems', 'barcode')) {
            $this->execute("DELETE FROM myitems WHERE barcode LIKE 'P6CONC-%'", []);
        }
        if ($this->tableExists('tables') && $this->hasColumn('tables', 'tname')) {
            $this->execute("DELETE FROM tables WHERE tname LIKE 'P6-CONC-%'", []);
        }
        if ($this->tableExists('document_counters') && $this->hasColumn('document_counters', 'counter_type')) {
            $this->execute("DELETE FROM document_counters WHERE counter_type = 'phase6_load_check'", []);
        }
    }

    private function tableExists(string $table): bool
    {
        return (int)$this->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int)$this->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    /**
     * @param list<mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        $this->executeWithConnection($this->db, $sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function executeWithConnection(mysqli $conn, string $sql, array $params): void
    {
        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $types = $this->bindTypes($params);
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        return $this->scalarWithConnection($this->db, $sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function scalarWithConnection(mysqli $conn, string $sql, array $params): mixed
    {
        $row = $this->fetchOneWithConnection($conn, $sql, $params);

        return array_values($row)[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function fetchOne(string $sql, array $params): array
    {
        return $this->fetchOneWithConnection($this->db, $sql, $params);
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function fetchOneWithConnection(mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $types = $this->bindTypes($params);
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : [];
    }

    /**
     * @param list<mixed> $params
     */
    private function bindTypes(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    private function newConnection(): mysqli
    {
        $database = $this->config['database'] ?? [];
        return new mysqli(
            (string)($database['host'] ?? '127.0.0.1'),
            (string)($database['user'] ?? 'root'),
            (string)($database['pass'] ?? ''),
            (string)($database['name'] ?? ''),
            (int)($database['port'] ?? 3306)
        );
    }

    private function reconnectParentConnection(): void
    {
        try {
            $this->db->close();
        } catch (Throwable) {
            // The inherited connection may already be invalid after child exits.
        }

        $this->db = $this->newConnection();
    }

    private function databaseName(): string
    {
        return (string)($this->config['database']['name'] ?? '');
    }

    private function tenant(): int
    {
        return (int)($this->config['branch']['pos_tenant'] ?? 0);
    }

    private function branch(): int
    {
        return (int)($this->config['branch']['pos_branch'] ?? 0);
    }
}

/**
 * @return array<string,mixed>
 */
function phase6_load_options(): array
{
    $options = getopt('', [
        'help',
        'json',
        'allow-current-db',
        'keep-data',
        'cashiers::',
        'search-requests::',
        'max-search-ms::',
    ]);
    if ($options === false) {
        $options = [];
    }

    if (isset($options['help'])) {
        echo phase6_load_help();
        exit(0);
    }

    return [
        'json' => isset($options['json']),
        'allow_current_db' => isset($options['allow-current-db']),
        'cleanup' => !isset($options['keep-data']),
        'cashiers' => phase6_load_int_option($options, 'cashiers', 5, 1, 25),
        'search_requests' => phase6_load_int_option($options, 'search-requests', 100, 1, 1000),
        'max_search_ms' => phase6_load_float_option($options, 'max-search-ms', 750.0, 1.0, 10000.0),
    ];
}

function phase6_load_help(): string
{
    return <<<TXT
Usage: php tools/phase6_load_concurrency_check.php [--json] [--allow-current-db] [--keep-data]

Runs the Phase 6 local load/concurrency proof:
- 5 cashier sale writers against one document counter
- 3 waiter table saves on different tables
- 2 same-table writers where one must conflict
- 2 duplicate payment submits where one must be ignored
- 100 item search requests with average response-time threshold

Safety:
- Refuses production mode.
- Refuses ordinary database names unless --allow-current-db is passed.
- Uses only P6-CONC rows and cleans them by default.

TXT;
}

function phase6_load_refuse_unsafe(array $config, array $options): void
{
    $env = strtolower((string)($config['env'] ?? 'local'));
    $productionMode = (bool)($config['production_mode'] ?? false);
    if ($productionMode || $env === 'production' || $env === 'prod') {
        fwrite(STDERR, "Refusing to run Phase 6 load/concurrency checks in production mode.\n");
        exit(2);
    }

    $database = (string)($config['database']['name'] ?? '');
    if (empty($options['allow_current_db']) && !preg_match('/(test|phase6|staging|demo)/i', $database)) {
        fwrite(STDERR, "Refusing database '{$database}'. Use a disposable/staging/demo database name or pass --allow-current-db intentionally.\n");
        exit(2);
    }
}

function phase6_load_int_option(array $options, string $key, int $default, int $min, int $max): int
{
    $value = isset($options[$key]) ? (int)$options[$key] : $default;
    return max($min, min($max, $value));
}

function phase6_load_float_option(array $options, string $key, float $default, float $min, float $max): float
{
    $value = isset($options[$key]) ? (float)$options[$key] : $default;
    return max($min, min($max, $value));
}

function phase6_load_print(array $result, bool $json): void
{
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo ($result['ok'] ? 'Phase 6 load/concurrency check passed.' : 'Phase 6 load/concurrency check failed.') . PHP_EOL;
    foreach ($result['scenarios'] as $name => $scenario) {
        echo sprintf('- %s: %s', $name, !empty($scenario['ok']) ? 'pass' : 'fail') . PHP_EOL;
    }
    if ($result['warnings'] !== []) {
        echo 'Warnings:' . PHP_EOL;
        foreach ($result['warnings'] as $warning) {
            echo '- ' . $warning . PHP_EOL;
        }
    }
}

$phase6LoadOptions = phase6_load_options();
$phase6LoadConfig = posmain_app_config();
phase6_load_refuse_unsafe($phase6LoadConfig, $phase6LoadOptions);

try {
    $phase6LoadDb = posmain_db_connect();
    $phase6LoadCheck = new Phase6LoadConcurrencyCheck($phase6LoadDb, $phase6LoadConfig, $phase6LoadOptions);
    $phase6LoadResult = $phase6LoadCheck->run();
    phase6_load_print($phase6LoadResult, (bool)$phase6LoadOptions['json']);
    exit(!empty($phase6LoadResult['ok']) ? 0 : 2);
} catch (Throwable $exception) {
    if (!empty($phase6LoadOptions['json'])) {
        echo json_encode([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Phase 6 load/concurrency check failed: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}
