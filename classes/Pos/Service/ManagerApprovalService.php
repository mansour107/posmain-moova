<?php

require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';

class ManagerApprovalRequiredException extends RuntimeException
{
    private string $permissionKey;

    public function __construct(string $permissionKey = '')
    {
        parent::__construct('MANAGER_APPROVAL_REQUIRED');
        $this->permissionKey = trim($permissionKey);
    }

    public function permissionKey(): string
    {
        return $this->permissionKey;
    }
}

class ManagerApprovalService
{
    private const TERMINAL_STATUSES = ['approved', 'declined', 'expired'];
    private const DEFAULT_TTL_SECONDS = 90;

    public function requestApproval(mysqli $conn, array $data): array
    {
        $actionType = $this->requiredText($data['action_type'] ?? '', 80, 'APPROVAL_ACTION_REQUIRED');
        $targetType = $this->requiredText($data['target_type'] ?? '', 80, 'APPROVAL_TARGET_REQUIRED');
        $targetId = $this->optionalPositiveInt($data['target_id'] ?? null);
        $requestedBy = $this->positiveInt($data['requested_by'] ?? 0, 'APPROVAL_REQUESTER_REQUIRED');
        $reason = $this->nullableText($data['reason'] ?? null, 500);
        $metadataJson = $this->jsonOrNull($data['metadata'] ?? $data['metadata_json'] ?? null);
        $permissionKey = $this->nullableText($data['permission_key'] ?? null, 80);
        $expiresAt = $this->resolveExpiresAt($data['expires_at'] ?? null);

        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
            $stmt = $conn->prepare("
                INSERT INTO manager_approvals (
                    action_type, target_type, target_id, requested_by, reason, metadata_json,
                    permission_key, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssiissss', $actionType, $targetType, $targetId, $requestedBy, $reason, $metadataJson, $permissionKey, $expiresAt);
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();

            $approval = $this->approvalById($conn, $id, false);
            $this->recordSyncSnapshot($conn, $id, 1, $data);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $approval;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    public function decide(mysqli $conn, int $approvalId, array $data): array
    {
        $approvalId = $this->positiveInt($approvalId, 'APPROVAL_ID_REQUIRED');
        $approvedBy = $this->positiveInt($data['approved_by'] ?? $data['manager_id'] ?? 0, 'APPROVAL_MANAGER_REQUIRED');
        $status = strtolower(trim((string) ($data['status'] ?? 'approved')));
        if (!in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException('APPROVAL_STATUS_INVALID');
        }
        $reason = $this->nullableText($data['reason'] ?? null, 500);

        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
            $stmt = $conn->prepare("
                UPDATE manager_approvals
                SET approved_by = ?,
                    status = ?,
                    reason = COALESCE(?, reason),
                    decided_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status = 'requested'
            ");
            $stmt->bind_param('issi', $approvedBy, $status, $reason, $approvalId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected < 1) {
                $existing = $this->approvalById($conn, $approvalId, true);
                if ((string) ($existing['status'] ?? '') !== 'requested') {
                    throw new RuntimeException('APPROVAL_ALREADY_DECIDED');
                }
                throw new RuntimeException('APPROVAL_NOT_FOUND');
            }

            $approval = $this->approvalById($conn, $approvalId, false);
            $this->recordSyncSnapshot($conn, $approvalId, 2, $data);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $approval;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    public function requireApprovedIfNeeded(
        mysqli $conn,
        string $actionType,
        string $targetType,
        ?int $targetId,
        float $amount,
        array $request = [],
        array $context = []
    ): ?array {
        $userId = (int) ($context['user_id'] ?? 0);
        $limitPermissionKey = trim((string) ($context['limit_permission_key'] ?? ''));
        if ($userId > 0 && $limitPermissionKey !== '') {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../../Security/PermissionService.php';
            }
            $permissionService = PermissionService::forConnection($conn);
            if ($permissionService->checkAmount($userId, $limitPermissionKey, $amount)) {
                return null;
            }
            $escalationKey = trim((string) ($context['escalation_permission_key'] ?? $actionType));
            if ($escalationKey !== '') {
                $actionType = $escalationKey;
            }
        } elseif (!$this->shouldEnforce($actionType, $context)) {
            return null;
        }

        $threshold = $this->threshold($context);
        if ($userId < 1 || $limitPermissionKey === '') {
            if ($amount <= $threshold) {
                return null;
            }
        }

        $approvalId = $this->approvalIdFrom($request, $context);
        if ($approvalId === null) {
            throw new ManagerApprovalRequiredException($actionType);
        }

        $approval = $this->approvalById($conn, $approvalId, true);
        $this->assertApprovalUsable($approval, $actionType, $targetType, $targetId);

        return $approval;
    }

    public function consumeApproval(mysqli $conn, int $approvalId, int $performedBy, array $context = []): array
    {
        $approvalId = $this->positiveInt($approvalId, 'APPROVAL_ID_REQUIRED');
        $performedBy = $this->positiveInt($performedBy, 'APPROVAL_PERFORMER_REQUIRED');
        $ownsTransaction = $this->beginTransactionIfNeeded($conn);
        try {
            $approval = $this->approvalById($conn, $approvalId, true);

            if ((string) ($approval['status'] ?? '') !== 'approved') {
                throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
            }
            if (!empty($approval['consumed_at'])) {
                throw new RuntimeException('APPROVAL_ALREADY_CONSUMED');
            }
            $this->assertNotExpired($approval);

            $stmt = $conn->prepare("
                UPDATE manager_approvals
                   SET consumed_at = CURRENT_TIMESTAMP,
                       performed_by = ?
                 WHERE id = ?
                   AND status = 'approved'
                   AND consumed_at IS NULL
            ");
            $stmt->bind_param('ii', $performedBy, $approvalId);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('APPROVAL_NOT_FOUND');
            }
            $stmt->close();

            $approval = $this->approvalById($conn, $approvalId, false);
            $this->recordSyncSnapshot($conn, $approvalId, 3, $context);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $approval;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $e;
        }
    }

    public function authenticateManagerOverride(mysqli $conn, string $pin, string $permissionKey, int $requestedBy, array $context = []): array
    {
        if (!class_exists('PinService', false)) {
            require_once __DIR__ . '/../../Security/PinService.php';
        }
        if (!function_exists('auth_guard_has_permission')) {
            require_once __DIR__ . '/../../../includes/auth_guard.php';
        }

        $pinService = new PinService();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($pinService->isTerminalFrozen($conn, $ip)) {
            throw new RuntimeException('PIN_TERMINAL_FROZEN');
        }

        $manager = null;
        try {
            $manager = $pinService->findUserByPin($conn, $pin);
        } catch (InvalidArgumentException $exception) {
            $pinService->recordTerminalFailure($conn, $ip);
            throw new RuntimeException('MANAGER_PIN_INVALID', 0, $exception);
        }
        if (!$manager || !$pinService->verifyPin($pin, (string) ($manager['pin_hash'] ?? ''))) {
            if ($manager) {
                $pinService->recordUserFailure($conn, (int) $manager['id']);
            }
            $pinService->recordTerminalFailure($conn, $ip);
            throw new RuntimeException('MANAGER_PIN_INVALID');
        }
        if ($pinService->isUserLocked($manager)) {
            throw new RuntimeException('MANAGER_PIN_LOCKED');
        }

        $managerId = (int) $manager['id'];
        $pinService->clearUserFailures($conn, $managerId);
        $pinService->clearTerminalFailures($conn, $ip);
        $roleFlags = [];
        if (!empty($manager['userrole'])) {
            $roleStmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
            $roleId = (int) $manager['userrole'];
            $roleStmt->bind_param('i', $roleId);
            $roleStmt->execute();
            $roleFlags = $roleStmt->get_result()->fetch_assoc() ?: [];
            $roleStmt->close();
        }

        $managerSession = ['userid' => $managerId, 'login' => true, 'usrole' => $manager['userrole'] ?? 0];
        if (!auth_guard_is_admin_session($managerSession, $roleFlags)
            && !auth_guard_session_has_permission($permissionKey, $roleFlags, $managerSession, $conn)) {
            throw new RuntimeException('MANAGER_PERMISSION_DENIED');
        }

        // Step-up re-auth for shift join/takeover (and explicit callers):
        // if the acting user already holds this permission, the PIN must belong
        // to that same user — another manager cannot authorize under their session.
        $requireSameUser = !empty($context['require_same_user']);
        $sameUserPermissions = ['pos.shift.override', 'pos.shift.force_close'];
        if (!$requireSameUser && $requestedBy > 0 && in_array($permissionKey, $sameUserPermissions, true)) {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../../Security/PermissionService.php';
            }
            $requireSameUser = PermissionService::forConnection($conn)->check($requestedBy, $permissionKey);
        }
        if ($requireSameUser && $requestedBy > 0 && $managerId !== $requestedBy) {
            $pinService->recordTerminalFailure($conn, $ip);
            throw new RuntimeException('MANAGER_PIN_MISMATCH');
        }

        $limitCheck = $this->resolveApproverLimitCheck($permissionKey, $context);
        if ($limitCheck !== null) {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../../Security/PermissionService.php';
            }
            $permissionService = PermissionService::forConnection($conn);
            if (!$permissionService->checkAmount(
                $managerId,
                $limitCheck['limit_permission_key'],
                $limitCheck['amount'],
                (int) ($manager['userrole'] ?? 0) ?: null
            )) {
                throw new RuntimeException('APPROVER_LIMIT_EXCEEDED');
            }
        }

        $approval = $this->requestApproval($conn, [
            'action_type' => (string) ($context['action_type'] ?? $permissionKey ?: 'manager.override'),
            'target_type' => (string) ($context['target_type'] ?? 'pos_action'),
            'target_id' => $context['target_id'] ?? null,
            'requested_by' => $requestedBy,
            'permission_key' => $permissionKey,
            'reason' => $context['reason'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ]);

        $this->decide($conn, (int) $approval['id'], [
            'approved_by' => $managerId,
            'status' => 'approved',
            'reason' => $context['reason'] ?? null,
        ]);

        return $this->approvalById($conn, (int) $approval['id'], false);
    }

    public function validateApprovedPermissionOverride(
        mysqli $conn,
        int $approvalId,
        string $permissionKey,
        int $requestedBy
    ): array {
        $permissionKey = trim($permissionKey);
        if ($permissionKey === '') {
            throw new InvalidArgumentException('PERMISSION_KEY_REQUIRED');
        }

        $approval = $this->approvalById($conn, $approvalId, false);
        $storedKey = trim((string) ($approval['permission_key'] ?? ''));
        if ($storedKey === '') {
            $storedKey = trim((string) ($approval['action_type'] ?? ''));
        }
        if ($storedKey !== $permissionKey) {
            throw new RuntimeException('MANAGER_APPROVAL_SCOPE_MISMATCH');
        }
        if ($requestedBy > 0 && (int) ($approval['requested_by'] ?? 0) !== $requestedBy) {
            throw new RuntimeException('MANAGER_APPROVAL_REQUESTER_MISMATCH');
        }
        if ((string) ($approval['status'] ?? '') !== 'approved' || (int) ($approval['approved_by'] ?? 0) <= 0) {
            throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
        }
        if (!empty($approval['consumed_at'])) {
            throw new RuntimeException('APPROVAL_ALREADY_CONSUMED');
        }
        $this->assertNotExpired($approval);

        return $approval;
    }

    private function assertApprovalUsable(array $approval, string $actionType, string $targetType, ?int $targetId): void
    {
        if ((string) $approval['action_type'] !== $actionType || (string) $approval['target_type'] !== $targetType) {
            throw new RuntimeException('MANAGER_APPROVAL_SCOPE_MISMATCH');
        }
        if ((string) $approval['status'] !== 'approved' || (int) ($approval['approved_by'] ?? 0) <= 0) {
            throw new RuntimeException('MANAGER_APPROVAL_NOT_APPROVED');
        }
        if (!empty($approval['consumed_at'])) {
            throw new RuntimeException('APPROVAL_ALREADY_CONSUMED');
        }
        $this->assertNotExpired($approval);

        $approvalTargetId = $approval['target_id'] !== null ? (int) $approval['target_id'] : null;
        if ($approvalTargetId !== null && ($targetId === null || $approvalTargetId !== $targetId)) {
            throw new RuntimeException('MANAGER_APPROVAL_TARGET_MISMATCH');
        }
    }

    private function assertNotExpired(array $approval): void
    {
        if (empty($approval['expires_at'])) {
            return;
        }
        if (strtotime((string) $approval['expires_at']) <= time()) {
            throw new RuntimeException('APPROVAL_EXPIRED');
        }
    }

    private function resolveExpiresAt($value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value !== '') {
            $ts = strtotime($value);
            if ($ts === false) {
                throw new InvalidArgumentException('APPROVAL_EXPIRES_INVALID');
            }

            return date('Y-m-d H:i:s', $ts);
        }

        return date('Y-m-d H:i:s', time() + self::DEFAULT_TTL_SECONDS);
    }

    public function approvalById(mysqli $conn, int $approvalId, bool $forUpdate = false): array
    {
        $approvalId = $this->positiveInt($approvalId, 'APPROVAL_ID_REQUIRED');
        $sql = "SELECT * FROM manager_approvals WHERE id = ? LIMIT 1";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $approvalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('APPROVAL_NOT_FOUND');
        }

        if (isset($row['metadata_json']) && $row['metadata_json'] !== null) {
            $row['metadata'] = json_decode((string) $row['metadata_json'], true);
        } else {
            $row['metadata'] = null;
        }

        return $row;
    }

    private function beginTransactionIfNeeded(mysqli $conn): bool
    {
        $result = $conn->query('SELECT @@session.in_transaction AS active_transaction');
        $row = $result->fetch_assoc() ?: [];
        if (!empty($row['active_transaction'])) {
            return false;
        }

        $conn->begin_transaction();
        return true;
    }

    private function recordSyncSnapshot(mysqli $conn, int $approvalId, int $revision, array $context): void
    {
        $options = [
            'event_type' => 'manager_approval.saved',
            'source_system' => 'manager_approval',
            'event_version' => $revision,
        ];
        if (isset($context['sync_config']) && is_array($context['sync_config'])) {
            $options['config'] = $context['sync_config'];
        }

        (new OperationalSyncEventService())->recordRowSnapshot($conn, 'manager_approval', $approvalId, $options);
    }

  /**
     * @return array{limit_permission_key: string, amount: float}|null
     */
    private function resolveApproverLimitCheck(string $permissionKey, array $context): ?array
    {
        $limitKey = trim((string) ($context['limit_permission_key'] ?? ''));
        if ($limitKey === '') {
            $limitKey = $this->limitKeyForEscalation($permissionKey) ?? '';
        }
        if ($limitKey === '') {
            return null;
        }

        if (!array_key_exists('amount', $context) && !array_key_exists('limit_amount', $context)) {
            return null;
        }

        $amount = (float) ($context['amount'] ?? $context['limit_amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        return [
            'limit_permission_key' => $limitKey,
            'amount' => $amount,
        ];
    }

    private function limitKeyForEscalation(string $permissionKey): ?string
    {
        $map = [
            'pos.discount.manual_pct.limit' => 'pos.discount.apply',
            'pos.payout.over_limit' => 'pos.payout.over_limit',
            'pos.refund.limit' => 'pos.refund.limit',
            'pos.refund' => 'pos.refund.limit',
        ];

        return $map[$permissionKey] ?? null;
    }

    private function shouldEnforce(string $actionType, array $context): bool
    {
        if (array_key_exists('require_manager_approval', $context)) {
            return (bool) $context['require_manager_approval'];
        }
        if (array_key_exists('require_discount_approval', $context) && strpos($actionType, 'discount') !== false) {
            return (bool) $context['require_discount_approval'];
        }

        $global = getenv('POSMAIN_REQUIRE_MANAGER_APPROVAL');
        if ($this->truthy($global)) {
            return true;
        }
        if (strpos($actionType, 'discount') !== false || strpos($actionType, 'manual_pct') !== false) {
            $userId = (int) ($context['user_id'] ?? 0);
            if ($userId > 0 && class_exists('PermissionService', false)) {
                return true;
            }
        }

        $discount = getenv('POSMAIN_REQUIRE_DISCOUNT_APPROVAL');
        return strpos($actionType, 'discount') !== false && $this->truthy($discount);
    }

    private function threshold(array $context): float
    {
        if (array_key_exists('discount_approval_threshold', $context)) {
            return max(0, (float) $context['discount_approval_threshold']);
        }

        $value = getenv('POSMAIN_DISCOUNT_APPROVAL_THRESHOLD');
        return $value === false || $value === '' ? 0.0 : max(0, (float) $value);
    }

    private function approvalIdFrom(array $request, array $context): ?int
    {
        foreach (['manager_approval_id', 'approval_id', 'discount_approval_id'] as $key) {
            if (isset($request[$key]) && (int) $request[$key] > 0) {
                return (int) $request[$key];
            }
            if (isset($context[$key]) && (int) $context[$key] > 0) {
                return (int) $context[$key];
            }
        }

        return null;
    }

    private function truthy($value): bool
    {
        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
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

    private function requiredText($value, int $maxLength, string $code): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($code);
        }

        return $this->truncate($text, $maxLength);
    }

    private function nullableText($value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $this->truncate($text, $maxLength);
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function jsonOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('APPROVAL_METADATA_INVALID');
        }

        return $json;
    }
}
