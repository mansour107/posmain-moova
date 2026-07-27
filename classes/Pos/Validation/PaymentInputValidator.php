<?php

require_once __DIR__ . '/TableInputValidator.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Financial/DecimalQuantity.php';

class PaymentInputValidator
{
    public static function validateTablePayment(array $data): array
    {
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'بيانات غير صحيحة');
        $data['order_id'] = TableInputValidator::optionalPositiveInt($data['order_id'] ?? 0, 'معرف الطلب غير صحيح');
        $data['discount'] = self::optionalMoney($data['discount'] ?? null, 'قيمة الخصم غير صحيحة');
        $data['net'] = self::optionalMoney($data['net'] ?? null, 'صافي الطلب غير صحيح');
        $data['paid'] = self::money($data['paid'] ?? $data['amount_paid'] ?? 0, 'بيانات غير صحيحة');
        $data['payment_method'] = self::paymentMethod($data['payment_method_id'] ?? $data['payment_method'] ?? 'cash');
        $data['payment_method_id'] = $data['payment_method'];
        $data['notes'] = self::notes($data['notes'] ?? '');
        $data['reference_no'] = self::notes($data['reference_no'] ?? $data['notes'] ?? '');

        return $data;
    }

    public static function validateSplitPayment(array $data): array
    {
        $data['order_id'] = TableInputValidator::positiveInt($data['order_id'] ?? $data['original_order_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['items'] = self::splitItems($data['items'] ?? null);
        $data['paid_amount'] = self::money($data['paid_amount'] ?? $data['paid'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['payment_method'] = self::paymentMethod($data['payment_method'] ?? 'cash');

        return $data;
    }

    public static function paymentMethod($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'cash';
        }

        if (!preg_match('/^(?:[1-9]\d*|[a-z0-9_]{1,40})$/i', $value)) {
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
                    try {
                        $quantity = DecimalQuantity::fromLegacy($normalizedItem['qty'] ?? $normalizedItem['quantity'] ?? 0);
                    } catch (Throwable $exception) {
                        throw new InvalidArgumentException('كمية الصنف المختارة غير صحيحة');
                    }
                    if (FinancialDecimal::compare($quantity->toString(), '0', DecimalQuantity::SCALE) <= 0) {
                        throw new InvalidArgumentException('كمية الصنف المختارة غير صحيحة');
                    }
                    $normalizedItem['qty'] = $quantity->toString();
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

    private static function money($value, string $message): string
    {
        try {
            $money = Money::from($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException($message);
        }
        if (!$money->isPositive()) {
            throw new InvalidArgumentException($message);
        }
        return $money->toString();
    }

    private static function optionalMoney($value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Money::from($value)->toString();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException($message);
        }
    }
}
