<?php

final class NegativeStockSalePolicyService
{
    public const BLOCK = 'block';
    public const ALLOW_WITH_WARNING = 'allow_with_warning';

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (isset($GLOBALS['appConfig']) && is_array($GLOBALS['appConfig']) ? $GLOBALS['appConfig'] : []);
    }

    public function resolve(mysqli $conn): string
    {
        if ($this->columnExists($conn, 'settings', 'negative_stock_sale_policy')) {
            $result = $conn->query("SELECT negative_stock_sale_policy FROM settings ORDER BY id ASC LIMIT 1");
            $saved = trim((string) (($result ? $result->fetch_assoc()['negative_stock_sale_policy'] : '') ?? ''));
            if (in_array($saved, [self::BLOCK, self::ALLOW_WITH_WARNING], true)) {
                return $saved;
            }
        }

        $strict = !empty($this->config['recipe']['strict_stock']) || !empty($this->config['inventory']['strict_stock']);
        if ($strict) {
            return self::BLOCK;
        }
        if (!empty($this->config['recipe']['allow_negative_stock_with_approval'])) {
            return self::ALLOW_WITH_WARNING;
        }

        return self::BLOCK;
    }

    public function normalize($value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, [self::BLOCK, self::ALLOW_WITH_WARNING], true)) {
            throw new InvalidArgumentException('NEGATIVE_STOCK_SALE_POLICY_INVALID');
        }

        return $value;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $tableEscaped = $conn->real_escape_string($table);
        $columnEscaped = $conn->real_escape_string($column);
        $tables = $conn->query("SHOW TABLES LIKE '{$tableEscaped}'");
        if (!$tables instanceof mysqli_result || $tables->num_rows < 1) {
            return false;
        }
        $result = $conn->query("SHOW COLUMNS FROM `{$tableEscaped}` LIKE '{$columnEscaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
