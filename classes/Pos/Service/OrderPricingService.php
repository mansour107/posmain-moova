<?php

require_once __DIR__ . '/../../Financial/FinancialPricingService.php';
require_once __DIR__ . '/../../Items/ItemUnitResolver.php';
require_once __DIR__ . '/ManagerApprovalService.php';

class OrderPricingService
{
    public function resolveTableSaveRequest(mysqli $conn, array $data, array $context = []): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

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
            $unitId = (int) ($item['unit_id'] ?? $item['scanned_unit_id'] ?? 0);
            $canonicalPrice = UnitPrice::from(
                ItemUnitResolver::sellPriceForItem($conn, $itemId, $unitId > 0 ? $unitId : null)
            )->toString();
            $submittedPrice = UnitPrice::fromLegacy($item['price'] ?? '0')->toString();
            if (FinancialDecimal::compare($canonicalPrice, '0', UnitPrice::SCALE) <= 0) {
                throw new InvalidArgumentException('CATALOG_PRICE_NOT_CONFIGURED');
            }

            if (
                FinancialDecimal::compare($submittedPrice, '0', UnitPrice::SCALE) > 0
                && FinancialDecimal::compare($submittedPrice, $canonicalPrice, UnitPrice::SCALE) !== 0
            ) {
                $this->assertPriceOverrideAuthorized($conn, $data, $context);
            }

            $price = FinancialDecimal::compare($submittedPrice, $canonicalPrice, UnitPrice::SCALE) === 0
                || FinancialDecimal::compare($submittedPrice, '0', UnitPrice::SCALE) <= 0
                ? $canonicalPrice
                : $submittedPrice;

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
                'enabled' => false,
                'rate' => '0.00',
                'inclusive' => false,
            ]
        );
        $resolvedTotal = $pricing['totals']['gross'];
        $resolvedNet = $pricing['totals']['net'];

        $data['items'] = $pricing['lines'];
        $data['total'] = $resolvedTotal;
        $data['net'] = $resolvedNet;
        $data['discount'] = $pricing['totals']['discount'];
        $data['taxable_amount'] = $pricing['totals']['taxable'];
        $data['tax_rate'] = '0.00';
        $data['tax_inclusive'] = false;
        $data['tax_amount'] = '0.00';
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

    private function assertPriceOverrideAuthorized(mysqli $conn, array $data, array $context): void
    {
        if (!empty($context['allow_price_mismatch'])) {
            return;
        }

        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId > 0) {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../../Security/PermissionService.php';
            }
            if (PermissionService::forConnection($conn)->check($userId, 'pos.price.override')) {
                return;
            }
        }

        $approvalId = (int) ($data['price_override_approval_id'] ?? 0);
        if ($approvalId < 1) {
            throw new InvalidArgumentException('PRICE_MISMATCH');
        }

        (new ManagerApprovalService())->requireApprovedIfNeeded(
            $conn,
            'pos.price.override',
            'pos_order',
            (int) ($data['order_id'] ?? 0) ?: null,
            1.0,
            ['price_override_approval_id' => $approvalId],
            [
                'user_id' => $userId,
                'require_manager_approval' => true,
                'price_override_approval_id' => $approvalId,
            ]
        );
    }
}
