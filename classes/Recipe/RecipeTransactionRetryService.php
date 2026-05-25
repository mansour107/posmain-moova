<?php

class RecipeTransactionRetryService
{
    private const DEFAULT_MAX_ATTEMPTS = 3;

    public function run(mysqli $conn, callable $callback, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, int $baseDelayMicros = 50000)
    {
        $maxAttempts = max(1, $maxAttempts);
        $attempt = 0;

        while (true) {
            $attempt++;
            $conn->begin_transaction();

            try {
                $result = $callback($conn, $attempt);
                $conn->commit();

                return $result;
            } catch (Throwable $exception) {
                $this->rollbackQuietly($conn);

                if ($attempt >= $maxAttempts || !$this->isRetryable($exception)) {
                    throw $exception;
                }

                $this->delay($attempt, $baseDelayMicros);
            }
        }
    }

    public function isRetryable(Throwable $exception): bool
    {
        $code = (int) $exception->getCode();
        if (in_array($code, [1205, 1213], true)) {
            return true;
        }

        if ($exception instanceof mysqli_sql_exception && method_exists($exception, 'getSqlState')) {
            $sqlState = strtoupper((string) $exception->getSqlState());
            if (in_array($sqlState, ['40001', '41000'], true)) {
                return true;
            }
        }

        $message = strtolower($exception->getMessage());

        return strpos($message, 'deadlock') !== false
            || strpos($message, 'lock wait timeout') !== false
            || strpos($message, 'try restarting transaction') !== false;
    }

    private function rollbackQuietly(mysqli $conn): void
    {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    private function delay(int $attempt, int $baseDelayMicros): void
    {
        if ($baseDelayMicros <= 0) {
            return;
        }

        usleep(min(1000000, $baseDelayMicros * $attempt));
    }
}
