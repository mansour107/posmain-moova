<?php

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
            'det_value' => 0.0,
            'profit' => 0.0,
            'qty_in' => 0.0,
            'qty_out' => 0.0,
        ];

        foreach ($lines as $line) {
            $detail = $this->normalizeInvoiceLine($conn, $invoiceType, $line, $context);
            $normalized[] = $detail;
            $totals['det_value'] += $detail['det_value'];
            $totals['profit'] += $detail['profit'];
            $totals['qty_in'] += $detail['qty_in'];
            $totals['qty_out'] += $detail['qty_out'];
        }

        return [
            'lines' => $normalized,
            'totals' => $totals,
        ];
    }

    public function normalizeInvoiceLine(mysqli $conn, int $invoiceType, array $line, array $context = []): array
    {
        $itemId = $this->lineItemId($line);
        $qty = $this->positiveFloat($line, ['qty', 'itmqty'], 'ITEM_QTY_INVALID');
        $price = $this->numericValue($line, ['price', 'itmprice'], 'ITEM_PRICE_REQUIRED');
        $discount = $this->numericValue($line, ['discount', 'itmdisc'], null, 0.0);
        $unitValue = $this->numericValue($line, ['u_val', 'unit_value'], null, 1.0);
        if ($unitValue <= 0) {
            $unitValue = 1.0;
        }

        $item = $this->loadItem($conn, $itemId);
        $movement = $this->movementQuantities($invoiceType, $qty, $unitValue);
        $detValue = $qty * ($price - $discount);
        $unitPrice = $price / $unitValue;
        $oldCost = (float) ($item['cost_price'] ?? 0);
        $oldQty = (float) ($item['itmqty'] ?? 0);
        $costPrice = $oldCost;
        $profit = 0.0;
        $itemUpdate = null;

        if (in_array($invoiceType, [self::TYPE_PURCHASE, self::TYPE_PURCHASE_ORDER], true)) {
            $newBalance = $movement['qty_in'] * $unitPrice;
            $totalQty = $oldQty + $movement['qty_in'];
            if ($totalQty > 0) {
                $costPrice = (($oldCost * $oldQty) + $newBalance) / $totalQty;
            }
            $itemUpdate = [
                'last_price' => $unitPrice,
                'cost_price' => $costPrice,
            ];
        } elseif (in_array($invoiceType, [self::TYPE_SALES, self::TYPE_POS, self::TYPE_OFFER], true)) {
            $profit = $qty * $unitValue * ($unitPrice - $oldCost);
        }

        return [
            'item_id' => $itemId,
            'u_val' => $unitValue,
            'qty_in' => $movement['qty_in'],
            'qty_out' => $movement['qty_out'],
            'price' => $unitPrice,
            'discount' => $discount,
            'det_value' => $detValue,
            'det_store' => (int) ($line['store_id'] ?? $context['store_id'] ?? 0),
            'cost_price' => $costPrice,
            'profit' => $profit,
            'item_update' => $itemUpdate,
            'stock_affects' => $movement['stock_affects'],
        ];
    }

    private function movementQuantities(int $invoiceType, float $qty, float $unitValue): array
    {
        if (in_array($invoiceType, [self::TYPE_PURCHASE_ORDER, self::TYPE_SALES_ORDER, self::TYPE_OFFER], true)) {
            return ['qty_in' => 0.0, 'qty_out' => 0.0, 'stock_affects' => false];
        }

        if (in_array($invoiceType, [self::TYPE_PURCHASE, self::TYPE_SALES_RETURN], true)) {
            return ['qty_in' => $qty * $unitValue, 'qty_out' => 0.0, 'stock_affects' => true];
        }

        if (in_array($invoiceType, [self::TYPE_SALES, self::TYPE_POS, self::TYPE_PURCHASE_RETURN], true)) {
            return ['qty_in' => 0.0, 'qty_out' => $qty * $unitValue, 'stock_affects' => true];
        }

        return ['qty_in' => 0.0, 'qty_out' => 0.0, 'stock_affects' => false];
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

    private function positiveFloat(array $line, array $keys, string $code): float
    {
        $value = $this->numericValue($line, $keys, $code);
        if ($value <= 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function numericValue(array $line, array $keys, ?string $missingCode, ?float $default = null): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $line) && $line[$key] !== '' && $line[$key] !== null) {
                return (float) $line[$key];
            }
        }

        if ($default !== null) {
            return $default;
        }

        throw new InvalidArgumentException($missingCode ?: 'NUMERIC_VALUE_REQUIRED');
    }
}
