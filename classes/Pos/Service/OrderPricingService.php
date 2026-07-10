<?php

require_once __DIR__ . '/../../Financial/FinancialPricingService.php';

class OrderPricingService
{
    public function resolveTableSaveRequest(mysqli $conn, array $data, array $context = []): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        $allowMismatch = !empty($context['allow_price_mismatch'])
            || !empty($data['manager_approval_id'])
            || !empty($data['price_override_approval_id']);

        $resolvedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('بيانات الأصناف غير صحيحة');
            }

            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($itemId < 1) {
                throw new InvalidArgumentException('معرف الصنف أو الكمية غير صحيحة');
            }

            $catalog = $this->loadCatalogPrice($conn, $itemId);
            $canonicalPrice = UnitPrice::fromLegacy($catalog['price1'] ?? '0')->toString();
            $submittedPrice = UnitPrice::fromLegacy($item['price'] ?? '0')->toString();
            if (FinancialDecimal::compare($canonicalPrice, '0', UnitPrice::SCALE) <= 0) {
                $canonicalPrice = $submittedPrice;
            }

            if (
                !$allowMismatch
                && FinancialDecimal::compare($submittedPrice, '0', UnitPrice::SCALE) > 0
                && FinancialDecimal::compare($submittedPrice, $canonicalPrice, UnitPrice::SCALE) !== 0
            ) {
                throw new InvalidArgumentException('PRICE_MISMATCH');
            }

            $price = FinancialDecimal::compare($canonicalPrice, '0', UnitPrice::SCALE) > 0 ? $canonicalPrice : $submittedPrice;

            $item['id'] = $itemId;
            $item['item_id'] = $itemId;
            $item['qty'] = DecimalQuantity::fromLegacy($item['qty'] ?? '0')->toString();
            $item['price'] = $price;
            $item['discount'] = UnitPrice::fromLegacy($item['discount'] ?? '0')->toString();
            $item['catalog_price'] = $canonicalPrice;
            $resolvedItems[] = $item;
        }

        $pricing = (new FinancialPricingService())->price(
            $resolvedItems,
            Money::fromLegacy($data['discount'] ?? '0')->toString(),
            [
                'rate' => Money::fromLegacy($data['tax_rate'] ?? '0')->toString(),
                'inclusive' => !empty($data['tax_inclusive']),
            ]
        );
        $resolvedTotal = $pricing['totals']['gross'];
        $resolvedNet = $pricing['totals']['net'];

        $data['items'] = $pricing['lines'];
        $data['total'] = $resolvedTotal;
        $data['net'] = $resolvedNet;
        $data['discount'] = $pricing['totals']['discount'];
        $data['taxable_amount'] = $pricing['totals']['taxable'];
        $data['tax_amount'] = $pricing['totals']['tax'];
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
