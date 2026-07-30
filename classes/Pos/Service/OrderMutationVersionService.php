<?php

final class OrderMutationVersionService
{
    public function columnExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM ot_head LIKE 'mutation_version'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function lockAndAssert(
        mysqli $conn,
        int $orderId,
        $expectedVersion,
        bool $requireExpected = true
    ): int {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }
        if (!$this->columnExists($conn)) {
            throw new RuntimeException('MUTATION_VERSION_SCHEMA_REQUIRED');
        }

        /*
         * Acquire the row lock before reading the version. A single locking
         * SELECT can return the version chosen before its lock wait; two
         * terminals released from the same blocker could therefore both see
         * the old version. The no-op UPDATE waits for the current row first,
         * and the following locking read observes the committed winner.
         */
        $lock = $conn->prepare(
            'UPDATE ot_head
             SET mutation_version = GREATEST(1, COALESCE(mutation_version, 0))
             WHERE id = ?'
        );
        $lock->bind_param('i', $orderId);
        $lock->execute();
        $lock->close();

        $stmt = $conn->prepare(
            'SELECT mutation_version FROM ot_head WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('ORDER_NOT_FOUND');
        }

        $current = max(1, (int) ($row['mutation_version'] ?? 1));
        $expected = $this->normalizeExpected($expectedVersion);
        if ($expected === null) {
            if ($requireExpected) {
                throw new InvalidArgumentException('MUTATION_VERSION_REQUIRED');
            }

            return $current;
        }
        if ($expected !== $current) {
            throw new RuntimeException('STALE_ORDER_VERSION');
        }

        return $current;
    }

    public function current(mysqli $conn, int $orderId): int
    {
        if ($orderId < 1 || !$this->columnExists($conn)) {
            return 0;
        }

        $stmt = $conn->prepare('SELECT mutation_version FROM ot_head WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? max(1, (int) ($row['mutation_version'] ?? 1)) : 0;
    }

    public function bumpAndGet(mysqli $conn, int $orderId, $expectedVersion = null): int
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }
        if (!$this->columnExists($conn)) {
            throw new RuntimeException('MUTATION_VERSION_SCHEMA_REQUIRED');
        }

        $expected = $this->normalizeExpected($expectedVersion);
        if ($expected !== null) {
            $stmt = $conn->prepare(
                'UPDATE ot_head
                 SET mutation_version = mutation_version + 1
                 WHERE id = ? AND mutation_version = ?'
            );
            $stmt->bind_param('ii', $orderId, $expected);
        } else {
            $stmt = $conn->prepare(
                'UPDATE ot_head SET mutation_version = mutation_version + 1 WHERE id = ?'
            );
            $stmt->bind_param('i', $orderId);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            if ($this->current($conn, $orderId) < 1) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }
            if ($expected !== null) {
                throw new RuntimeException('STALE_ORDER_VERSION');
            }
            throw new RuntimeException('ORDER_VERSION_BUMP_FAILED');
        }

        return $expected !== null ? $expected + 1 : $this->current($conn, $orderId);
    }

    private function normalizeExpected($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('MUTATION_VERSION_INVALID');
        }

        return (int) $value;
    }
}
