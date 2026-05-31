<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';

class InventoryBalanceRepository extends RecipeRepositoryBase
{
    public function putBalance(mysqli $conn, array $data): int
    {
        $data = array_merge([
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
            'moving_average_cost' => '0.000000',
            'last_movement_id' => null,
        ], $data);

        $sql = "
INSERT INTO inventory_item_balances
  (pos_tenant, pos_branch, branch_uuid, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost, last_movement_id)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  id = LAST_INSERT_ID(id),
  branch_uuid = VALUES(branch_uuid),
  qty_on_hand = VALUES(qty_on_hand),
  qty_reserved = VALUES(qty_reserved),
  qty_available = VALUES(qty_available),
  moving_average_cost = VALUES(moving_average_cost),
  last_movement_id = VALUES(last_movement_id)";

        $this->executeStatement($conn, $sql, [
            (int) $data['pos_tenant'],
            (int) $data['pos_branch'],
            $data['branch_uuid'],
            (int) $data['store_id'],
            (int) $data['item_id'],
            (string) $data['qty_on_hand'],
            (string) $data['qty_reserved'],
            (string) $data['qty_available'],
            (string) $data['moving_average_cost'],
            $data['last_movement_id'],
        ]);

        return (int) $conn->insert_id;
    }

    public function findBalance(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId): ?array
    {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $itemId]
        );
    }
}
