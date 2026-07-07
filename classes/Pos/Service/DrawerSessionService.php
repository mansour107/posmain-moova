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
        'no_sale' => 0,
    ];

    /** @var array<string, bool> */
    private static array $columnCache = [];

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

        $this->recordMovement($conn, $id, [
            'movement_type' => 'opening',
            'amount' => $openingCash,
            'created_by' => $openedBy,
            'allow_zero_amount' => true,
            'reason' => 'shift_opening',
        ]);

        return $this->sessionById($conn, $id);
    }

    public function recordMovement(mysqli $conn, int $sessionId, array $request): array
    {
        $session = $this->requireOpenSession($conn, $sessionId);
        $type = $this->movementType($request['movement_type'] ?? $request['type'] ?? '');
        $allowZeroAmount = !empty($request['allow_zero_amount']);
        $positiveOnly = $type !== 'closing_adjustment';
        $amount = $this->decimal($request['amount'] ?? null, $positiveOnly, 'DRAWER_AMOUNT_INVALID', $allowZeroAmount);
        $orderId = $this->optionalPositiveInt($request['order_id'] ?? null);
        $paymentId = $this->optionalPositiveInt($request['payment_id'] ?? null);
        $reason = $this->nullableText($request['reason'] ?? null, 500);
        $createdBy = $this->positiveInt($request['created_by'] ?? $session['user_id'], 'CREATED_BY_REQUIRED');
        $managerApprovalId = $this->optionalPositiveInt($request['manager_approval_id'] ?? null);
        $refOtHeadId = $this->optionalPositiveInt($request['ref_ot_head_id'] ?? null);
        $tenant = (int) ($session['tenant'] ?? 0);
        $branch = (int) ($session['branch'] ?? 0);

        $columns = ['drawer_session_id', 'movement_type', 'amount', 'order_id', 'payment_id', 'reason', 'created_by'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $types = 'issiisi';
        $values = [$sessionId, $type, $amount, $orderId, $paymentId, $reason, $createdBy];

        if ($this->movementColumnExists($conn, 'tenant')) {
            $columns[] = 'tenant';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $tenant;
        }
        if ($this->movementColumnExists($conn, 'branch')) {
            $columns[] = 'branch';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $branch;
        }

        if ($this->movementColumnExists($conn, 'manager_approval_id')) {
            $columns[] = 'manager_approval_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $managerApprovalId;
        }
        if ($this->movementColumnExists($conn, 'ref_ot_head_id')) {
            $columns[] = 'ref_ot_head_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $refOtHeadId;
        }

        $sql = 'INSERT INTO drawer_movements (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->movementById($conn, $id);
    }

    public function recordUnassignedMovement(mysqli $conn, array $request): ?array
    {
        if (!$this->drawerMovementsTableExists($conn)) {
            return null;
        }

        $type = $this->movementType($request['movement_type'] ?? $request['type'] ?? '');
        $allowZeroAmount = !empty($request['allow_zero_amount']);
        $amount = $this->decimal($request['amount'] ?? null, true, 'DRAWER_AMOUNT_INVALID', $allowZeroAmount);
        $orderId = $this->optionalPositiveInt($request['order_id'] ?? null);
        $paymentId = $this->optionalPositiveInt($request['payment_id'] ?? null);
        $reason = $this->nullableText($request['reason'] ?? null, 500);
        $createdBy = $this->positiveInt($request['created_by'] ?? 0, 'CREATED_BY_REQUIRED');
        $refOtHeadId = $this->optionalPositiveInt($request['ref_ot_head_id'] ?? null);
        $tenant = $this->nonNegativeInt($request['tenant'] ?? $request['pos_tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($request['branch'] ?? $request['pos_branch'] ?? 0, 'BRANCH_INVALID');
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($tenant < 1) {
                $tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
            }
            if ($branch < 1) {
                $branch = (int) ($_SESSION['pos_branch'] ?? 0);
            }
        }

        $columns = ['drawer_session_id', 'movement_type', 'amount', 'order_id', 'payment_id', 'reason', 'created_by'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $types = 'issiisi';
        $values = [null, $type, $amount, $orderId, $paymentId, $reason, $createdBy];

        if ($this->movementColumnExists($conn, 'tenant')) {
            $columns[] = 'tenant';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $tenant;
        }
        if ($this->movementColumnExists($conn, 'branch')) {
            $columns[] = 'branch';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $branch;
        }
        if ($this->movementColumnExists($conn, 'ref_ot_head_id')) {
            $columns[] = 'ref_ot_head_id';
            $placeholders[] = '?';
            $types .= 'i';
            $values[] = $refOtHeadId;
        }

        $sql = 'INSERT INTO drawer_movements (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->movementById($conn, $id);
    }

    public function linkLatestSaleMovementToVoucher(mysqli $conn, int $orderId, int $voucherId): bool
    {
        if ($orderId < 1 || $voucherId < 1 || !$this->movementColumnExists($conn, 'ref_ot_head_id')) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM drawer_movements
            WHERE order_id = ?
              AND movement_type = 'sale_cash'
              AND ref_ot_head_id IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return $this->linkMovementToVoucher($conn, (int) $row['id'], $voucherId);
    }

    public function linkMovementToVoucher(mysqli $conn, int $movementId, int $voucherId): bool
    {
        if ($movementId < 1 || $voucherId < 1 || !$this->movementColumnExists($conn, 'ref_ot_head_id')) {
            return false;
        }

        $update = $conn->prepare('UPDATE drawer_movements SET ref_ot_head_id = ? WHERE id = ?');
        $update->bind_param('ii', $voucherId, $movementId);
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();

        return $affected === 1;
    }

    public function sessionCashBreakdown(mysqli $conn, int $sessionId): array
    {
        $session = $this->sessionById($conn, $sessionId);
        $movements = $this->movementsForSession($conn, $sessionId);
        $hasOpeningMovement = false;
        foreach ($movements as $movement) {
            if (($movement['movement_type'] ?? '') === 'opening') {
                $hasOpeningMovement = true;
                break;
            }
        }

        $preCloseExpected = $hasOpeningMovement ? 0.0 : (float) $session['opening_cash'];
        $closeVariance = 0.0;
        foreach ($movements as $movement) {
            $type = (string) ($movement['movement_type'] ?? '');
            if ($type === 'closing_adjustment') {
                $closeVariance += (float) ($movement['amount'] ?? 0);
                continue;
            }

            $sign = self::MOVEMENT_TYPES[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }

            $preCloseExpected += $sign * (float) ($movement['amount'] ?? 0);
        }

        $countedCash = $session['counted_cash'] !== null ? (float) $session['counted_cash'] : null;
        $isClosed = in_array((string) ($session['status'] ?? ''), ['closed', 'forced_closed'], true);

        return [
            'pre_close_expected_cash' => $this->formatDecimal($preCloseExpected),
            'close_variance' => $this->formatDecimal($closeVariance),
            'post_close_expected_cash' => $this->formatDecimal($preCloseExpected + $closeVariance),
            'counted_cash' => $countedCash !== null ? $this->formatDecimal($countedCash) : null,
            'is_closed' => $isClosed,
        ];
    }

    public function expectedCash(mysqli $conn, int $sessionId): string
    {
        $session = $this->sessionById($conn, $sessionId);
        $movements = $this->movementsForSession($conn, $sessionId);
        $hasOpeningMovement = false;
        foreach ($movements as $movement) {
            if (($movement['movement_type'] ?? '') === 'opening') {
                $hasOpeningMovement = true;
                break;
            }
        }

        $expected = $hasOpeningMovement ? 0.0 : (float) $session['opening_cash'];
        foreach ($movements as $movement) {
            $type = (string) ($movement['movement_type'] ?? '');
            $sign = self::MOVEMENT_TYPES[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }
            $expected += $sign * (float) $movement['amount'];
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

  /** True once any branch has ever recorded a drawer session (subsystem adopted). */
    public function subsystemInUse(mysqli $conn): bool
    {
        $result = $conn->query('SELECT 1 FROM drawer_sessions LIMIT 1');

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return list<array<string, mixed>> */
    public function findOpenSessionsForUser(mysqli $conn, int $userId): array
    {
        if ($userId < 1) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM drawer_sessions
            WHERE user_id = ?
              AND status = 'open'
              AND closed_at IS NULL
            ORDER BY opened_at DESC, id DESC
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $this->formatSession($row);
        }
        $stmt->close();

        return $sessions;
    }

    public function resolveOpenSessionForUser(mysqli $conn, int $userId, array $context = []): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $sessionId = (int) ($context['drawer_session_id'] ?? 0);
        if ($sessionId < 1 && session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = (int) ($_SESSION['pos_drawer_session_id'] ?? 0);
        }

        if ($sessionId > 0) {
            try {
                $session = $this->sessionById($conn, $sessionId);
                if (($session['status'] ?? '') === 'open' && (int) ($session['user_id'] ?? 0) === $userId) {
                    return $session;
                }
            } catch (Throwable $exception) {
                // fall through to scoped lookup
            }
        }

        $tenant = (int) ($context['tenant'] ?? $context['pos_tenant'] ?? 0);
        $branch = (int) ($context['branch'] ?? $context['pos_branch'] ?? 0);
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($tenant < 1) {
                $tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
            }
            if ($branch < 1) {
                $branch = (int) ($_SESSION['pos_branch'] ?? 0);
            }
        }

        $session = $this->findOpenSession($conn, $userId, $tenant, $branch);
        if ($session) {
            return $session;
        }

        $sessions = $this->findOpenSessionsForUser($conn, $userId);

        return $sessions[0] ?? null;
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

    public function netCashRecordedForOrder(mysqli $conn, int $orderId): float
    {
        if ($orderId < 1) {
            return 0.0;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'drawer_movements'");
        if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows < 1) {
            return 0.0;
        }

        $stmt = $conn->prepare("
            SELECT movement_type, COALESCE(SUM(amount), 0) AS total_amount
            FROM drawer_movements
            WHERE order_id = ?
              AND movement_type IN ('sale_cash', 'refund_cash')
            GROUP BY movement_type
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();

        $saleCash = 0.0;
        $refundCash = 0.0;
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['movement_type'] ?? '');
            $amount = (float) ($row['total_amount'] ?? 0);
            if ($type === 'sale_cash') {
                $saleCash = $amount;
            } elseif ($type === 'refund_cash') {
                $refundCash = $amount;
            }
        }
        $stmt->close();

        return round($saleCash - $refundCash, 3);
    }

    private function finishSession(mysqli $conn, int $sessionId, array $request, string $status): array
    {
        $this->requireOpenSession($conn, $sessionId);
        $closedBy = $this->positiveInt($request['closed_by'] ?? $request['user_id'] ?? 0, 'CLOSED_BY_REQUIRED');
        $countedCash = $this->decimal($request['counted_cash'] ?? null, false, 'COUNTED_CASH_INVALID');
        $notes = $this->nullableText($request['notes'] ?? null, 500);
        $closedAt = $this->dateTime($request['closed_at'] ?? null);

        $conn->begin_transaction();

        try {
            $expectedBeforeClose = (float) $this->expectedCash($conn, $sessionId);
            $difference = round((float) $countedCash - $expectedBeforeClose, 3);

            if (abs($difference) > 0.0001) {
                $this->recordMovement($conn, $sessionId, [
                    'movement_type' => 'closing_adjustment',
                    'amount' => $this->formatDecimal($difference),
                    'created_by' => $closedBy,
                    'reason' => 'shift_close_variance',
                ]);
            }

            $expectedCash = $this->expectedCash($conn, $sessionId);
            $differenceFormatted = $this->formatDecimal((float) $countedCash - (float) $expectedCash);

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
            $stmt->bind_param('sisssssi', $closedAt, $closedBy, $expectedCash, $countedCash, $differenceFormatted, $status, $notes, $sessionId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected !== 1) {
                throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
            }

            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
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
        $movement = [
            'id' => (int) $row['id'],
            'drawer_session_id' => $row['drawer_session_id'] !== null ? (int) $row['drawer_session_id'] : null,
            'movement_type' => (string) $row['movement_type'],
            'amount' => $this->formatDecimal($row['amount']),
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
            'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
            'created_by' => (int) $row['created_by'],
            'created_at' => (string) $row['created_at'],
            'is_unassigned' => $row['drawer_session_id'] === null,
        ];

        if (array_key_exists('tenant', $row)) {
            $movement['tenant'] = (int) $row['tenant'];
        }
        if (array_key_exists('branch', $row)) {
            $movement['branch'] = (int) $row['branch'];
        }

        if (array_key_exists('manager_approval_id', $row)) {
            $movement['manager_approval_id'] = $row['manager_approval_id'] !== null
                ? (int) $row['manager_approval_id']
                : null;
        }
        if (array_key_exists('ref_ot_head_id', $row)) {
            $movement['ref_ot_head_id'] = $row['ref_ot_head_id'] !== null
                ? (int) $row['ref_ot_head_id']
                : null;
        }

        return $movement;
    }

    private function movementColumnExists(mysqli $conn, string $column): bool
    {
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeColumn === '') {
            return false;
        }

        $key = spl_object_hash($conn) . ':drawer_movements:' . $safeColumn;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM drawer_movements LIKE '{$safeColumn}'");
        self::$columnCache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;

        return self::$columnCache[$key];
    }

    private function drawerMovementsTableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'drawer_movements'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
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

    private function decimal($value, bool $positiveOnly, string $code, bool $allowZero = false): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($code);
        }

        $amount = (float) $value;
        if ($positiveOnly && $amount < 0) {
            throw new InvalidArgumentException($code);
        }
        if ($positiveOnly && $amount === 0.0 && !$allowZero) {
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
