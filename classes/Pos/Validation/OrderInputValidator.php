<?php

require_once __DIR__ . '/TableInputValidator.php';

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
        $data['total'] = TableInputValidator::decimal($data['total'] ?? 0, 'إجمالي الطلب غير صحيح', true);
        $data['discount'] = TableInputValidator::decimal($data['discount'] ?? 0, 'قيمة الخصم غير صحيحة', true);
        $data['net'] = TableInputValidator::decimal($data['net'] ?? max(0, $data['total'] - $data['discount']), 'صافي الطلب غير صحيح', true);

        if ($data['discount'] > $data['total']) {
            throw new InvalidArgumentException('قيمة الخصم أكبر من إجمالي الطلب');
        }

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
            $qty = TableInputValidator::decimal($item['qty'] ?? 0, 'كمية الصنف غير صحيحة');
            $price = TableInputValidator::decimal($item['price'] ?? 0, 'سعر الصنف غير صحيح', true);
            $discount = TableInputValidator::decimal($item['discount'] ?? 0, 'خصم الصنف غير صحيح', true);

            $item['id'] = $itemId;
            $item['item_id'] = $itemId;
            $item['qty'] = $qty;
            $item['price'] = $price;
            $item['discount'] = $discount;
            $normalized[] = $item;
        }

        return $normalized;
    }
}
