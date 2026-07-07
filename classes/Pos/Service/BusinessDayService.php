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

    public function sessionBusinessDayExpression(string $sessionAlias = 'ds', string $settingsAlias = 'pbs'): string
    {
        return "DATE(DATE_SUB({$sessionAlias}.opened_at, INTERVAL COALESCE({$settingsAlias}.business_day_cutoff_hour, "
            . self::DEFAULT_CUTOFF_HOUR . ') HOUR))';
    }

    public function movementBusinessDayExpression(string $movementAlias = 'dm', string $settingsAlias = 'pbs'): string
    {
        return "DATE(DATE_SUB({$movementAlias}.created_at, INTERVAL COALESCE({$settingsAlias}.business_day_cutoff_hour, "
            . self::DEFAULT_CUTOFF_HOUR . ') HOUR))';
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
