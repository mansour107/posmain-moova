<?php

class BusinessDayService
{
    public const DEFAULT_CUTOFF_HOUR = 6;

    public function cutoffHourForBranch(mysqli $conn, int $tenant, int $branch): int
    {
        if (!$this->tableExists($conn, 'pos_branch_settings')) {
            return self::DEFAULT_CUTOFF_HOUR;
        }

        $stmt = $conn->prepare('
            SELECT business_day_cutoff_hour
            FROM pos_branch_settings
            WHERE pos_tenant = ?
              AND pos_branch = ?
            LIMIT 1
        ');
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return self::DEFAULT_CUTOFF_HOUR;
        }

        return $this->normalizeCutoffHour((int) ($row['business_day_cutoff_hour'] ?? self::DEFAULT_CUTOFF_HOUR));
    }

    public function setCutoffHourForBranch(mysqli $conn, int $tenant, int $branch, int $cutoffHour): int
    {
        $cutoffHour = $this->normalizeCutoffHour($cutoffHour);
        if (!$this->tableExists($conn, 'pos_branch_settings')) {
            throw new RuntimeException('POS_BRANCH_SETTINGS_MISSING');
        }

        $stmt = $conn->prepare('
            INSERT INTO pos_branch_settings (pos_tenant, pos_branch, business_day_cutoff_hour)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                business_day_cutoff_hour = VALUES(business_day_cutoff_hour),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->bind_param('iii', $tenant, $branch, $cutoffHour);
        $stmt->execute();
        $stmt->close();

        return $cutoffHour;
    }

    public function businessDayForTimestamp(string $timestamp, int $cutoffHour): string
    {
        $cutoffHour = $this->normalizeCutoffHour($cutoffHour);
        $parsed = strtotime($timestamp);
        if ($parsed === false) {
            throw new InvalidArgumentException('BUSINESS_DAY_TIMESTAMP_INVALID');
        }

        $hour = (int) date('G', $parsed);
        if ($hour < $cutoffHour) {
            return date('Y-m-d', strtotime('-1 day', $parsed));
        }

        return date('Y-m-d', $parsed);
    }

    public function currentBusinessDayForBranch(mysqli $conn, int $tenant, int $branch, ?string $now = null): string
    {
        $cutoffHour = $this->cutoffHourForBranch($conn, $tenant, $branch);
        $timestamp = $now !== null && $now !== '' ? $now : date('Y-m-d H:i:s');

        return $this->businessDayForTimestamp($timestamp, $cutoffHour);
    }

    /**
     * Half-open window [start_at, end_at) for a business day label.
     *
     * @return array{start_at: string, end_at: string, cutoff_hour: int}
     */
    public function windowBounds(string $businessDay, int $cutoffHour): array
    {
        $cutoffHour = $this->normalizeCutoffHour($cutoffHour);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDay)) {
            throw new InvalidArgumentException('BUSINESS_DAY_DATE_INVALID');
        }

        $start = strtotime(sprintf('%s %02d:00:00', $businessDay, $cutoffHour));
        if ($start === false) {
            throw new InvalidArgumentException('BUSINESS_DAY_DATE_INVALID');
        }

        $end = strtotime('+1 day', $start);
        if ($end === false) {
            throw new InvalidArgumentException('BUSINESS_DAY_DATE_INVALID');
        }

        return [
            'start_at' => date('Y-m-d H:i:s', $start),
            'end_at' => date('Y-m-d H:i:s', $end),
            'cutoff_hour' => $cutoffHour,
        ];
    }

    public function previousBusinessDay(string $businessDay): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDay)) {
            throw new InvalidArgumentException('BUSINESS_DAY_DATE_INVALID');
        }

        $parsed = strtotime($businessDay . ' 12:00:00');
        if ($parsed === false) {
            throw new InvalidArgumentException('BUSINESS_DAY_DATE_INVALID');
        }

        return date('Y-m-d', strtotime('-1 day', $parsed));
    }

    public function timestampBusinessDayExpression(string $timestampExpr, string $settingsAlias = 'pbs'): string
    {
        return "DATE(DATE_SUB({$timestampExpr}, INTERVAL COALESCE({$settingsAlias}.business_day_cutoff_hour, "
            . self::DEFAULT_CUTOFF_HOUR . ') HOUR))';
    }

    public function sessionBusinessDayExpression(string $sessionAlias = 'ds', string $settingsAlias = 'pbs'): string
    {
        return $this->timestampBusinessDayExpression("{$sessionAlias}.opened_at", $settingsAlias);
    }

    public function movementBusinessDayExpression(string $movementAlias = 'dm', string $settingsAlias = 'pbs'): string
    {
        return $this->timestampBusinessDayExpression("{$movementAlias}.created_at", $settingsAlias);
    }

    public function orderBusinessDayExpression(string $orderAlias = 'oh', string $settingsAlias = 'pbs'): string
    {
        // pro_date is already the stamped business day for POS orders; keep expression for timestamp sources.
        return "DATE({$orderAlias}.pro_date)";
    }

    public function orderTimestampBusinessDayExpression(string $orderAlias = 'oh', string $settingsAlias = 'pbs'): string
    {
        $timestampExpr = "COALESCE({$orderAlias}.payment_date, {$orderAlias}.crtime, CONCAT({$orderAlias}.pro_date, ' 12:00:00'))";

        return $this->timestampBusinessDayExpression($timestampExpr, $settingsAlias);
    }

    public function branchSettingsJoin(string $sessionAlias, string $settingsAlias = 'pbs'): string
    {
        return "LEFT JOIN pos_branch_settings {$settingsAlias} ON {$settingsAlias}.pos_tenant = {$sessionAlias}.tenant"
            . " AND {$settingsAlias}.pos_branch = {$sessionAlias}.branch";
    }

    public function branchSettingsJoinForMovement(string $movementAlias, string $settingsAlias = 'pbs'): string
    {
        return "LEFT JOIN pos_branch_settings {$settingsAlias} ON {$settingsAlias}.pos_tenant = {$movementAlias}.tenant"
            . " AND {$settingsAlias}.pos_branch = {$movementAlias}.branch";
    }

    public function branchSettingsJoinForOrder(
        string $orderAlias = 'oh',
        string $settingsAlias = 'pbs',
        string $tenantColumn = 'pos_tenant',
        string $branchColumn = 'pos_branch'
    ): string {
        return "LEFT JOIN pos_branch_settings {$settingsAlias} ON {$settingsAlias}.pos_tenant = {$orderAlias}.{$tenantColumn}"
            . " AND {$settingsAlias}.pos_branch = {$orderAlias}.{$branchColumn}";
    }

    public function normalizeCutoffHour(int $hour): int
    {
        if ($hour < 0) {
            return 0;
        }
        if ($hour > 23) {
            return 23;
        }

        return $hour;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
