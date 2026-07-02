<?php

require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/ItemUnitColumnSupport.php';
require_once __DIR__ . '/ItemUnitResolver.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

final class ErpUnitConversionAuditService
{
    public function classifyItemUnits(mysqli $conn, int $limit = 500): array
    {
        $hasSwap = ItemUnitColumnSupport::hasConversionSwapped($conn);
        $swapSelect = $hasSwap ? 'iu.conversion_swapped' : '0 AS conversion_swapped';
        $stmt = $conn->prepare("
            SELECT iu.id, iu.item_id, iu.unit_id, iu.u_val, {$swapSelect},
                   iu.def_sale, iu.def_buy, m.iname
            FROM item_units iu
            INNER JOIN myitems m ON m.id = iu.item_id
            WHERE COALESCE(iu.isdeleted, 0) = 0
            ORDER BY iu.id ASC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $summary = [
            'display_factor' => 0,
            'legacy_inverse_sell' => 0,
            'swapped_display' => 0,
            'ambiguous' => 0,
        ];
        $classified = [];

        foreach ($rows as $row) {
            $classification = $this->classifyRow($row, $hasSwap);
            $summary[$classification] = ($summary[$classification] ?? 0) + 1;
            $resolved = ItemUnitConversion::inventoryFactorFromRow(
                $row,
                (int) ($row['def_buy'] ?? 0) === 1 ? 'purchase' : 'sell',
                $hasSwap
            );
            $classified[] = [
                'unit_row_id' => (int) $row['id'],
                'item_id' => (int) $row['item_id'],
                'item_name' => (string) $row['iname'],
                'stored_u_val' => (string) $row['u_val'],
                'classification' => $classification,
                'resolved_inventory_factor' => $resolved,
            ];
        }

        return [
            'summary' => $summary,
            'rows' => $classified,
        ];
    }

    public function findRawFactorMovementMismatches(mysqli $conn, int $limit = 200): array
    {
        $stmt = $conn->prepare("
            SELECT fd.id, fd.fatid, fd.item_id, fd.u_val, fd.qty_in, fd.qty_out, fd.fat_tybe
            FROM fat_details fd
            WHERE COALESCE(fd.isdeleted, 0) = 0
              AND fd.fat_tybe IN (3, 4, 9, 10, 11)
              AND fd.qty_out > 0
            ORDER BY fd.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $mismatches = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $expected = ItemUnitResolver::sellToStockFactorDecimal($conn, $itemId);
            $stored = RecipeDecimal::normalize($row['u_val'] ?? '1', ItemUnitConversion::DISPLAY_SCALE);
            $expectedNorm = RecipeDecimal::normalize($expected, ItemUnitConversion::DISPLAY_SCALE);
            if (RecipeDecimal::compare($stored, $expectedNorm, ItemUnitConversion::DISPLAY_SCALE) !== 0) {
                $mismatches[] = [
                    'detail_id' => (int) $row['id'],
                    'order_id' => (int) $row['fatid'],
                    'item_id' => $itemId,
                    'stored_u_val' => $stored,
                    'expected_u_val' => $expectedNorm,
                    'qty_out' => (string) $row['qty_out'],
                ];
            }
        }

        return $mismatches;
    }

    private function classifyRow(array $row, bool $hasSwap): string
    {
        $stored = RecipeDecimal::normalize($row['u_val'] ?? '1', ItemUnitConversion::DISPLAY_SCALE);
        $swapped = $hasSwap && (int) ($row['conversion_swapped'] ?? 0) === 1;

        if ($swapped) {
            return 'swapped_display';
        }

        if ((int) ($row['def_sale'] ?? 0) === 1 && RecipeDecimal::compare($stored, '1', ItemUnitConversion::DISPLAY_SCALE) < 0) {
            return 'legacy_inverse_sell';
        }

        if (RecipeDecimal::compare($stored, '1', ItemUnitConversion::DISPLAY_SCALE) >= 0) {
            return 'display_factor';
        }

        return 'ambiguous';
    }
}
