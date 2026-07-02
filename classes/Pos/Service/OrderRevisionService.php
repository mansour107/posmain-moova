<?php

class OrderRevisionService
{
    public function columnExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM ot_head LIKE 'kitchen_revision'");

        return $result && $result->num_rows > 0;
    }

    public function currentRevision(mysqli $conn, int $orderId): int
    {
        if ($orderId < 1) {
            return 0;
        }

        if (!$this->columnExists($conn)) {
            return 0;
        }

        $stmt = $conn->prepare('SELECT kitchen_revision FROM ot_head WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['kitchen_revision'] ?? 0);
    }

    public function bumpAndGet(mysqli $conn, int $orderId): int
    {
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        if (!$this->columnExists($conn)) {
            return 1;
        }

        $stmt = $conn->prepare('UPDATE ot_head SET kitchen_revision = kitchen_revision + 1 WHERE id = ?');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stmt->close();

        return $this->currentRevision($conn, $orderId);
    }
}
