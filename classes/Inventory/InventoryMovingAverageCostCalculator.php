<?php

declare(strict_types=1);

require_once __DIR__ . '/InventoryDecimal.php';

/**
 * Pure exact-decimal moving-average policy shared by live ledger writes and
 * chronological balance rebuilds.
 */
final class InventoryMovingAverageCostCalculator
{
    private const COST_BEARING_INBOUND_TYPES = [
        'purchase',
        'opening_balance',
        'transfer_in',
        'production_output',
        'refund_reversal',
        'adjustment',
    ];

    public static function nextAverageCost(
        string $oldOnHand,
        string $oldAverageCost,
        string $movementType,
        string $qtyIn,
        string $qtyOut,
        string $unitCost
    ): string {
        $oldOnHand = InventoryDecimal::normalize($oldOnHand);
        $oldAverageCost = InventoryDecimal::normalize($oldAverageCost);
        $qtyIn = InventoryDecimal::normalize($qtyIn);
        $qtyOut = InventoryDecimal::normalize($qtyOut);
        $unitCost = InventoryDecimal::normalize($unitCost);
        $movementType = strtolower(trim($movementType));

        if (InventoryDecimal::isPositive($qtyIn)) {
            if (!in_array($movementType, self::COST_BEARING_INBOUND_TYPES, true)) {
                return $oldAverageCost;
            }
            if (InventoryDecimal::compare($oldOnHand, '0') <= 0) {
                return $unitCost;
            }
            $totalQty = InventoryDecimal::add($oldOnHand, $qtyIn);
            if (InventoryDecimal::compare($totalQty, '0') <= 0) {
                return $unitCost;
            }
            $oldValue = InventoryDecimal::multiply($oldOnHand, $oldAverageCost);
            $newValue = InventoryDecimal::multiply($qtyIn, $unitCost);

            return InventoryDecimal::divide(InventoryDecimal::add($oldValue, $newValue), $totalQty);
        }

        // Outbound stock normally retains its existing moving-average cost. If
        // the ledger starts below zero, preserve the sale-time cost evidence
        // rather than leaving a zero-cost negative balance.
        if (InventoryDecimal::isPositive($qtyOut)
            && InventoryDecimal::compare($oldAverageCost, '0') === 0
            && InventoryDecimal::compare($unitCost, '0') > 0) {
            return $unitCost;
        }

        return $oldAverageCost;
    }
}
