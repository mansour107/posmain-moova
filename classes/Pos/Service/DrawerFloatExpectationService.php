<?php

require_once dirname(__DIR__, 3) . '/includes/drawer_movement_signs.php';
require_once dirname(__DIR__) . '/Value/CashAmount.php';

class DrawerFloatExpectationService
{
    /** @return array<string, int> */
    private function movementSigns(): array
    {
        return posmain_drawer_movement_signs();
    }

    public function expectedOpeningFloat(mysqli $conn, int $tenant, int $branch): array
    {
        $lastClosed = $this->findLastClosedSession($conn, $tenant, $branch);
        $baseCounted = '0.00';
        $sinceAt = null;
        $lastSessionId = null;
        $baselineRequired = false;
        $baseline = null;

        if ($lastClosed) {
            $baseCounted = CashAmount::normalize($lastClosed['counted_cash'] ?? '0.00');
            $sinceAt = (string) ($lastClosed['closed_at'] ?? '');
            $lastSessionId = (int) ($lastClosed['id'] ?? 0);
        } else {
            $baseline = $this->getOpeningBaseline($conn, $tenant, $branch);
            if ($baseline === null) {
                $baselineRequired = true;
            } else {
                $baseCounted = $baseline;
            }
        }

        $unassignedNet = $this->netUnassignedMovementsSince($conn, $tenant, $branch, $sinceAt);
        $expected = CashAmount::add($baseCounted, $unassignedNet);

        return [
            'base_counted' => $baseCounted,
            'unassigned_net' => $unassignedNet,
            'interim_net' => '0.00',
            'expected' => $expected,
            'since_at' => $sinceAt,
            'last_session_id' => $lastSessionId,
            'baseline_required' => $baselineRequired,
            'baseline' => $baseline,
        ];
    }

    public function needsBaselineInitialization(mysqli $conn, int $tenant, int $branch): bool
    {
        if ($this->findLastClosedSession($conn, $tenant, $branch)) {
            return false;
        }

        return $this->getOpeningBaseline($conn, $tenant, $branch) === null;
    }

    public function getOpeningBaseline(mysqli $conn, int $tenant, int $branch): ?string
    {
        if (!$this->tableExists($conn, 'pos_branch_settings')
            || !$this->columnExists($conn, 'pos_branch_settings', 'opening_float_baseline')) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT opening_float_baseline
            FROM pos_branch_settings
            WHERE pos_tenant = ? AND pos_branch = ?
            LIMIT 1
        ');
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['opening_float_baseline'] === null) {
            return null;
        }

