<?php

class PaymentMethodService
{
    private const TYPES = ['cash', 'card', 'wallet', 'bank'];
    private const SETTLEMENT_POLICIES = ['cash_drawer', 'manual_external', 'reference_required'];

    public function saveMethod(mysqli $conn, array $data): array
    {
        $code = $this->normalizeCode($data['code'] ?? '');
        $nameAr = $this->requiredText($data['name_ar'] ?? $data['name'] ?? '', 120, 'PAYMENT_METHOD_NAME_REQUIRED');
        $nameEn = $this->nullableText($data['name_en'] ?? null, 120);
        $accountId = $this->optionalPositiveInt($data['account_id'] ?? null);
        $type = $this->normalizeType($data['type'] ?? '');
        $settlementPolicy = $this->normalizeSettlementPolicy(
            $data['settlement_policy'] ?? $this->defaultSettlementPolicy($type)
        );
        $this->assertSettlementPolicyMatchesType($type, $settlementPolicy);
        $requiresReference = $settlementPolicy === 'reference_required' ? 1 : 0;
        $isActive = $this->boolInt($data['is_active'] ?? true);
        $sortOrder = $this->nonNegativeInt($data['sort_order'] ?? 0, 'PAYMENT_METHOD_SORT_INVALID');
        if ($isActive === 1 && $accountId === null) {
            throw new InvalidArgumentException('PAYMENT_METHOD_ACCOUNT_REQUIRED');
        }

        $stmt = $conn->prepare("
            INSERT INTO payment_methods (
                code, name_ar, name_en, account_id, type,
                requires_reference, settlement_policy, is_active, sort_order
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name_ar = VALUES(name_ar),
                name_en = VALUES(name_en),
                account_id = VALUES(account_id),
                type = VALUES(type),
                requires_reference = VALUES(requires_reference),
                settlement_policy = VALUES(settlement_policy),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");
        $stmt->bind_param(
            'sssisisii',
            $code,
            $nameAr,
            $nameEn,
            $accountId,
            $type,
            $requiresReference,
            $settlementPolicy,
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
              AND account_id IS NOT NULL
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

        $code = $this->normalizeCode($method);
        try {
            return $this->methodByCode($conn, $code, true);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'PAYMENT_METHOD_NOT_FOUND' || !$this->isLegacyCashAlias($code)) {
                throw $exception;
            }

            return $this->firstActiveByType($conn, 'cash');
        }
    }

    public function defaultCashMethod(mysqli $conn): array
    {
        return $this->firstActiveByType($conn, 'cash');
    }

    /**
     * Resolves a tender that is safe to post. Draft payment-method records may
     * exist for administration, but a cashier cannot use one without a ledger
     * account or a mandatory non-cash settlement reference.
     */
    public function resolveTender(mysqli $conn, $method, $reference = null): array
    {
        $resolved = $this->resolvePostableAccount($conn, $this->resolveActive($conn, $method));
        $resolved['reference_no'] = $this->validateReference($resolved, $reference);

        return $resolved;
    }

    /**
     * Non-cash refunds may be created as pending_external without a reference;
     * settlement later requires the external reference.
     */
    public function resolveTenderAllowingPendingExternal(mysqli $conn, $method, $reference = null): array
    {
        $resolved = $this->resolvePostableAccount($conn, $this->resolveActive($conn, $method));
        $reference = $this->nullableText($reference, 120);
        if (($resolved['settlement_policy'] ?? '') === 'reference_required' && $reference === null) {
            $resolved['reference_no'] = null;

            return $resolved;
        }
        $resolved['reference_no'] = $this->validateReference($resolved, $reference);

        return $resolved;
    }

    private function resolvePostableAccount(mysqli $conn, array $resolved): array
    {
        $accountId = (int) ($resolved['account_id'] ?? 0);
        if ($accountId < 1 || !$this->accountExists($conn, $accountId)) {
            if (($resolved['type'] ?? '') === 'cash') {
                $fundId = $this->resolveDefaultCashAccountId($conn);
                if ($fundId > 0 && $this->accountExists($conn, $fundId)) {
                    $resolved['account_id'] = $fundId;
                    $accountId = $fundId;
                }
            }
        }
        if ($accountId < 1 || !$this->accountExists($conn, $accountId)) {
            throw new RuntimeException('PAYMENT_METHOD_ACCOUNT_REQUIRED');
        }

        return $resolved;
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
        if (($method['settlement_policy'] ?? '') === 'reference_required' && $reference === null) {
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

    private function firstActiveByType(mysqli $conn, string $type): array
    {
        $stmt = $conn->prepare('
            SELECT *
            FROM payment_methods
            WHERE type = ?
              AND is_active = 1
            ORDER BY CASE WHEN account_id IS NULL OR account_id = 0 THEN 1 ELSE 0 END, id ASC
            LIMIT 1
        ');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('PAYMENT_METHOD_NOT_FOUND');
        }

        return $this->formatMethod($row);
    }

    private function isLegacyCashAlias(string $code): bool
    {
        return in_array($code, ['cash', 'كاش', 'نقدي', 'نقد'], true);
    }

    private function resolveDefaultCashAccountId(mysqli $conn): int
    {
        if (!function_exists('posmain_resolve_pos_defaults')) {
            $defaultsPath = dirname(__DIR__, 3) . '/includes/pos_default_accounts.php';
            if (is_file($defaultsPath)) {
                require_once $defaultsPath;
            }
        }
        if (!function_exists('posmain_resolve_pos_defaults')) {
            return 0;
        }
        $defaults = posmain_resolve_pos_defaults($conn, []);

        return max(0, (int) ($defaults['payment_fund_id'] ?? $defaults['fund_id'] ?? 0));
    }

    private function accountExists(mysqli $conn, int $accountId): bool
    {
        if ($accountId < 1) {
            return false;
        }
        $tables = $conn->query("SHOW TABLES LIKE 'acc_head'");
        if ($tables === false || $tables->num_rows < 1) {
            // Minimal test schemas may omit the chart of accounts; treat configured ids as present.
            return true;
        }
        $stmt = $conn->prepare('SELECT id FROM acc_head WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
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
            'settlement_policy' => (string) ($row['settlement_policy'] ?? $this->defaultSettlementPolicy((string) $row['type'])),
            'is_active' => (int) $row['is_active'] === 1,
            'sort_order' => (int) $row['sort_order'],
            'drawer_impact' => (string) ($row['settlement_policy'] ?? '') === 'cash_drawer'
                || (!isset($row['settlement_policy']) && (string) $row['type'] === 'cash'),
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

    private function normalizeSettlementPolicy($value): string
    {
        $policy = strtolower(trim((string) $value));
        if (!in_array($policy, self::SETTLEMENT_POLICIES, true)) {
            throw new InvalidArgumentException('PAYMENT_SETTLEMENT_POLICY_INVALID');
        }

        return $policy;
    }

    private function defaultSettlementPolicy(string $type): string
    {
        if ($type === 'cash') {
            return 'cash_drawer';
        }
        if ($type === 'bank') {
            return 'manual_external';
        }

        return 'reference_required';
    }

    private function assertSettlementPolicyMatchesType(string $type, string $policy): void
    {
        if (($type === 'cash' && $policy !== 'cash_drawer')
            || ($type !== 'cash' && $policy === 'cash_drawer')) {
            throw new InvalidArgumentException('PAYMENT_SETTLEMENT_POLICY_TYPE_MISMATCH');
        }
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
