<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class StockReservationRepository extends RecipeRepositoryBase
{
    private const STATUSES = ['reserved', 'consumed', 'released', 'expired'];

    public function createReservation(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'fat_detail_id' => null,
            'order_line_uuid' => null,
            'recipe_order_line_usage_id' => null,
            'recipe_id' => null,
            'status' => 'reserved',
            'expires_at' => null,
            'consumed_at' => null,
            'released_at' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['status'] = trim((string) ($row['status'] ?? ''));
        $this->assertValidReservation($row);
        $row['qty_reserved'] = RecipeDecimal::normalize($row['qty_reserved']);

        return $this->insertRow($conn, 'stock_reservations', $row);
    }

    public function findActiveForOrderLine(mysqli $conn, int $orderId, ?int $fatDetailId, ?string $orderLineUuid): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM stock_reservations
WHERE order_id = ?
  AND (? IS NULL OR fat_detail_id = ?)
  AND (? IS NULL OR order_line_uuid = ?)
  AND status = 'reserved'
ORDER BY id
FOR UPDATE",
            [$orderId, $fatDetailId, $fatDetailId, $orderLineUuid, $orderLineUuid]
        );
    }

    public function findActiveForUsageIds(mysqli $conn, array $usageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $usageIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$ids) {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM stock_reservations
WHERE recipe_order_line_usage_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
  AND status = 'reserved'
ORDER BY id
FOR UPDATE",
            $ids
        );
    }

    public function findByIdempotencyKey(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        int $storeId,
        string $idempotencyKey
    ): ?array {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM stock_reservations
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $idempotencyKey]
        );
    }

    public function findExpiredReserved(mysqli $conn, string $now, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM stock_reservations
WHERE status = 'reserved'
  AND expires_at IS NOT NULL
  AND expires_at <= ?
ORDER BY expires_at ASC, id ASC
LIMIT {$limit}
FOR UPDATE",
            [$now]
        );
    }

    public function updateStatus(mysqli $conn, int $reservationId, string $status): int
    {
        if ($reservationId < 1) {
            throw new InvalidArgumentException('Stock reservation id must be positive.');
        }
        $status = trim($status);
        $this->assertValidStatus($status);

        $timestampColumn = null;
        if ($status === 'consumed') {
            $timestampColumn = 'consumed_at';
        } elseif ($status === 'released') {
            $timestampColumn = 'released_at';
        }

        if ($timestampColumn) {
            return $this->executeStatement(
                $conn,
                "UPDATE stock_reservations SET status = ?, {$timestampColumn} = CURRENT_TIMESTAMP WHERE id = ?",
                [$status, $reservationId]
            );
        }

        return $this->executeStatement($conn, 'UPDATE stock_reservations SET status = ? WHERE id = ?', [$status, $reservationId]);
    }

    private function assertValidReservation(array $row): void
    {
        if (trim((string) ($row['reservation_uuid'] ?? '')) === '') {
            throw new InvalidArgumentException('Stock reservation UUID is required.');
        }
        if (trim((string) ($row['idempotency_key'] ?? '')) === '') {
            throw new InvalidArgumentException('Stock reservation idempotency key is required.');
        }

        $this->assertValidStatus((string) ($row['status'] ?? ''));

        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Stock reservation ' . $field . ' cannot be negative.');
            }
        }

        foreach (['order_id', 'sellable_item_id', 'ingredient_item_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Stock reservation ' . $field . ' must be positive.');
            }
        }

        foreach (['fat_detail_id', 'recipe_order_line_usage_id', 'recipe_id'] as $field) {
            if ($row[$field] !== null && (int) $row[$field] < 1) {
                throw new InvalidArgumentException('Stock reservation ' . $field . ' must be positive when provided.');
            }
        }

        $this->assertDecimal($row['qty_reserved'] ?? null, 'qty_reserved');
        if (RecipeDecimal::compare($row['qty_reserved'], '0') <= 0) {
            throw new InvalidArgumentException('Stock reservation qty_reserved must be positive.');
        }
    }

    private function assertValidStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Stock reservation status is invalid.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Stock reservation ' . $field . ' must be a decimal value.');
        }
    }
}
