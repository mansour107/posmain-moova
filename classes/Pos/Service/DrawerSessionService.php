<?php

class DrawerSessionService
{
    private const MOVEMENT_TYPES = [
        'sale_cash' => 1,
        'refund_cash' => -1,
        'paid_in' => 1,
        'paid_out' => -1,
        'safe_drop' => -1,
        'opening' => 1,
        'closing_adjustment' => 1,
    ];

    public function openSession(mysqli $conn, array $request): array
    {
        $userId = $this->positiveInt($request['user_id'] ?? 0, 'USER_ID_REQUIRED');
        $openedBy = $this->positiveInt($request['opened_by'] ?? $userId, 'OPENED_BY_REQUIRED');
        $tenant = $this->nonNegativeInt($request['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($request['branch'] ?? 0, 'BRANCH_INVALID');
        $fundAccountId = $this->optionalPositiveInt($request['fund_account_id'] ?? null);
        $openingCash = $this->decimal($request['opening_cash'] ?? '0', false, 'OPENING_CASH_INVALID');
        $notes = $this->nullableText($request['notes'] ?? null, 500);

        if ($this->findOpenSession($conn, $userId, $tenant, $branch)) {
            throw new RuntimeException('DRAWER_SESSION_ALREADY_OPEN');
        }

        $uuid = $this->uuid();
        $openedAt = $this->dateTime($request['opened_at'] ?? null);
        $stmt = $conn->prepare("
            INSERT INTO drawer_sessions (
                uuid, user_id, tenant, branch, fund_account_id, opened_at,
                opened_by, opening_cash, status, notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)
        ");
        $stmt->bind_param(
            'siiiisiss',
            $uuid,
            $userId,
            $tenant,
            $branch,
            $fundAccountId,
            $openedAt,
            $openedBy,
            $openingCash,
            $notes
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->sessionById($conn, $id);
    }

    public function recordMovement(mysqli $conn, int $sessionId, array $request): array
    {
        $session = $this->requireOpenSession($conn, $sessionId);
        $type = $this->movementType($request['movement_type'] ?? $request['type'] ?? '');
        $amount = $this->decimal($request['amount'] ?? null, true, 'DRAWER_AMOUNT_INVALID');
        $orderId = $this->optionalPositiveInt($request['order_id'] ?? null);
        $paymentId = $this->optionalPositiveInt($request['payment_id'] ?? null);
        $reason = $this->nullableText($request['reason'] ?? null, 500);
        $createdBy = $this->positiveInt($request['created_by'] ?? $session['user_id'], 'CREATED_BY_REQUIRED');

        $stmt = $conn->prepare("
            INSERT INTO drawer_movements (
                drawer_session_id, movement_type, amount, order_id,
                payment_id, reason, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issiisi', $sessionId, $type, $amount, $orderId, $paymentId, $reason, $createdBy);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->movementById($conn, $id);
    }

    public function expectedCash(mysqli $conn, int $sessionId): string
    {
        $session = $this->sessionById($conn, $sessionId);
        $expected = (float) $session['opening_cash'];
        foreach ($this->movementsForSession($conn, $sessionId) as $movement) {
            $expected += self::MOVEMENT_TYPES[$movement['movement_type']] * (float) $movement['amount'];
        }

        return $this->formatDecimal($expected);
    }

    public function closeSession(mysqli $conn, int $sessionId, array $request): array
    {
        return $this->finishSession($conn, $sessionId, $request, 'closed');
    }

    public function forceCloseSession(mysqli $conn, int $sessionId, array $request): array
    {
        return $this->finishSession($conn, $sessionId, $request, 'forced_closed');
    }

    public function sessionById(mysqli $conn, int $sessionId): array
    {
        $sessionId = $this->positiveInt($sessionId, 'DRAWER_SESSION_REQUIRED');
        $stmt = $conn->prepare("SELECT * FROM drawer_sessions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('DRAWER_SESSION_NOT_FOUND');
        }

        return $this->formatSession($row);
    }

    public function findOpenSession(mysqli $conn, int $userId, int $tenant = 0, int $branch = 0): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM drawer_sessions
            WHERE user_id = ?
              AND tenant = ?
              AND branch = ?
              AND status = 'open'
            ORDER BY opened_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('iii', $userId, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatSession($row) : null;
    }

    public function findOpenSessionForBranch(mysqli $conn, int $tenant = 0, int $branch = 0): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM drawer_sessions
            WHERE tenant = ?
              AND branch = ?
              AND status = 'open'
            ORDER BY opened_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatSession($row) : null;
    }

    public function branchHasSessions(mysqli $conn, int $tenant = 0, int $branch = 0): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM drawer_sessions
            WHERE tenant = ?
              AND branch = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    public function movementsForSession(mysqli $conn, int $sessionId): array
    {
        $sessionId = $this->positiveInt($sessionId, 'DRAWER_SESSION_REQUIRED');
        $stmt = $conn->prepare("
            SELECT *
            FROM drawer_movements
            WHERE drawer_session_id = ?
            ORDER BY created_at, id
        ");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();

        $movements = [];
        while ($row = $result->fetch_assoc()) {
            $movements[] = $this->formatMovement($row);
        }
        $stmt->close();

        return $movements;
    }

    private function finishSession(mysqli $conn, int $sessionId, array $request, string $status): array
    {
        $this->requireOpenSession($conn, $sessionId);
        $closedBy = $this->positiveInt($request['closed_by'] ?? $request['user_id'] ?? 0, 'CLOSED_BY_REQUIRED');
        $countedCash = $this->decimal($request['counted_cash'] ?? null, false, 'COUNTED_CASH_INVALID');
        $expectedCash = $this->expectedCash($conn, $sessionId);
        $difference = $this->formatDecimal((float) $countedCash - (float) $expectedCash);
        $notes = $this->nullableText($request['notes'] ?? null, 500);
        $closedAt = $this->dateTime($request['closed_at'] ?? null);

        $stmt = $conn->prepare("
            UPDATE drawer_sessions
            SET closed_at = ?,
                closed_by = ?,
                expected_cash = ?,
                counted_cash = ?,
                difference = ?,
                status = ?,
                notes = ?
            WHERE id = ?
              AND status = 'open'
        ");
        $stmt->bind_param('sisssssi', $closedAt, $closedBy, $expectedCash, $countedCash, $difference, $status, $notes, $sessionId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected !== 1) {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        return $this->sessionById($conn, $sessionId);
    }

    private function requireOpenSession(mysqli $conn, int $sessionId): array
    {
        $session = $this->sessionById($conn, $sessionId);
        if ($session['status'] !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        return $session;
    }

    private function movementById(mysqli $conn, int $movementId): array
    {
        $stmt = $conn->prepare("SELECT * FROM drawer_movements WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $movementId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('DRAWER_MOVEMENT_NOT_FOUND');
        }

        return $this->formatMovement($row);
    }

    private function formatSession(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'user_id' => (int) $row['user_id'],
            'tenant' => (int) $row['tenant'],
            'branch' => (int) $row['branch'],
            'fund_account_id' => $row['fund_account_id'] !== null ? (int) $row['fund_account_id'] : null,
            'opened_at' => (string) $row['opened_at'],
            'opened_by' => (int) $row['opened_by'],
            'opening_cash' => $this->formatDecimal($row['opening_cash']),
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'closed_by' => $row['closed_by'] !== null ? (int) $row['closed_by'] : null,
            'expected_cash' => $row['expected_cash'] !== null ? $this->formatDecimal($row['expected_cash']) : null,
            'counted_cash' => $row['counted_cash'] !== null ? $this->formatDecimal($row['counted_cash']) : null,
            'difference' => $row['difference'] !== null ? $this->formatDecimal($row['difference']) : null,
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];
    }

    private function formatMovement(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'drawer_session_id' => (int) $row['drawer_session_id'],
            'movement_type' => (string) $row['movement_type'],
            'amount' => $this->formatDecimal($row['amount']),
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'created_by' => (int) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function movementType($value): string
    {
        $type = strtolower(trim((string) $value));
        if (!array_key_exists($type, self::MOVEMENT_TYPES)) {
            throw new InvalidArgumentException('DRAWER_MOVEMENT_TYPE_INVALID');
        }

        return $type;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function dateTime($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('DATETIME_INVALID');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function decimal($value, bool $positiveOnly, string $code): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($code);
        }

        $amount = (float) $value;
        if ($positiveOnly && $amount <= 0) {
            throw new InvalidArgumentException($code);
        }
        if (!$positiveOnly && $amount < 0) {
            throw new InvalidArgumentException($code);
        }

        return $this->formatDecimal($amount);
    }

    private function formatDecimal($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function nonNegativeInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
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
}
