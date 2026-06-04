<?php

require_once __DIR__ . '/RecipeEditorItemCostService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once dirname(__DIR__) . '/Inventory/InventoryDecimal.php';

class RecipeInventoryDisplayCostService
{
    private $itemCosts;

    public function __construct(?RecipeEditorItemCostService $itemCosts = null)
    {
        $this->itemCosts = $itemCosts ?: new RecipeEditorItemCostService();
    }

    public function enrichBalanceReportRows(mysqli $conn, array $rows, array $filters): array
    {
        if (!$rows) {
            return $rows;
        }

        $context = $this->previewContextFromFilters($filters);
        foreach ($rows as &$row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }

            $resolved = $this->itemCosts->resolveInventoryUnitCost($conn, $itemId, $context);
            if ($resolved === null) {
                continue;
            }

            $unitCost = (string) $resolved['unit_cost'];
            $qtyOnHand = InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
            $row['moving_average_cost'] = $unitCost;
            $row['recipe_cost_source'] = (string) $resolved['cost_source'];
            $row['recipe_calculated_cost'] = (string) $resolved['calculated_cost'];

            if (array_key_exists('stock_value', $row)) {
                $row['stock_value'] = InventoryDecimal::multiply($qtyOnHand, $unitCost);
            }
            if (array_key_exists('current_stock_value', $row)) {
                $row['current_stock_value'] = InventoryDecimal::multiply($qtyOnHand, $unitCost);
            }
            if (array_key_exists('estimated_purchase_cost', $row) && array_key_exists('suggested_purchase_base_qty', $row)) {
                $row['estimated_purchase_cost'] = InventoryDecimal::multiply(
                    (string) ($row['suggested_purchase_base_qty'] ?? '0'),
                    $unitCost
                );
            }
        }
        unset($row);

        return $rows;
    }

    private function previewContextFromFilters(array $filters): array
    {
        return [
            'pos_tenant' => max(0, (int) ($filters['pos_tenant'] ?? 0)),
            'pos_branch' => max(0, (int) ($filters['pos_branch'] ?? 0)),
            'store_id' => max(0, (int) ($filters['store_id'] ?? 0)),
            'order_type' => 'any',
            'channel' => 'any',
        ];
    }
}
