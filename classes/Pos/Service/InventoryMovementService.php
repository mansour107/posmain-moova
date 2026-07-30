<?php

require_once __DIR__ . '/../../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../../Items/ItemUnitResolver.php';
require_once __DIR__ . '/../../Items/ItemUnitConversion.php';
require_once __DIR__ . '/../../Items/ItemUnitConversionFeatureFlags.php';
require_once __DIR__ . '/../../Financial/Money.php';

class InventoryMovementService
{
    const TYPE_SALES = 3;
    const TYPE_PURCHASE = 4;
    const TYPE_POS = 9;
    const TYPE_PURCHASE_RETURN = 10;
    const TYPE_SALES_RETURN = 11;
    const TYPE_PURCHASE_ORDER = 12;
    const TYPE_SALES_ORDER = 13;
    const TYPE_OFFER = 14;

    public function normalizeInvoiceLines(mysqli $conn, int $invoiceType, array $lines, array $context = []): array
    {
        $normalized = [];
        $totals = [
            'det_value' => Money::zero()->toString(),
            'profit' => Money::zero()->toString(),
            'qty_in' => RecipeDecimal::zero(ItemUnitConversion::INVENTORY_SCALE),
            'qty_out' => RecipeDecimal::zero(ItemUnitConversion::INVENTORY_SCALE),
        ];

        foreach ($lines as $line) {
            $detail = $this->normalizeInvoiceLine($conn, $invoiceType, $line, $context);
            $normalized[] = $detail;
            $totals['det_value'] = RecipeDecimal::add($totals['det_value'], $detail['det_value'], Money::SCALE);
            $totals['profit'] = RecipeDecimal::add($totals['profit'], $detail['profit'], Money::SCALE);
            $totals['qty_in'] = RecipeDecimal::add($totals['qty_in'], $detail['qty_in'], ItemUnitConversion::INVENTORY_SCALE);
            $totals['qty_out'] = RecipeDecimal::add($totals['qty_out'], $detail['qty_out'], ItemUnitConversion::INVENTORY_SCALE);
        }

        return [
            'lines' => $normalized,
            'totals' => $totals,
        ];
    }

    public function normalizeInvoiceLine(mysqli $conn, int $invoiceType, array $line, array $context = []): array
    {
        $scale = ItemUnitConversion::INVENTORY_SCALE;
        $itemId = $this->lineItemId($line);
        $qty = $this->positiveDecimal($line, ['qty', 'itmqty'], 'ITEM_QTY_INVALID', $scale);
        $price = $this->decimalValue($line, ['price', 'itmprice'], 'ITEM_PRICE_REQUIRED', $scale);
        $discount = $this->decimalValue($line, ['discount', 'itmdisc'], null, $scale, '0');
        $unitId = (int) ($line['unit_id'] ?? $line['unitid'] ?? 0);
        $resolved = ItemUnitResolver::resolvePosStockFactor(
            $conn,
            $itemId,
            $unitId > 0 ? $unitId : null,
            $line['u_val'] ?? $line['unit_value'] ?? null
        );
        $unitValueDecimal = $resolved['factor_decimal'];
        if (RecipeDecimal::compare($unitValueDecimal, '0', $scale) <= 0) {
            $unitValueDecimal = RecipeDecimal::normalize('1', $scale);
        }
        $unitValue = RecipeDecimal::normalize($unitValueDecimal, ItemUnitConversion::DISPLAY_SCALE);

        $item = $this->loadItem($conn, $itemId);
        $movement = $this->movementQuantities($invoiceType, $qty, $unitValueDecimal, $resolved['unit_row'], $scale);
        $detValue = RecipeDecimal::multiply(
            $qty,
            RecipeDecimal::subtract($price, $discount, $scale),
            Money::SCALE
        );
        $unitPrice = RecipeDecimal::divide($price, $unitValueDecimal, $scale);
        $oldCost = RecipeDecimal::normalize($item['cost_price'] ?? '0', $scale);
        $oldQty = RecipeDecimal::normalize($item['itmqty'] ?? '0', $scale);
        $costPrice = $oldCost;
        $profit = Money::zero()->toString();
        $itemUpdate = null;

        if (in_array($invoiceType, [self::TYPE_PURCHASE, self::TYPE_PURCHASE_ORDER], true)) {
            $newBalance = RecipeDecimal::multiply($movement['qty_in_decimal'], $unitPrice, $scale);
            $totalQty = RecipeDecimal::add($oldQty, $movement['qty_in_decimal'], $scale);
            if (RecipeDecimal::compare($totalQty, '0', $scale) > 0) {
                $existingBalance = RecipeDecimal::multiply($oldCost, $oldQty, $scale);
                $costPrice = RecipeDecimal::divide(
                    RecipeDecimal::add($existingBalance, $newBalance, $scale),
                    $totalQty,
                    $scale
                );
            }
            $itemUpdate = [
                'last_price' => $unitPrice,
                'cost_price' => $costPrice,
            ];
        } elseif (in_array($invoiceType, [self::TYPE_SALES, self::TYPE_POS, self::TYPE_OFFER], true)) {
            $stockQty = $movement['qty_out_decimal'] !== '0'
                ? $movement['qty_out_decimal']
                : $movement['qty_in_decimal'];
            $profit = RecipeDecimal::multiply(
                $stockQty,
                RecipeDecimal::subtract($unitPrice, $oldCost, $scale),
                Money::SCALE
            );
        }

        return [
            'item_id' => $itemId,
            'u_val' => $unitValue,
            'u_val_decimal' => RecipeDecimal::normalize($unitValueDecimal, ItemUnitConversion::DISPLAY_SCALE),
            'qty_in' => $movement['qty_in_decimal'],
            'qty_out' => $movement['qty_out_decimal'],
            'price' => $unitPrice,
            'discount' => $discount,
            'det_value' => $detValue,
            'det_store' => (int) ($line['store_id'] ?? $context['store_id'] ?? 0),
            'cost_price' => $costPrice,
            'profit' => $profit,
            'item_update' => $itemUpdate,
            'stock_affects' => $movement['stock_affects'],
            '_source_item' => $line,
        ];
    }