        return CashAmount::normalize($row['opening_float_baseline']);
    }

    public function setOpeningBaseline(mysqli $conn, int $tenant, int $branch, $amount, int $userId): array
    {
        try {
            $formatted = CashAmount::normalize($amount);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('BASELINE_AMOUNT_INVALID', 0, $exception);
        }

        if ($this->findLastClosedSession($conn, $tenant, $branch)) {
            throw new RuntimeException('BASELINE_NOT_REQUIRED');
        }

        if ($this->branchHasAnyDrawerSession($conn, $tenant, $branch)) {
            throw new RuntimeException('BASELINE_LOCKED');
        }

        if (!$this->tableExists($conn, 'pos_branch_settings')) {
            throw new RuntimeException('BRANCH_SETTINGS_UNAVAILABLE');
        }

        $stmt = $conn->prepare('
            INSERT INTO pos_branch_settings (
                pos_tenant, pos_branch, opening_float_baseline,
                opening_float_baseline_set_by, opening_float_baseline_set_at
            ) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                opening_float_baseline = VALUES(opening_float_baseline),
                opening_float_baseline_set_by = VALUES(opening_float_baseline_set_by),
                opening_float_baseline_set_at = NOW()
        ');
        $stmt->bind_param('iisi', $tenant, $branch, $formatted, $userId);
        $stmt->execute();
        $stmt->close();

        return [
            'opening_float_baseline' => $formatted,
            'tenant' => $tenant,
            'branch' => $branch,
        ];
    }

    public function canSetOpeningBaseline(mysqli $conn, int $tenant, int $branch): bool
    {
        if ($this->findLastClosedSession($conn, $tenant, $branch)) {
            return false;
        }

        return !$this->branchHasAnyDrawerSession($conn, $tenant, $branch);
    }

    public function toleranceForBranch(mysqli $conn, int $tenant, int $branch): string
    {
        if (!$this->tableExists($conn, 'pos_branch_settings')) {
            return '0.01';
        }

        $stmt = $conn->prepare('
            SELECT cash_count_tolerance
            FROM pos_branch_settings
            WHERE pos_tenant = ? AND pos_branch = ?
            LIMIT 1
        ');
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return '0.01';
        }

        $tolerance = CashAmount::normalize($row['cash_count_tolerance'] ?? '0.01');

        return CashAmount::compare($tolerance, '0.00') > 0 ? $tolerance : '0.01';
    }

    public function amountsMatch($counted, $expected, $tolerance): bool
    {
        $difference = CashAmount::subtract($counted, $expected);
        if (CashAmount::compare($difference, '0.00') < 0) {
            $difference = CashAmount::negate($difference);
        }

        return CashAmount::compare($difference, CashAmount::normalize($tolerance)) <= 0;
    }

    private function findLastClosedSession(mysqli $conn, int $tenant, int $branch): ?array
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return null;
        }

        $sql = "
            SELECT id, counted_cash, closed_at, status
            FROM drawer_sessions
            WHERE status IN ('closed', 'forced_closed')
              AND closed_at IS NOT NULL
        ";
        $params = [];
        $types = '';

        if ($tenant > 0) {
            $sql .= ' AND tenant = ?';
            $params[] = $tenant;
            $types .= 'i';
        }
        if ($branch > 0) {
            $sql .= ' AND branch = ?';
            $params[] = $branch;
            $types .= 'i';
        }

        $sql .= ' ORDER BY closed_at DESC, id DESC LIMIT 1';

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function branchHasAnyDrawerSession(mysqli $conn, int $tenant, int $branch): bool
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return false;
        }

        $sql = 'SELECT 1 FROM drawer_sessions WHERE 1=1';
        $params = [];
        $types = '';

        if ($tenant > 0) {
            $sql .= ' AND tenant = ?';
            $params[] = $tenant;
            $types .= 'i';
        }
        if ($branch > 0) {
            $sql .= ' AND branch = ?';
            $params[] = $branch;
            $types .= 'i';
        }

        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    private function netUnassignedMovementsSince(mysqli $conn, int $tenant, int $branch, ?string $sinceAt): string
    {
        if (!$this->tableExists($conn, 'drawer_movements')) {
            return '0.00';
        }

        $sql = "
            SELECT movement_type, amount
            FROM drawer_movements
            WHERE drawer_session_id IS NULL
        ";
        $params = [];
        $types = '';

        if ($tenant > 0 && $this->columnExists($conn, 'drawer_movements', 'tenant')) {
            $sql .= ' AND tenant = ?';
            $params[] = $tenant;
            $types .= 'i';
        }
        if ($branch > 0 && $this->columnExists($conn, 'drawer_movements', 'branch')) {
            $sql .= ' AND branch = ?';
            $params[] = $branch;
            $types .= 'i';
        }
        if ($sinceAt !== null && trim($sinceAt) !== '') {
            $sql .= ' AND created_at > ?';
            $params[] = $sinceAt;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $net = '0.00';
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['movement_type'] ?? '');
            $sign = $this->movementSigns()[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }
            $amount = CashAmount::normalize($row['amount'] ?? '0.00');
            $net = CashAmount::add($net, $sign < 0 ? CashAmount::negate($amount) : $amount);
        }
        $stmt->close();

        return $net;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
