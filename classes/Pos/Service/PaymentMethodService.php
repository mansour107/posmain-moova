<?php

class PaymentMethodService
{
    private const TYPES = ['cash', 'card', 'wallet', 'bank', 'gift_card', 'other'];

    public function saveMethod(mysqli $conn, array $data): array
    {
        $code = $this->normalizeCode($data['code'] ?? '');
        $nameAr = $this->requiredText($data['name_ar'] ?? $data['name'] ?? '', 120, 'PAYMENT_METHOD_NAME_REQUIRED');
        $nameEn = $this->nullableText($data['name_en'] ?? null, 120);
        $accountId = $this->optionalPositiveInt($data['account_id'] ?? null);
        $type = $this->normalizeType($data['type'] ?? '');
        $requiresReference = $this->boolInt($data['requires_reference'] ?? false);
        $isActive = $this->boolInt($data['is_active'] ?? true);
        $sortOrder = $this->nonNegativeInt($data['sort_order'] ?? 0, 'PAYMENT_METHOD_SORT_INVALID');

        $stmt = $conn->prepare("
            INSERT INTO payment_methods (
                code, name_ar, name_en, account_id, type,
                requires_reference, is_active, sort_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name_ar = VALUES(name_ar),
                name_en = VALUES(name_en),
                account_id = VALUES(account_id),
                type = VALUES(type),
                requires_reference = VALUES(requires_reference),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->bind_param(
            'sssisiii',
            $code,
            $nameAr,
            $nameEn,
            $accountId,
            $type,
            $requiresReference,
            $isActive,
            $sortOrder
        );
        $stmt->execute();
        $stmt->close();

        return $this->methodByCode($conn, $code, false);
    }

    public function listActive(mysqli $conn): array
    {
        $result = $conn->query("
            SELECT *
            FROM payment_methods
            WHERE is_active = 1
            ORDER BY sort_order, name_ar, id
        ");

        $methods = [];
        while ($row = $result->fetch_assoc()) {
            $methods[] = $this->formatMethod($row);
        }

        return $methods;
    }

    public function resolveActive(mysqli $conn, $method): array
    {
        if (is_array($method)) {
            $method = $method['code'] ?? $method['id'] ?? '';
        }

        if (is_int($method) || (is_string($method) && preg_match('/^\d+$/', trim($method)))) {
            return $this->methodById($conn, (int) $method, true);
        }

        return $this->methodByCode($conn, $this->normalizeCode($method), true);
    }

    public function normalizeCode($value): string
    {
        $code = strtolower(trim((string) $value));
        $code = preg_replace('/[\s\-]+/', '_', $code);
        $code = trim((string) $code, '_');

        if ($code === '' || !preg_match('/^[a-z0-9_]{1,40}$/', $code)) {
            throw new InvalidArgumentException('PAYMENT_METHOD_CODE_INVALID');
        }

        return $code;
    }

    public function validateReference(array $method, $reference): ?string
    {
        $reference = $this->nullableText($reference, 120);
        if (!empty($method['requires_reference']) && $reference === null) {
            throw new InvalidArgumentException('PAYMENT_REFERENCE_REQUIRED');
        }

        return $reference;
    }

    private function methodByCode(mysqli $conn, string $code, bool $activeOnly): array
    {
        $sql = "
            SELECT *
            FROM payment_methods
            WHERE code = ?
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('PAYMENT_METHOD_NOT_FOUND');
        }

        return $this->formatMethod($row);
    }

    private function methodById(mysqli $conn, int $id, bool $activeOnly): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException('PAYMENT_METHOD_REQUIRED');
        }

        $sql = "
            SELECT *
            FROM payment_methods
            WHERE id = ?
        ";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('PAYMENT_METHOD_NOT_FOUND');
        }

        return $this->formatMethod($row);
    }

    private function formatMethod(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name_ar' => (string) $row['name_ar'],
            'name_en' => $row['name_en'] !== null ? (string) $row['name_en'] : null,
            'account_id' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
            'type' => (string) $row['type'],
            'requires_reference' => (int) $row['requires_reference'] === 1,
            'is_active' => (int) $row['is_active'] === 1,
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    private function normalizeType($value): string
    {
        $type = strtolower(trim((string) $value));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('PAYMENT_METHOD_TYPE_INVALID');
        }

        return $type;
    }

    private function requiredText($value, int $maxLength, string $code): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($code);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function nullableText($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function nonNegativeInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function boolInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value === 1 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
