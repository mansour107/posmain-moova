<?php

require_once __DIR__ . '/TableInputValidator.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Financial/DecimalQuantity.php';
require_once __DIR__ . '/../../Financial/UnitPrice.php';

class OrderInputValidator
{
    public static function validateTableSave(array $data): array
    {
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'الرجاء اختيار طاولة');
        $data['order_id'] = TableInputValidator::optionalPositiveInt($data['order_id'] ?? 0, 'معرف الطلب غير صحيح');
        $data['order_date'] = TableInputValidator::dateOrToday($data['order_date'] ?? '');
        $data['store_id'] = TableInputValidator::positiveInt($data['store_id'] ?? 0, 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $data['emp_id'] = TableInputValidator::positiveInt($data['emp_id'] ?? 0, 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $data['fund_id'] = TableInputValidator::positiveInt($data['fund_id'] ?? 0, 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $data['items'] = self::items($data['items'] ?? null);
        $data['total'] = self::money($data['total'] ?? '0', 'إجمالي الطلب غير صحيح');
        $data['discount'] = self::money($data['discount'] ?? '0', 'قيمة الخصم غير صحيحة');
        $data['net'] = self::money($data['net'] ?? '0', 'صافي الطلب غير صحيح');

        return $data;
    }

    private static function items($items): array
    {
        if (!is_array($items) || !$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('بيانات الأصناف غير صحيحة');
            }

            $itemId = TableInputValidator::positiveInt($item['id'] ?? $item['item_id'] ?? 0, 'معرف الصنف غير صحيح');
            try {
                $qty = DecimalQuantity::from($item['qty'] ?? '0')->toString();
                $price = UnitPrice::from($item['price'] ?? '0')->toString();
                $discount = UnitPrice::from($item['discount'] ?? '0')->toString();
            } catch (Throwable $exception) {
                throw new InvalidArgumentException('بيانات سعر أو كمية الصنف غير صحيحة');
            }
            if (FinancialDecimal::compare($qty, '0', DecimalQuantity::SCALE) <= 0) {
                throw new InvalidArgumentException('كمية الصنف غير صحيحة');
            }

            $item['id'] = $itemId;
            $item['item_id'] = $itemId;
            $item['qty'] = $qty;
            $item['price'] = $price;
            $item['discount'] = $discount;
            $normalized[] = $item;
        }

        return $normalized;
    }

    private static function money($value, string $message): string
    {
        try {
            return Money::from($value)->toString();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException($message);
        }
    }
}
