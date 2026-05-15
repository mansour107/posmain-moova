<?php

require_once __DIR__ . '/TableInputValidator.php';

class PaymentInputValidator
{
    public static function validateTablePayment(array $data): array
    {
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'بيانات غير صحيحة');
        $data['order_id'] = TableInputValidator::optionalPositiveInt($data['order_id'] ?? 0, 'معرف الطلب غير صحيح');
        $data['discount'] = TableInputValidator::optionalDecimal($data['discount'] ?? null, 'قيمة الخصم غير صحيحة');
        $data['net'] = TableInputValidator::optionalDecimal($data['net'] ?? null, 'صافي الطلب غير صحيح');
        $data['paid'] = TableInputValidator::decimal($data['paid'] ?? $data['amount_paid'] ?? 0, 'بيانات غير صحيحة');
        $data['payment_method'] = self::paymentMethod($data['payment_method'] ?? 'cash');
        $data['notes'] = self::notes($data['notes'] ?? '');

        return $data;
    }

    public static function validateSplitPayment(array $data): array
    {
        $data['order_id'] = TableInputValidator::positiveInt($data['order_id'] ?? $data['original_order_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['items'] = self::splitItems($data['items'] ?? null);
        $data['paid_amount'] = TableInputValidator::decimal($data['paid_amount'] ?? $data['paid'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['payment_method'] = self::paymentMethod($data['payment_method'] ?? 'cash');

        return $data;
    }

    public static function paymentMethod($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'cash';
        }

        $allowed = ['cash', 'card', 'bank', 'visa', 'wallet', 'pos', 'كاش', 'صرافة', 'بطاقة'];
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('طريقة الدفع غير صحيحة');
        }

        return $value;
    }

    private static function splitItems($items): array
    {
        if (!is_array($items) || !$items) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $detailId = TableInputValidator::positiveInt($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
                $normalizedItem = $item;
                $normalizedItem['detail_id'] = $detailId;
                if (array_key_exists('qty', $normalizedItem) || array_key_exists('quantity', $normalizedItem)) {
                    $normalizedItem['qty'] = TableInputValidator::decimal($normalizedItem['qty'] ?? $normalizedItem['quantity'] ?? 0, 'كمية الصنف المختارة غير صحيحة');
                }
                $normalized[] = $normalizedItem;
                continue;
            }

            $normalized[] = TableInputValidator::positiveInt($item, 'بيانات السداد المقسم غير صحيحة');
        }

        return $normalized;
    }

    private static function notes($value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);

        return substr(trim((string) $value), 0, 255);
    }
}
