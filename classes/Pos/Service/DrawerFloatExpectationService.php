<?php

require_once dirname(__DIR__, 3) . '/includes/drawer_movement_signs.php';

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
        $baseCounted = 0.0;
        $sinceAt = null;
        $lastSessionId = null;
        $baselineRequired = false;
        $baseline = null;

        if ($lastClosed) {
            $baseCounted = (float) ($lastClosed['counted_cash'] ?? 0);
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
        $expected = round($baseCounted + $unassignedNet, 3);

        return [
            'base_counted' => $this->formatDecimal($baseCounted),
            'unassigned_net' => $this->formatDecimal($unassignedNet),
            'interim_net' => $this->formatDecimal(0),
            'expected' => $this->formatDecimal($expected),
            'since_at' => $sinceAt,
            'last_session_id' => $lastSessionId,
            'baseline_required' => $baselineRequired,
            'baseline' => $baseline !== null ? $this->formatDecimal($baseline) : null,
        ];
    }

    public function needsBaselineInitialization(mysqli $conn, int $tenant, int $branch): bool
    {
        if ($this->findLastClosedSession($conn, $tenant, $branch)) {
            return false;
        }

        return $this->getOpeningBaseline($conn, $tenant, $branch) === null;
    }

    public function getOpeningBaseline(mysqli $conn, int $tenant, int $branch): ?float
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

        return round((float) $row['opening_float_baseline'], 3);
    }

    public function setOpeningBaseline(mysqli $conn, int $tenant, int $branch, float $amount, int $userId): array
    {
        if ($amount < 0) {
            throw new RuntimeException('BASELINE_AMOUNT_INVALID');
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

        $formatted = number_format(round($amount, 3), 3, '.', '');

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

    public function toleranceForBranch(mysqli $conn, int $tenant, int $branch): float
    {
        if (!$this->tableExists($conn, 'pos_branch_settings')) {
            return 0.010;
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
            return 0.010;
        }

        $tolerance = (float) ($row['cash_count_tolerance'] ?? 0.010);

        return $tolerance > 0 ? $tolerance : 0.010;
    }

    public function amountsMatch(float $counted, float $expected, float $tolerance): bool
    {
        return abs(round($counted - $expected, 3)) <= $tolerance;
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

    private function netUnassignedMovementsSince(mysqli $conn, int $tenant, int $branch, ?string $sinceAt): float
    {
        if (!$this->tableExists($conn, 'drawer_movements')) {
            return 0.0;
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

        $net = 0.0;
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['movement_type'] ?? '');
            $sign = $this->movementSigns()[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }
            $net += $sign * (float) ($row['amount'] ?? 0);
        }
        $stmt->close();

        return round($net, 3);
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 3, '.', '');
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