    private function movementQuantities(
        int $invoiceType,
        string $qty,
        string $unitValueDecimal,
        ?array $unitRow,
        int $scale
    ): array {
        if (in_array($invoiceType, [self::TYPE_PURCHASE_ORDER, self::TYPE_SALES_ORDER, self::TYPE_OFFER], true)) {
            return [
                'qty_in_decimal' => RecipeDecimal::normalize('0', $scale),
                'qty_out_decimal' => RecipeDecimal::normalize('0', $scale),
                'stock_affects' => false,
            ];
        }

        $role = in_array($invoiceType, [self::TYPE_PURCHASE, self::TYPE_SALES_RETURN], true) ? 'purchase' : 'sell';
        $stored = (string) ($unitRow['u_val'] ?? '1');
        $swapped = (int) ($unitRow['conversion_swapped'] ?? 0) === 1;
        $useExact = ItemUnitConversionFeatureFlags::exactDecimalConversions() && $unitRow !== null;

        if ($useExact) {
            $stockQty = ItemUnitConversion::stockQuantityFromEnteredQty($qty, $stored, $swapped, $role, $scale);
        } else {
            $stockQty = RecipeDecimal::multiply($qty, $unitValueDecimal, $scale);
        }

        if (in_array($invoiceType, [self::TYPE_PURCHASE, self::TYPE_SALES_RETURN], true)) {
            return [
                'qty_in_decimal' => $stockQty,
                'qty_out_decimal' => RecipeDecimal::normalize('0', $scale),
                'stock_affects' => true,
            ];
        }

        if (in_array($invoiceType, [self::TYPE_SALES, self::TYPE_POS, self::TYPE_PURCHASE_RETURN], true)) {
            return [
                'qty_in_decimal' => RecipeDecimal::normalize('0', $scale),
                'qty_out_decimal' => $stockQty,
                'stock_affects' => true,
            ];
        }

        return [
            'qty_in_decimal' => RecipeDecimal::normalize('0', $scale),
            'qty_out_decimal' => RecipeDecimal::normalize('0', $scale),
            'stock_affects' => false,
        ];
    }

    private function loadItem(mysqli $conn, int $itemId): array
    {
        $stmt = $conn->prepare("SELECT id, cost_price, itmqty FROM myitems WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new InvalidArgumentException('ITEM_NOT_FOUND');
        }

        return $item;
    }

    private function lineItemId(array $line): int
    {
        $itemId = (int) ($line['item_id'] ?? $line['itmname'] ?? $line['id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('ITEM_ID_REQUIRED');
        }

        return $itemId;
    }

    private function positiveDecimal(array $line, array $keys, string $code, int $scale): string
    {
        $value = $this->decimalValue($line, $keys, $code, $scale);
        if (RecipeDecimal::compare($value, '0', $scale) <= 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function decimalValue(array $line, array $keys, ?string $missingCode, int $scale, ?string $default = null): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $line) && $line[$key] !== '' && $line[$key] !== null) {
                return RecipeDecimal::normalize($line[$key], $scale);
            }
        }

        if ($default !== null) {
            return RecipeDecimal::normalize($default, $scale);
        }

        throw new InvalidArgumentException($missingCode ?: 'NUMERIC_VALUE_REQUIRED');
    }
}
