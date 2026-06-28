<?php

class OrderPricingService
{
    public const DEFAULT_TOLERANCE = 0.01;

    public function resolveTableSaveRequest(mysqli $conn, array $data, array $context = []): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        $tolerance = (float) ($context['price_tolerance'] ?? self::DEFAULT_TOLERANCE);
        $allowMismatch = !empty($context['allow_price_mismatch'])
            || !empty($data['manager_approval_id'])
            || !empty($data['price_override_approval_id']);

        $resolvedItems = [];
        $lineSubtotal = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('بيانات الأصناف غير صحيحة');
            }

            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $submittedPrice = (float) ($item['price'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            if ($itemId < 1 || $qty <= 0) {
                throw new InvalidArgumentException('معرف الصنف أو الكمية غير صحيحة');
            }

            $catalog = $this->loadCatalogPrice($conn, $itemId);
            $canonicalPrice = (float) ($catalog['price1'] ?? 0);
            if ($canonicalPrice <= 0) {
                $canonicalPrice = $submittedPrice;
            }

            if (
                !$allowMismatch
                && $submittedPrice > 0
                && abs($submittedPrice - $canonicalPrice) > $tolerance
            ) {
                throw new InvalidArgumentException('PRICE_MISMATCH');
            }

            $price = $canonicalPrice > 0 ? $canonicalPrice : $submittedPrice;
            $lineTotal = max(0, ($qty * $price) - $discount);
            $lineSubtotal += $lineTotal;

            $item['id'] = $itemId;
            $item['item_id'] = $itemId;
            $item['qty'] = $qty;
            $item['price'] = $price;
            $item['discount'] = $discount;
            $item['catalog_price'] = $canonicalPrice;
            $resolvedItems[] = $item;
        }

        $discount = (float) ($data['discount'] ?? 0);
        $submittedTotal = (float) ($data['total'] ?? 0);
        $submittedNet = (float) ($data['net'] ?? max(0, $submittedTotal - $discount));
        $resolvedTotal = round($lineSubtotal, 4);
        $resolvedNet = round(max(0, $resolvedTotal - $discount), 4);

        if (!$allowMismatch) {
            if ($submittedTotal > 0 && abs($submittedTotal - $resolvedTotal) > $tolerance) {
                throw new InvalidArgumentException('TOTAL_MISMATCH');
            }
            if ($submittedNet > 0 && abs($submittedNet - $resolvedNet) > $tolerance) {
                throw new InvalidArgumentException('NET_MISMATCH');
            }
        }

        $data['items'] = $resolvedItems;
        $data['total'] = $resolvedTotal;
        $data['net'] = $resolvedNet;
        $data['discount'] = $discount;
        $data['pricing_resolved'] = true;

        return $data;
    }

    private function loadCatalogPrice(mysqli $conn, int $itemId): array
    {
        $stmt = $conn->prepare("SELECT id, price1, cost_price FROM myitems WHERE id = ? AND isdeleted = 0 LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new InvalidArgumentException('ITEM_NOT_FOUND');
        }

        return $row;
    }
}
