<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeAvailabilityCacheRepository extends RecipeRepositoryBase
{
    private const ORDER_TYPES = ['any', 'dine_in', 'takeaway', 'delivery'];
    private const CHANNELS = ['any', 'pos', 'table', 'moova', 'cofe', 'api'];

    public function putAvailability(mysqli $conn, array $data): int
    {
        $data = array_merge([
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'recipe_id' => null,
            'order_type' => 'any',
            'channel' => 'any',
            'computed_available_qty' => '0.000000',
            'effective_available_qty' => '0.000000',
            'effective_is_available' => 1,
            'blocking_item_id' => null,
            'unavailable_reason' => null,
            'availability_revision' => 1,
            'calculated_at' => date('Y-m-d H:i:s'),
        ], $data);
        $data['order_type'] = trim((string) ($data['order_type'] ?? ''));
        $data['channel'] = trim((string) ($data['channel'] ?? ''));
        $this->assertValidAvailability($data);
        $data['computed_available_qty'] = RecipeDecimal::normalize($data['computed_available_qty']);
        $data['effective_available_qty'] = RecipeDecimal::normalize($data['effective_available_qty']);

        $sql = "
INSERT INTO recipe_availability_cache
  (pos_tenant, pos_branch, branch_uuid, store_id, sellable_item_id, recipe_id, order_type, channel,
   computed_available_qty, effective_available_qty, effective_is_available, blocking_item_id,
   unavailable_reason, availability_revision, calculated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  branch_uuid = VALUES(branch_uuid),
  recipe_id = VALUES(recipe_id),
  computed_available_qty = VALUES(computed_available_qty),
  effective_available_qty = VALUES(effective_available_qty),
  effective_is_available = VALUES(effective_is_available),
  blocking_item_id = VALUES(blocking_item_id),
  unavailable_reason = VALUES(unavailable_reason),
  availability_revision = VALUES(availability_revision),
  calculated_at = VALUES(calculated_at)";

        $this->executeStatement($conn, $sql, [
            (int) $data['pos_tenant'],
            (int) $data['pos_branch'],
            $data['branch_uuid'],
            (int) $data['store_id'],
            (int) $data['sellable_item_id'],
            $data['recipe_id'],
            (string) $data['order_type'],
            (string) $data['channel'],
            (string) $data['computed_available_qty'],
            (string) $data['effective_available_qty'],
            (int) $data['effective_is_available'],
            $data['blocking_item_id'],
            $data['unavailable_reason'],
            (int) $data['availability_revision'],
            (string) $data['calculated_at'],
        ]);

        return (int) $conn->insert_id;
    }

    public function findForItem(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        int $storeId,
        int $sellableItemId,
        string $orderType,
        string $channel
    ): ?array {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM recipe_availability_cache
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND sellable_item_id = ?
  AND order_type = ?
  AND channel = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $sellableItemId, $orderType, $channel]
        );
    }

    public function findBestForMenu(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        int $storeId,
        int $sellableItemId,
        string $orderType,
        string $channel
    ): ?array {
        $candidates = [
            [$orderType, $channel],
            [$orderType, 'any'],
            ['any', $channel],
            ['any', 'any'],
        ];

        foreach ($candidates as $candidate) {
            $row = $this->findForItem(
                $conn,
                $posTenant,
                $posBranch,
                $storeId,
                $sellableItemId,
                $candidate[0],
                $candidate[1]
            );
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private function assertValidAvailability(array $data): void
    {
        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $field) {
            if ((int) ($data[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Recipe availability cache ' . $field . ' cannot be negative.');
            }
        }
        if ((int) ($data['sellable_item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Recipe availability cache sellable_item_id must be positive.');
        }
        foreach (['recipe_id', 'blocking_item_id'] as $field) {
            if ($data[$field] !== null && $data[$field] !== '' && (int) $data[$field] < 1) {
                throw new InvalidArgumentException('Recipe availability cache ' . $field . ' must be positive when provided.');
            }
        }
        if (!in_array((string) ($data['order_type'] ?? ''), self::ORDER_TYPES, true)) {
            throw new InvalidArgumentException('Recipe availability cache order_type is invalid.');
        }
        if (!in_array((string) ($data['channel'] ?? ''), self::CHANNELS, true)) {
            throw new InvalidArgumentException('Recipe availability cache channel is invalid.');
        }
        foreach (['computed_available_qty', 'effective_available_qty'] as $field) {
            $this->assertDecimal($data[$field] ?? null, $field);
            if (RecipeDecimal::compare($data[$field], '0') < 0) {
                throw new InvalidArgumentException('Recipe availability cache ' . $field . ' cannot be negative.');
            }
        }
        $effectiveFlag = $data['effective_is_available'] ?? null;
        if (is_bool($effectiveFlag)) {
            $effectiveFlag = $effectiveFlag ? '1' : '0';
        } else {
            $effectiveFlag = trim((string) $effectiveFlag);
        }
        if (!in_array($effectiveFlag, ['0', '1'], true)) {
            throw new InvalidArgumentException('Recipe availability cache effective_is_available must be 0 or 1.');
        }
        if ((int) ($data['availability_revision'] ?? 0) < 1) {
            throw new InvalidArgumentException('Recipe availability cache availability_revision must be positive.');
        }
        if (trim((string) ($data['calculated_at'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe availability cache calculated_at is required.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Recipe availability cache ' . $field . ' must be a decimal value.');
        }
    }
}
