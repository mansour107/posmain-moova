<?php

class TableInputValidator
{
    public static function positiveInt($value, string $message): int
    {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_float($value) && floor($value) === $value) {
            $normalized = (int) $value;
        } else {
            $value = trim((string) $value);
            if ($value === '' || !preg_match('/^\d+$/', $value)) {
                throw new InvalidArgumentException($message);
            }
            $normalized = (int) $value;
        }

        if ($normalized <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    public static function optionalPositiveInt($value, string $message): int
    {
        if ($value === null) {
            return 0;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === '0') {
            return 0;
        }

        return self::positiveInt($value, $message);
    }

    public static function decimal($value, string $message, bool $allowZero = false): float
    {
        if (is_int($value) || is_float($value)) {
            $normalized = (float) $value;
        } else {
            $value = trim((string) $value);
            if ($value === '' || !preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
                throw new InvalidArgumentException($message);
            }
            $normalized = (float) $value;
        }

        if (!is_finite($normalized) || $normalized < 0 || (!$allowZero && $normalized <= 0)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    public static function optionalDecimal($value, string $message): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::decimal($value, $message, true);
    }

    public static function dateOrToday($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m-d');
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('تاريخ الطلب غير صحيح');
        }

        return $value;
    }

    public static function reason($value, string $default): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            $value = $default;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('سبب العملية مطلوب');
        }

        return substr($value, 0, 255);
    }

    public static function tableStatusAction(array $data): string
    {
        $action = isset($data['action'])
            ? trim((string) $data['action'])
            : ((int) ($data['is_occupied'] ?? 0) === 1 ? 'activate' : 'clear');

        if (!in_array($action, ['activate', 'clear'], true)) {
            throw new InvalidArgumentException('عملية غير صحيحة');
        }

        return $action;
    }

    public static function failureResponse(Throwable $exception): array
    {
        if (function_exists('posmain_exception_payload')) {
            return posmain_exception_payload(
                $exception,
                'حدث خطأ أثناء تنفيذ العملية، يرجى المحاولة مرة أخرى',
                'ERROR',
                true,
                'pos_table_endpoint'
            );
        }

        return [
            'success' => false,
            'code' => $exception instanceof InvalidArgumentException ? 'VALIDATION_FAILED' : 'ERROR',
            'message' => $exception->getMessage(),
        ];
    }
}
