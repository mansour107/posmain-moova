<?php

require_once __DIR__ . '/ItemUnitColumnSupport.php';
require_once __DIR__ . '/ItemInventoryUnitSync.php';
require_once __DIR__ . '/ItemUnitConversion.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../Financial/UnitPrice.php';
require_once __DIR__ . '/../Financial/Decimal.php';

final class ItemUnitPersistence
{
    public static function saveForItem(mysqli $conn, int $itemId, array $units, int $purchaseUnitId = 0): void
    {
        if ($itemId < 1 || !$units) {
            throw new InvalidArgumentException('missing item units');
        }

        ItemUnitColumnSupport::ensureDefFlags($conn);
        ItemUnitColumnSupport::ensureUValPrecision($conn);
        ItemUnitColumnSupport::ensureConversionSwapped($conn);
        $hasDefFlags = ItemUnitColumnSupport::hasDefFlags($conn);
        $hasConversionSwapped = ItemUnitColumnSupport::hasConversionSwapped($conn);

        $submittedUnitIds = [];
        if ($hasDefFlags && $hasConversionSwapped) {
            $updateSql = 'UPDATE item_units SET cost_price = ?, price1 = ?, price2 = ?, price3 = ?, u_val = ?, unit_barcode = ?, def_sale = ?, def_buy = ?, def_stock = ?, conversion_swapped = ? WHERE item_id = ? AND unit_id = ?';
            $insertSql = 'INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3, def_sale, def_buy, def_stock, conversion_swapped) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        } elseif ($hasDefFlags) {
            $updateSql = 'UPDATE item_units SET cost_price = ?, price1 = ?, price2 = ?, price3 = ?, u_val = ?, unit_barcode = ?, def_sale = ?, def_buy = ?, def_stock = ? WHERE item_id = ? AND unit_id = ?';
            $insertSql = 'INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3, def_sale, def_buy, def_stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        } else {
            $updateSql = 'UPDATE item_units SET cost_price = ?, price1 = ?, price2 = ?, price3 = ?, u_val = ?, unit_barcode = ? WHERE item_id = ? AND unit_id = ?';
            $insertSql = 'INSERT INTO item_units(item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        }

        $updateStmt = $conn->prepare($updateSql);
        $insertStmt = $conn->prepare($insertSql);

        foreach ($units as $unit) {
            $unitId = (int) ($unit['unit_id'] ?? 0);
            if ($unitId < 1) {
                continue;
            }
            $submittedUnitIds[] = $unitId;

            $uVal = FinancialDecimal::normalize(
                $unit['u_val'] ?? '1',
                ItemUnitConversion::DISPLAY_SCALE
            );
            $unitBarcode = mb_substr((string) ($unit['unit_barcode'] ?? ''), 0, 20);
            $costPrice = UnitPrice::from($unit['cost_price'] ?? '0')->toString();
            $price1 = UnitPrice::from($unit['price1'] ?? '0')->toString();
            $price2 = UnitPrice::from($unit['price2'] ?? '0')->toString();
            $price3 = UnitPrice::from($unit['price3'] ?? '0')->toString();
            $defSale = (int) ($unit['def_sale'] ?? 0);
            $defBuy = (int) ($unit['def_buy'] ?? 0);
            $defStock = (int) ($unit['def_stock'] ?? 0);
            $conversionSwapped = (int) ($unit['conversion_swapped'] ?? 0);

            if ($hasDefFlags && $hasConversionSwapped) {
                $updateStmt->bind_param(
                    'ssssssiiiiii',
                    $costPrice,
                    $price1,
                    $price2,
                    $price3,
                    $uVal,
                    $unitBarcode,
                    $defSale,
                    $defBuy,
                    $defStock,
                    $conversionSwapped,
                    $itemId,
                    $unitId
                );
            } elseif ($hasDefFlags) {
                $updateStmt->bind_param(
                    'ssssssiiiii',
                    $costPrice,
                    $price1,
                    $price2,
                    $price3,
                    $uVal,
                    $unitBarcode,
                    $defSale,
                    $defBuy,
                    $defStock,
                    $itemId,
                    $unitId
                );
            } else {
                $updateStmt->bind_param('ssssssii', $costPrice, $price1, $price2, $price3, $uVal, $unitBarcode, $itemId, $unitId);
            }
            $updateStmt->execute();

            if ($updateStmt->affected_rows > 0 || self::unitExists($conn, $itemId, $unitId)) {
                continue;
            }

            if ($hasDefFlags && $hasConversionSwapped) {
                $insertStmt->bind_param(
                    'iissssssiiii',
                    $itemId,
                    $unitId,
                    $uVal,
                    $unitBarcode,
                    $costPrice,
                    $price1,
                    $price2,
                    $price3,
                    $defSale,
                    $defBuy,
                    $defStock,
                    $conversionSwapped
                );
            } elseif ($hasDefFlags) {
                $insertStmt->bind_param(
                    'iissssssiii',
                    $itemId,
                    $unitId,
                    $uVal,
                    $unitBarcode,
                    $costPrice,
                    $price1,
                    $price2,
                    $price3,
                    $defSale,
                    $defBuy,
                    $defStock
                );
            } else {
                $insertStmt->bind_param('iissssss', $itemId, $unitId, $uVal, $unitBarcode, $costPrice, $price1, $price2, $price3);
            }
            $insertStmt->execute();
        }

        $updateStmt->close();
        $insertStmt->close();

        $submittedUnitIds = array_values(array_unique(array_filter($submittedUnitIds)));
        if (!$submittedUnitIds) {
            throw new InvalidArgumentException('missing item units');
        }

        $placeholders = implode(',', array_fill(0, count($submittedUnitIds), '?'));
        $types = 'i' . str_repeat('i', count($submittedUnitIds));
        $params = array_merge([$itemId], $submittedUnitIds);
        $deleteStmt = $conn->prepare("DELETE FROM item_units WHERE item_id = ? AND unit_id NOT IN ({$placeholders})");
        self::bindParams($deleteStmt, $types, $params);
        $deleteStmt->execute();
        $deleteStmt->close();

        if ($purchaseUnitId > 0) {
            ItemInventoryUnitSync::syncPurchaseUnitPreference($conn, $itemId, $purchaseUnitId);
        }
    }

    private static function unitExists(mysqli $conn, int $itemId, int $unitId): bool
    {
        $stmt = $conn->prepare('SELECT id FROM item_units WHERE item_id = ? AND unit_id = ? LIMIT 1');
        $stmt->bind_param('ii', $itemId, $unitId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row);
    }

    private static function bindParams(mysqli_stmt $stmt, string $types, array $params): void
    {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }

        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
}
