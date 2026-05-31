<?php

class InventoryOperationalHardeningService
{
    private const RETRYABLE_MYSQL_CODES = [1205, 1213];
    private const RETRYABLE_SQLSTATES = ['40001', '41000'];

    public function pagination(array $input, int $defaultLimit = 100, int $maxLimit = 500): array
    {
        $maxLimit = max(1, $maxLimit);
        $limit = (int) ($input['limit'] ?? $defaultLimit);
        $offset = (int) ($input['offset'] ?? 0);

        return [
            'limit' => max(1, min($maxLimit, $limit)),
            'offset' => max(0, $offset),
        ];
    }

    public function isRetryableDatabaseException(Throwable $exception): bool
    {
        $code = (int) $exception->getCode();
        if (in_array($code, self::RETRYABLE_MYSQL_CODES, true)) {
            return true;
        }

        if ($exception instanceof mysqli_sql_exception) {
            $sqlState = method_exists($exception, 'getSqlState') ? (string) $exception->getSqlState() : '';
            return in_array($sqlState, self::RETRYABLE_SQLSTATES, true);
        }

        return false;
    }

    public function runWithRetry(callable $operation, array $options = [])
    {
        $attempts = max(1, min(5, (int) ($options['attempts'] ?? 3)));
        $sleepMicros = max(0, min(250000, (int) ($options['sleep_micros'] ?? 50000)));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $operation($attempt);
            } catch (Throwable $exception) {
                $last = $exception;
                if ($attempt >= $attempts || !$this->isRetryableDatabaseException($exception)) {
                    throw $exception;
                }
                if ($sleepMicros > 0) {
                    usleep($sleepMicros * $attempt);
                }
            }
        }

        throw $last ?: new RuntimeException('Inventory retry operation failed.');
    }

    public function operatorMessage(string $code, array $context = []): string
    {
        $code = strtolower(trim($code));
        $item = trim((string) ($context['item_name'] ?? $context['item'] ?? ''));
        $store = trim((string) ($context['store_name'] ?? $context['store'] ?? ''));
        $qty = trim((string) ($context['qty'] ?? ''));

        if ($code === 'stock_unavailable') {
            if ($item !== '' && $store !== '' && $qty !== '') {
                return $item . ' stock is ' . $qty . ' in ' . $store . '.';
            }
            if ($item !== '' && $store !== '') {
                return $item . ' is not available in ' . $store . '.';
            }

            return 'Selected item is not available in this store.';
        }

        if ($code === 'retry_later') {
            return 'Inventory is busy. Please try again.';
        }

        if ($code === 'permission_denied') {
            return 'You do not have permission to perform this inventory action.';
        }

        return 'Inventory action could not be completed.';
    }

    public function requiredIndexes(): array
    {
        return [
            'inventory_movements' => [
                'uq_inventory_idempotency',
                'idx_inventory_item_time',
                'idx_inventory_source',
                'idx_inventory_movement_type_time',
            ],
            'inventory_item_balances' => [
                'uq_inventory_balance_item',
                'idx_inventory_balance_available',
            ],
            'recipe_availability_cache' => [
                'uq_recipe_availability_item',
                'idx_recipe_availability_available',
                'idx_recipe_availability_revision',
            ],
        ];
    }
}
