<?php

require_once dirname(__DIR__, 3) . '/includes/drawer_movement_signs.php';
require_once __DIR__ . '/BusinessDayService.php';
require_once __DIR__ . '/../../Sync/OperationalSyncRecorder.php';
require_once dirname(__DIR__) . '/Value/CashAmount.php';

class DrawerSessionService
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /** @return array<string, int> */
    private static function movementTypes(): array
    {
        static $types = null;
        if ($types === null) {
            $types = posmain_drawer_movement_signs();
        }

        return $types;
    }

    public function openSession(mysqli $conn, array $request): array
    {
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        $userId = $this->positiveInt($request['user_id'] ?? 0, 'USER_ID_REQUIRED');
        $openedBy = $this->positiveInt($request['opened_by'] ?? $userId, 'OPENED_BY_REQUIRED');
        $tenant = $this->nonNegativeInt($request['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($request['branch'] ?? 0, 'BRANCH_INVALID');
        $fundAccountId = $this->optionalPositiveInt($request['fund_account_id'] ?? null);
        $openingCash = $this->decimal($request['opening_cash'] ?? '0', true, 'OPENING_CASH_INVALID', true);
        $notes = $this->nullableText($request['notes'] ?? null, 500);
        $registerId = $this->optionalPositiveInt($request['register_id'] ?? null);
        $precedingSessionId = $this->optionalPositiveInt($request['preceding_session_id'] ?? null);
        $takeoverAuthorizedBy = $this->optionalPositiveInt($request['takeover_authorized_by'] ?? null);
        $hasRegisterLocks = $this->drawerSessionColumnExists($conn, 'open_register_lock')
            && $this->drawerSessionColumnExists($conn, 'open_user_lock')
            && $registerId !== null;

        $ownsTransaction = posmain_tx_begin_if_needed(
            $conn,
            posmain_tx_context_in_transaction($request)
        );

        try {
            if ($this->findOpenSession($conn, $userId, $tenant, $branch)) {
                throw new RuntimeException('DRAWER_SESSION_ALREADY_OPEN');
            }

            if ($hasRegisterLocks) {
                $registerOpen = $this->findOpenSessionForRegister($conn, $registerId);
                if ($registerOpen && (int) ($registerOpen['user_id'] ?? 0) !== $userId) {
                    throw new RuntimeException('REGISTER_DRAWER_ALREADY_OPEN');
                }
            } else {
                $branchOpen = $this->findOpenSessionForBranch($conn, $tenant, $branch);
                if ($branchOpen && (int) ($branchOpen['user_id'] ?? 0) !== $userId) {
                    throw new RuntimeException('BRANCH_DRAWER_ALREADY_OPEN');
                }
            }

            $uuid = $this->uuid();
            $openedAt = $this->dateTime($request['opened_at'] ?? null);
            $businessDay = null;
            if ($this->drawerSessionColumnExists($conn, 'business_day')) {
                $businessDays = new BusinessDayService();
                $cutoffHour = $businessDays->cutoffHourForBranch($conn, $tenant, $branch);
                $businessDay = $businessDays->businessDayForTimestamp($openedAt, $cutoffHour);
            }

            $columns = [
                'uuid', 'user_id', 'tenant', 'branch', 'fund_account_id', 'opened_at',
                'opened_by', 'opening_cash', 'status', 'notes',
            ];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', "'open'", '?'];
            $types = 'siiiisiss';
            $values = [
                $uuid, $userId, $tenant, $branch, $fundAccountId, $openedAt,
                $openedBy, $openingCash, $notes,
            ];

            if ($businessDay !== null) {
                $columns[] = 'business_day';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $businessDay;
            }

            if ($this->drawerSessionColumnExists($conn, 'register_id') && $registerId !== null) {
                $columns[] = 'register_id';
                $placeholders[] = '?';
                $types .= 'i';
                $values[] = $registerId;
            }

            if ($hasRegisterLocks) {
                $openRegisterLock = $tenant . ':' . $branch . ':r' . $registerId;
                $openUserLock = $tenant . ':' . $branch . ':u' . $userId;
                $columns[] = 'open_register_lock';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $openRegisterLock;
                $columns[] = 'open_user_lock';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $openUserLock;
                // Keep branch lock null once register locks are active so multi-register branches can open in parallel.
            } elseif ($this->drawerSessionColumnExists($conn, 'open_branch_lock')) {
                $openBranchLock = $tenant . ':' . $branch;
                $columns[] = 'open_branch_lock';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $openBranchLock;
            }

            if ($this->drawerSessionColumnExists($conn, 'preceding_session_id') && $precedingSessionId !== null) {
                $columns[] = 'preceding_session_id';
                $placeholders[] = '?';
                $types .= 'i';
                $values[] = $precedingSessionId;
            }
            if ($this->drawerSessionColumnExists($conn, 'takeover_authorized_by') && $takeoverAuthorizedBy !== null) {
                $columns[] = 'takeover_authorized_by';
                $placeholders[] = '?';
                $types .= 'i';
                $values[] = $takeoverAuthorizedBy;
            }
            if ($this->drawerSessionColumnExists($conn, 'sync_revision')) {
                $columns[] = 'sync_revision';
                $placeholders[] = '1';
            }

            $sql = 'INSERT INTO drawer_sessions (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);

            try {
                $stmt->execute();
            } catch (mysqli_sql_exception $exception) {
                $stmt->close();
                if ((int) $exception->getCode() === 1062) {
                    $message = (string) $exception->getMessage();
                    if (str_contains($message, 'open_register_lock') || str_contains($message, 'open_user_lock')) {
                        throw new RuntimeException(
                            str_contains($message, 'open_user_lock')
                                ? 'USER_DRAWER_ALREADY_OPEN'
                                : 'REGISTER_DRAWER_ALREADY_OPEN'
                        );
                    }
                    throw new RuntimeException('BRANCH_DRAWER_ALREADY_OPEN');
                }
                throw $exception;
            }
            $sessionId = (int) $conn->insert_id;
            $stmt->close();

            if ($sessionId < 1) {
                throw new RuntimeException('DRAWER_SESSION_CREATE_FAILED');
            }

            $this->recordMovement($conn, $sessionId, [
                'movement_type' => 'opening',
                'amount' => $openingCash,
                'created_by' => $openedBy,
                'allow_zero_amount' => true,
                'reason' => 'shift_opening',
                'sync_config' => $request['sync_config'] ?? null,
            ]);
            $this->recordSessionSyncSnapshot($conn, $sessionId, $request);

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        return $this->sessionById($conn, $sessionId);
    }

    public function recordMovement(mysqli $conn, int $sessionId, array $request): array
    {
        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
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
            $idempotencyRequired = in_array($type, [
                'sale_cash',
                'refund_cash',
                'paid_in',
                'paid_out',
                'safe_drop',
                'no_sale',
            ], true);
            $idempotencyKey = trim((string) ($request['idempotency_key'] ?? ''));
            if ($idempotencyRequired && $idempotencyKey === '') {
                throw new InvalidArgumentException('DRAWER_IDEMPOTENCY_REQUIRED');
            }
            if (strlen($idempotencyKey) > 191) {
                throw new InvalidArgumentException('DRAWER_IDEMPOTENCY_KEY_INVALID');
            }
            $hasIdempotencyColumns = $this->movementColumnExists($conn, 'idempotency_key')
                && $this->movementColumnExists($conn, 'idempotency_hash');
            if ($idempotencyRequired && !$hasIdempotencyColumns) {
                throw new RuntimeException('DRAWER_IDEMPOTENCY_SCHEMA_REQUIRED');
            }
            $idempotencyHash = $idempotencyKey === '' ? null : hash('sha256', json_encode([
                'drawer_session_id' => $sessionId,
                'movement_type' => $type,
                'amount' => $amount,
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'reason' => $reason,
                'created_by' => $createdBy,
                'manager_approval_id' => $managerApprovalId,
                'ref_ot_head_id' => $refOtHeadId,
                'tenant' => $tenant,
                'branch' => $branch,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($idempotencyKey !== '' && $hasIdempotencyColumns) {
                $existing = $this->movementByIdempotencyKey($conn, $sessionId, $idempotencyKey);
                if ($existing) {
                    if (!hash_equals((string) ($existing['idempotency_hash'] ?? ''), (string) $idempotencyHash)) {
                        throw new RuntimeException('DRAWER_IDEMPOTENCY_CONFLICT');
                    }
                    $movement = $this->formatMovement($existing);
                    $movement['idempotency_replayed'] = true;
                    if ($ownsTransaction) {
                        $conn->commit();
                    }

                    return $movement;
                }
            }

            $columns = ['drawer_session_id', 'movement_type', 'amount', 'order_id', 'payment_id', 'reason', 'created_by'];
            $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
            $types = 'issiisi';
            $values = [$sessionId, $type, $amount, $orderId, $paymentId, $reason, $createdBy];

            if ($idempotencyKey !== '' && $hasIdempotencyColumns) {
                $columns[] = 'idempotency_key';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $idempotencyKey;
                $columns[] = 'idempotency_hash';
                $placeholders[] = '?';
                $types .= 's';
                $values[] = $idempotencyHash;
            }

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

            $movement = $this->movementById($conn, $id);
            $this->recordMovementSyncSnapshot($conn, $id, $request);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $movement;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function recordUnassignedMovement(mysqli $conn, array $request): ?array
    {
        if (!$this->drawerMovementsTableExists($conn)) {
            return null;
        }

        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
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

            $movement = $this->movementById($conn, $id);
            $this->recordMovementSyncSnapshot($conn, $id, $request);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $movement;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function linkLatestSaleMovementToVoucher(mysqli $conn, int $orderId, int $voucherId, array $context = []): bool
    {
        if ($orderId < 1 || $voucherId < 1 || !$this->movementColumnExists($conn, 'ref_ot_head_id')) {
            return false;
        }

        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM drawer_movements
                WHERE order_id = ?
                  AND movement_type = 'sale_cash'
                  AND ref_ot_head_id IS NULL
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('i', $orderId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $linked = $row
                ? $this->linkMovementToVoucher($conn, (int) $row['id'], $voucherId, $context)
                : false;
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $linked;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function linkMovementToVoucher(mysqli $conn, int $movementId, int $voucherId, array $context = []): bool
    {
        if ($movementId < 1 || $voucherId < 1 || !$this->movementColumnExists($conn, 'ref_ot_head_id')) {
            return false;
        }

        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
            $update = $conn->prepare('UPDATE drawer_movements SET ref_ot_head_id = ? WHERE id = ? AND ref_ot_head_id IS NULL');
            $update->bind_param('ii', $voucherId, $movementId);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();

            if ($affected === 1) {
                $this->recordMovementSyncSnapshot($conn, $movementId, $context);
            }
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $affected === 1;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
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

        $preCloseExpected = $hasOpeningMovement
            ? '0.00'
            : CashAmount::normalize($session['opening_cash'] ?? '0.00');
        $closeVariance = '0.00';
        foreach ($movements as $movement) {
            $type = (string) ($movement['movement_type'] ?? '');
            if ($type === 'closing_adjustment') {
                $closeVariance = CashAmount::add($closeVariance, $movement['amount'] ?? '0.00');
                continue;
            }

            $sign = self::movementTypes()[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }

            $amount = CashAmount::normalize($movement['amount'] ?? '0.00', true);
            $preCloseExpected = CashAmount::add(
                $preCloseExpected,
                $sign < 0 ? CashAmount::negate($amount) : $amount
            );
        }

        $countedCash = $session['counted_cash'] !== null
            ? CashAmount::normalize($session['counted_cash'])
            : null;
        $isClosed = in_array((string) ($session['status'] ?? ''), ['closed', 'forced_closed'], true);
        $countPending = $isClosed && $countedCash === null;
        // No closing count yet (open session or interrupted close) is unknown over/short,
        // not a balanced 0.00 — same rule as count_pending closed sessions.
        $closeVarianceKnown = $countedCash !== null;

        return [
            'pre_close_expected_cash' => $preCloseExpected,
            // A missing close count is not a zero variance. Keep it nullable so
            // reporting can distinguish an uncounted close from a balanced one.
            'close_variance' => $closeVarianceKnown ? $closeVariance : null,
            'post_close_expected_cash' => $closeVarianceKnown
                ? CashAmount::add($preCloseExpected, $closeVariance)
                : $preCloseExpected,
            'counted_cash' => $countedCash,
            'has_closing_count' => $countedCash !== null,
            'count_pending' => $countPending,
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

        $expected = $hasOpeningMovement
            ? '0.00'
            : CashAmount::normalize($session['opening_cash'] ?? '0.00');
        foreach ($movements as $movement) {
            $type = (string) ($movement['movement_type'] ?? '');
            $sign = self::movementTypes()[$type] ?? 0;
            if ($sign === 0) {
                continue;
            }
            $amount = CashAmount::normalize($movement['amount'] ?? '0.00', true);
            $expected = CashAmount::add($expected, $sign < 0 ? CashAmount::negate($amount) : $amount);
        }

        return $expected;
    }

    public function closeSession(mysqli $conn, int $sessionId, array $request, array $context = []): array
    {
        return $this->finishSession($conn, $sessionId, $request, 'closed', $context);
    }

    public function forceCloseSession(mysqli $conn, int $sessionId, array $request, array $context = []): array
    {
        return $this->finishSession($conn, $sessionId, $request, 'forced_closed', $context);
    }

    /**
     * Version and capture a drawer-session mutation already made by a higher-level
     * workflow. The caller must own or join the surrounding business transaction;
     * starting a transaction here would be too late to make the preceding write
     * and its outbox event atomic.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function captureExternalSessionMutation(mysqli $conn, int $sessionId, array $context = []): array
    {
        $sessionId = $this->positiveInt($sessionId, 'DRAWER_SESSION_REQUIRED');
        if (!$this->connectionInTransaction($conn)) {
            throw new RuntimeException('DRAWER_SESSION_SYNC_TRANSACTION_REQUIRED');
        }

        if (!$this->drawerSessionColumnExists($conn, 'sync_revision')) {
            // Disabled sync remains compatible with older schemas. When sync is
            // enabled, the typed recorder fails closed on the missing revision.
            $this->recordSessionSyncSnapshot($conn, $sessionId, $context);

            return $this->sessionById($conn, $sessionId);
        }

        $stmt = $conn->prepare('
            UPDATE drawer_sessions
            SET sync_revision = sync_revision + 1
            WHERE id = ?
        ');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('DRAWER_SESSION_NOT_FOUND');
        }

        $this->recordSessionSyncSnapshot($conn, $sessionId, $context);

        return $this->sessionById($conn, $sessionId);
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

    public function findOpenSessionForRegister(mysqli $conn, int $registerId): ?array
    {
        if ($registerId < 1 || !$this->drawerSessionColumnExists($conn, 'register_id')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM drawer_sessions
            WHERE register_id = ?
              AND status = 'open'
            ORDER BY opened_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $registerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatSession($row) : null;
    }

    /**
     * Move an open drawer to another paired register after manager approval.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function transferOpenSessionRegister(mysqli $conn, int $sessionId, int $targetRegisterId, array $request = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        if ($targetRegisterId < 1) {
            throw new RuntimeException('REGISTER_ID_REQUIRED');
        }
        if (!$this->drawerSessionColumnExists($conn, 'register_id')) {
            throw new RuntimeException('REGISTER_COLUMN_MISSING');
        }

        $ownsTransaction = posmain_tx_begin_if_needed(
            $conn,
            posmain_tx_context_in_transaction($request)
        );

        try {
            $session = $this->requireOpenSession($conn, $sessionId);
            $existing = $this->findOpenSessionForRegister($conn, $targetRegisterId);
            if ($existing && (int) $existing['id'] !== $sessionId) {
                throw new RuntimeException('REGISTER_DRAWER_ALREADY_OPEN');
            }

            $tenant = (int) ($session['tenant'] ?? 0);
            $branch = (int) ($session['branch'] ?? 0);
            $userId = (int) ($session['user_id'] ?? 0);
            $authorizedBy = $this->optionalPositiveInt($request['authorized_by'] ?? null);

            $sets = ['register_id = ' . (int) $targetRegisterId];
            if ($this->drawerSessionColumnExists($conn, 'open_register_lock')) {
                $lock = $conn->real_escape_string($tenant . ':' . $branch . ':r' . $targetRegisterId);
                $sets[] = "open_register_lock = '{$lock}'";
            }
            if ($this->drawerSessionColumnExists($conn, 'open_user_lock')) {
                $lock = $conn->real_escape_string($tenant . ':' . $branch . ':u' . $userId);
                $sets[] = "open_user_lock = '{$lock}'";
            }
            if ($this->drawerSessionColumnExists($conn, 'open_branch_lock')) {
                // Register-scoped ownership supersedes branch lock.
                $sets[] = 'open_branch_lock = NULL';
            }
            if ($authorizedBy !== null && $this->drawerSessionColumnExists($conn, 'takeover_authorized_by')) {
                $sets[] = 'takeover_authorized_by = ' . (int) $authorizedBy;
            }
            if ($this->drawerSessionColumnExists($conn, 'sync_revision')) {
                $sets[] = 'sync_revision = sync_revision + 1';
            }

            $ok = $conn->query(
                'UPDATE drawer_sessions SET ' . implode(', ', $sets)
                . ' WHERE id = ' . (int) $sessionId . " AND status = 'open'"
            );
            if ($ok !== true || $conn->affected_rows !== 1) {
                throw new RuntimeException('REGISTER_TRANSFER_FAILED');
            }
            $this->recordSessionSyncSnapshot($conn, $sessionId, $request);

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        return $this->sessionById($conn, $sessionId);
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

    public function netCashRecordedForOrder(mysqli $conn, int $orderId): string
    {
        if ($orderId < 1) {
            return '0.00';
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'drawer_movements'");
        if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows < 1) {
            return '0.00';
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

        $saleCash = '0.00';
        $refundCash = '0.00';
        while ($row = $result->fetch_assoc()) {
            $type = (string) ($row['movement_type'] ?? '');
            $amount = CashAmount::normalize($row['total_amount'] ?? '0.00');
            if ($type === 'sale_cash') {
                $saleCash = $amount;
            } elseif ($type === 'refund_cash') {
                $refundCash = $amount;
            }
        }
        $stmt->close();

        return CashAmount::subtract($saleCash, $refundCash);
    }

    private function finishSession(mysqli $conn, int $sessionId, array $request, string $status, array $context = []): array
    {
        require_once dirname(__DIR__, 3) . '/includes/db_transaction.php';

        $this->requireOpenSession($conn, $sessionId);
        $closedBy = $this->positiveInt($request['closed_by'] ?? $request['user_id'] ?? 0, 'CLOSED_BY_REQUIRED');
        $countedCash = $this->decimal($request['counted_cash'] ?? null, true, 'COUNTED_CASH_INVALID', true);
        $notes = $this->nullableText($request['notes'] ?? null, 500);
        $closedAt = $this->dateTime($request['closed_at'] ?? null);

        $ownsTransaction = posmain_tx_begin_if_needed($conn, posmain_tx_context_in_transaction($context));

        try {
            $expectedBeforeClose = $this->expectedCash($conn, $sessionId);
            // Authoritative over/short = counted − pre-close expected (before closing_adjustment).
            $difference = CashAmount::subtract($countedCash, $expectedBeforeClose);

            if (CashAmount::compare($difference, '0.00') !== 0) {
                $this->recordMovement($conn, $sessionId, [
                    'movement_type' => 'closing_adjustment',
                    'amount' => $difference,
                    'created_by' => $closedBy,
                    'reason' => 'shift_close_variance',
                    'sync_config' => $context['sync_config'] ?? $request['sync_config'] ?? null,
                ]);
            }

            // Post-close expected equals counted; difference keeps raw over/short.
            $expectedCash = $this->expectedCash($conn, $sessionId);
            $differenceFormatted = $difference;

            $lockClears = [];
            if ($this->drawerSessionColumnExists($conn, 'open_branch_lock')) {
                $lockClears[] = 'open_branch_lock = NULL';
            }
            if ($this->drawerSessionColumnExists($conn, 'open_register_lock')) {
                $lockClears[] = 'open_register_lock = NULL';
            }
            if ($this->drawerSessionColumnExists($conn, 'open_user_lock')) {
                $lockClears[] = 'open_user_lock = NULL';
            }
            $lockSql = $lockClears !== [] ? (', ' . implode(', ', $lockClears)) : '';
            $revisionSql = $this->drawerSessionColumnExists($conn, 'sync_revision')
                ? ', sync_revision = sync_revision + 1'
                : '';
            $stmt = $conn->prepare("
                UPDATE drawer_sessions
                SET closed_at = ?,
                    closed_by = ?,
                    expected_cash = ?,
                    counted_cash = ?,
                    difference = ?,
                    status = ?,
                    notes = ?
                    {$lockSql}
                    {$revisionSql}
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

            if ($this->drawerSessionColumnExists($conn, 'close_expected_snapshot')
                && !array_key_exists('skip_close_expected_snapshot', $request)) {
                $snapshot = $expectedBeforeClose;
                $snapStmt = $conn->prepare('UPDATE drawer_sessions SET close_expected_snapshot = ? WHERE id = ?');
                $snapStmt->bind_param('si', $snapshot, $sessionId);
                $snapStmt->execute();
                $snapStmt->close();
            }

            $syncContext = $context;
            if (!isset($syncContext['sync_config']) && isset($request['sync_config'])) {
                $syncContext['sync_config'] = $request['sync_config'];
            }
            $this->recordSessionSyncSnapshot($conn, $sessionId, $syncContext);

            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        try {
            require_once __DIR__ . '/DrawerOverrideService.php';
            $endReason = $status === 'forced_closed'
                ? DrawerOverrideService::END_REASON_FORCE_CLOSE
                : DrawerOverrideService::END_REASON_SHIFT_CLOSE;
            (new DrawerOverrideService())->endActiveForDrawer($conn, $sessionId, $endReason, $closedBy);
        } catch (Throwable $ignored) {
        }

        return $this->sessionById($conn, $sessionId);
    }

    private function requireOpenSession(mysqli $conn, int $sessionId): array
    {
        $stmt = $conn->prepare('SELECT * FROM drawer_sessions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('DRAWER_SESSION_NOT_FOUND');
        }
        $session = $this->formatSession($row);
        if ($session['status'] !== 'open') {
            throw new RuntimeException('DRAWER_SESSION_NOT_OPEN');
        }

        return $session;
    }

    private function movementByIdempotencyKey(mysqli $conn, int $sessionId, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare(
            'SELECT * FROM drawer_movements
             WHERE drawer_session_id = ? AND idempotency_key = ?
             LIMIT 1'
        );
        $stmt->bind_param('is', $sessionId, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
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
        $session = [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'user_id' => (int) $row['user_id'],
            'tenant' => (int) $row['tenant'],
            'branch' => (int) $row['branch'],
            'fund_account_id' => $row['fund_account_id'] !== null ? (int) $row['fund_account_id'] : null,
            'opened_at' => (string) $row['opened_at'],
            'opened_by' => (int) $row['opened_by'],
            'opening_cash' => $this->formatDecimal($row['opening_cash']),
            'business_day' => array_key_exists('business_day', $row) && $row['business_day'] !== null
                ? (string) $row['business_day']
                : null,
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
            'closed_by' => $row['closed_by'] !== null ? (int) $row['closed_by'] : null,
            'expected_cash' => $row['expected_cash'] !== null ? $this->formatDecimal($row['expected_cash']) : null,
            'counted_cash' => $row['counted_cash'] !== null ? $this->formatDecimal($row['counted_cash']) : null,
            'difference' => $row['difference'] !== null ? $this->formatDecimal($row['difference']) : null,
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
        ];

        if (array_key_exists('register_id', $row)) {
            $session['register_id'] = $row['register_id'] !== null ? (int) $row['register_id'] : null;
        }
        if (array_key_exists('open_register_lock', $row)) {
            $session['open_register_lock'] = $row['open_register_lock'] !== null
                ? (string) $row['open_register_lock']
                : null;
        }
        if (array_key_exists('open_user_lock', $row)) {
            $session['open_user_lock'] = $row['open_user_lock'] !== null
                ? (string) $row['open_user_lock']
                : null;
        }

        if (array_key_exists('expected_opening_cash', $row)) {
            $session['expected_opening_cash'] = $row['expected_opening_cash'] !== null
                ? $this->formatDecimal($row['expected_opening_cash'])
                : null;
        }
        if (array_key_exists('opening_variance', $row)) {
            $session['opening_variance'] = $row['opening_variance'] !== null
                ? $this->formatDecimal($row['opening_variance'])
                : null;
        }
        if (array_key_exists('close_expected_snapshot', $row)) {
            $session['close_expected_snapshot'] = $row['close_expected_snapshot'] !== null
                ? $this->formatDecimal($row['close_expected_snapshot'])
                : null;
        }
        if (array_key_exists('variance_status', $row)) {
            $session['variance_status'] = (string) ($row['variance_status'] ?? 'none');
        }
        if (array_key_exists('variance_type', $row)) {
            $session['variance_type'] = (string) ($row['variance_type'] ?? 'none');
        }
        if (array_key_exists('preceding_session_id', $row)) {
            $session['preceding_session_id'] = $row['preceding_session_id'] !== null
                ? (int) $row['preceding_session_id']
                : null;
        }
        if (array_key_exists('takeover_authorized_by', $row)) {
            $session['takeover_authorized_by'] = $row['takeover_authorized_by'] !== null
                ? (int) $row['takeover_authorized_by']
                : null;
        }
        if (array_key_exists('sync_revision', $row)) {
            $session['sync_revision'] = (int) $row['sync_revision'];
        }

        return $session;
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
        if (array_key_exists('idempotency_key', $row)) {
            $movement['idempotency_key'] = $row['idempotency_key'] !== null
                ? (string) $row['idempotency_key']
                : null;
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

    private function beginTransactionIfNeeded(mysqli $conn): bool
    {
        if ($this->connectionInTransaction($conn)) {
            return false;
        }

        $conn->begin_transaction();
        return true;
    }

    private function connectionInTransaction(mysqli $conn): bool
    {
        $result = $conn->query('SELECT @@session.in_transaction AS active_transaction');
        $row = $result->fetch_assoc() ?: [];

        return !empty($row['active_transaction']);
    }

    private function recordMovementSyncSnapshot(mysqli $conn, int $movementId, array $context): void
    {
        $options = [
            'event_type' => 'drawer_movement.saved',
            'source_system' => 'drawer',
        ];
        if (isset($context['sync_config']) && is_array($context['sync_config'])) {
            $options['config'] = $context['sync_config'];
        } elseif ($this->syncOutboxAvailable($conn)) {
            // The outbox is a durability boundary, not a transport toggle.
            // Queue the mutation whenever the current schema supports it; the
            // separately configured worker still controls whether it is sent.
            $options['config'] = posmain_operational_sync_config();
        }

        (new OperationalSyncEventService())->recordRequiredDrawerMovementSnapshot($conn, $movementId, $options);
    }

    private function recordSessionSyncSnapshot(mysqli $conn, int $sessionId, array $context): void
    {
        $options = [
            'event_type' => 'drawer_session.saved',
            'source_system' => 'drawer',
        ];
        if (isset($context['sync_config']) && is_array($context['sync_config'])) {
            $options['config'] = $context['sync_config'];
        } elseif ($this->syncOutboxAvailable($conn)) {
            $options['config'] = posmain_operational_sync_config();
        }

        (new OperationalSyncEventService())->recordDrawerSessionSnapshot($conn, $sessionId, $options);
    }

    private function syncOutboxAvailable(mysqli $conn): bool
    {
        foreach (['sync_outbox', 'sync_branch_identity'] as $table) {
            $result = $conn->query("SHOW TABLES LIKE '{$table}'");
            if (!($result instanceof mysqli_result) || $result->num_rows < 1) {
                return false;
            }
        }

        return true;
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

    private function drawerSessionColumnExists(mysqli $conn, string $column): bool
    {
        $safeColumn = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($safeColumn === '') {
            return false;
        }

        $key = spl_object_hash($conn) . ':drawer_sessions:' . $safeColumn;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        $result = $conn->query("SHOW COLUMNS FROM drawer_sessions LIKE '{$safeColumn}'");
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
        if (!array_key_exists($type, self::movementTypes())) {
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

        try {
            $amount = CashAmount::normalize($value, !$positiveOnly);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException($code, 0, $exception);
        }
        if ($positiveOnly && CashAmount::compare($amount, '0.00') < 0) {
            throw new InvalidArgumentException($code);
        }
        if ($positiveOnly && CashAmount::compare($amount, '0.00') === 0 && !$allowZero) {
            throw new InvalidArgumentException($code);
        }

        return $amount;
    }

    private function formatDecimal($value): string
    {
        return CashAmount::normalize($value, true);
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
