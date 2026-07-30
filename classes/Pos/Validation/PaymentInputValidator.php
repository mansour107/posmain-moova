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
        $data['notes'] = self::notes($data['notes'] ?? '');
        $data['reference_no'] = self::notes($data['reference_no'] ?? $data['notes'] ?? '');
        $data['tenders'] = self::tenders($data, 'بيانات غير صحيحة');
        $data['paid'] = self::sumTenderAmounts($data['tenders']);
        $data['payment_method'] = count($data['tenders']) === 1
            ? $data['tenders'][0]['payment_method']
            : 'mixed';
        $data['payment_method_id'] = $data['payment_method'];

        return $data;
    }

    public static function validateSplitPayment(array $data): array
    {
        $data['order_id'] = TableInputValidator::positiveInt($data['order_id'] ?? $data['original_order_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['table_id'] = TableInputValidator::positiveInt($data['table_id'] ?? 0, 'بيانات السداد المقسم غير صحيحة');
        $data['items'] = self::splitItems($data['items'] ?? null);
        $data['notes'] = self::notes($data['notes'] ?? '');
        $data['reference_no'] = self::notes($data['reference_no'] ?? $data['notes'] ?? '');
        $data['tenders'] = self::tenders($data, 'بيانات السداد المقسم غير صحيحة');
        $data['paid_amount'] = self::sumTenderAmounts($data['tenders']);
        $data['payment_method'] = count($data['tenders']) === 1
            ? $data['tenders'][0]['payment_method']
            : 'mixed';

        return $data;
    }

    private static function tenders(array $data, string $message): array
    {
        $rawTenders = $data['tenders'] ?? null;
        if ($rawTenders === null || $rawTenders === []) {
            $method = self::paymentMethod($data['payment_method_id'] ?? $data['payment_method'] ?? 'cash');
            if ($method === 'mixed') {
                throw new InvalidArgumentException($message);
            }

            return [[
                'payment_method' => $method,
                'amount' => self::money($data['paid_amount'] ?? $data['paid'] ?? $data['amount_paid'] ?? 0, $message),
                'reference_no' => self::notes($data['reference_no'] ?? $data['notes'] ?? ''),
            ]];
        }
        if (!is_array($rawTenders) || count($rawTenders) > 8) {
            throw new InvalidArgumentException($message);
        }

        $normalized = [];
        foreach ($rawTenders as $rawTender) {
            if (!is_array($rawTender)) {
                throw new InvalidArgumentException($message);
            }
            $normalized[] = [
                'payment_method' => self::paymentMethod(
                    $rawTender['payment_method_id']
                    ?? $rawTender['payment_method']
                    ?? $rawTender['method']
                    ?? ''
                ),
                'amount' => self::money(
                    $rawTender['amount'] ?? $rawTender['paid'] ?? $rawTender['tendered_amount'] ?? 0,
                    $message
                ),
                'reference_no' => self::notes(
                    $rawTender['reference_no']
                    ?? $rawTender['reference']
                    ?? $data['reference_no']
                    ?? $data['notes']
                    ?? ''
                ),
            ];
        }
        if ($normalized === []) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private static function sumTenderAmounts(array $tenders): string
    {
        $total = Money::zero();
        foreach ($tenders as $tender) {
            $total = $total->add(Money::from((string) ($tender['amount'] ?? '0')));
        }

        return $total->toString();
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
