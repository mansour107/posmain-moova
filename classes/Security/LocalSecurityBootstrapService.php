<?php

require_once __DIR__ . '/PinService.php';
require_once __DIR__ . '/SecurityAuditLogger.php';
require_once __DIR__ . '/RolePermissionSyncService.php';

class LocalSecurityBootstrapService
{
    public const BOOTSTRAP_PIN = '0000';

    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'security_bootstrap_state'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function isPending(mysqli $conn): bool
    {
        $state = $this->currentState($conn);

        return $state !== null && ($state['status'] ?? '') === 'pending';
    }

    public function isCompleted(mysqli $conn): bool
    {
        $state = $this->currentState($conn);

        return $state !== null && ($state['status'] ?? '') === 'completed';
    }

    public function currentState(mysqli $conn): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }

        $result = $conn->query('SELECT * FROM security_bootstrap_state WHERE id = 1 LIMIT 1');
        if (!$result instanceof mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();

        return is_array($row) ? $row : null;
    }

    /**
     * Ensure local PIN bootstrap state exists. Safe to call repeatedly.
     * Only installs bootstrap PIN 0000 when status is pending and no completed state exists.
     */
    public function ensureLocalBootstrap(mysqli $conn, int $ownerUserId = 0): array
    {
        if (!$this->tableExists($conn)) {
            throw new RuntimeException('BOOTSTRAP_SCHEMA_MISSING');
        }

        require_once __DIR__ . '/../../config/app_config.php';
        if (!function_exists('posmain_is_pin_main_auth') || !posmain_is_pin_main_auth()) {
            return $this->currentState($conn) ?: ['status' => 'skipped'];
        }

        posmain_pin_secret();

        $existing = $this->currentState($conn);
        if ($existing && ($existing['status'] ?? '') === 'completed') {
            return $existing;
        }

        if ($ownerUserId < 1) {
            $ownerUserId = $this->resolveOwnerUserId($conn);
        }
        if ($ownerUserId < 1) {
            throw new RuntimeException('BOOTSTRAP_OWNER_MISSING');
        }

        $ownsTx = false;
        if (!$conn->begin_transaction()) {
            // Some drivers already in transaction; continue best-effort.
        } else {
            $ownsTx = true;
        }

        try {
            $pinService = new PinService();
            $pinService->setBootstrapPinForOwner($conn, $ownerUserId, self::BOOTSTRAP_PIN);

            if ($this->userColumnExists($conn, 'pin_must_change')) {
                $stmt = $conn->prepare(
                    'UPDATE users SET pin_must_change = 1 WHERE id = ? AND COALESCE(isdeleted, 0) != 1'
                );
                $stmt->bind_param('i', $ownerUserId);
                $stmt->execute();
                $stmt->close();
            }

            $status = 'pending';
            $stmt = $conn->prepare(
                'INSERT INTO security_bootstrap_state (id, status, owner_user_id, started_at)
                 VALUES (1, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    status = IF(status = \'completed\', status, VALUES(status)),
                    owner_user_id = IF(status = \'completed\', owner_user_id, VALUES(owner_user_id)),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('si', $status, $ownerUserId);
            $stmt->execute();
            $stmt->close();

            if ($ownsTx) {
                $conn->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTx) {
                $conn->rollback();
            }
            throw $exception;
        }

        try {
            (new SecurityAuditLogger())->record($conn, 'owner_bootstrap_pin_seeded', [
                'user_id' => $ownerUserId,
                'target_type' => 'user',
                'target_id' => $ownerUserId,
                'metadata' => ['bootstrap' => true],
            ]);
        } catch (Throwable $ignored) {
        }

        return $this->currentState($conn) ?: ['status' => 'pending', 'owner_user_id' => $ownerUserId];
    }

    public function completeBootstrap(mysqli $conn, int $ownerUserId, string $newPin): void
    {
        require_once dirname(__DIR__, 2) . '/includes/db_transaction.php';
        $ownsTransaction = posmain_tx_begin_if_needed($conn, false);
        try {
            $stateStmt = $conn->prepare(
                'SELECT status, owner_user_id FROM security_bootstrap_state WHERE id = 1 FOR UPDATE'
            );
            $stateStmt->execute();
            $state = $stateStmt->get_result()->fetch_assoc();
            $stateStmt->close();
            if (!$state || ($state['status'] ?? '') !== 'pending') {
                throw new RuntimeException('BOOTSTRAP_NOT_PENDING');
            }
            $designatedOwnerId = (int) ($state['owner_user_id'] ?? 0);
            if ($designatedOwnerId > 0 && $designatedOwnerId !== $ownerUserId) {
                throw new RuntimeException('BOOTSTRAP_OWNER_MISMATCH');
            }

            $pinService = new PinService();
            $pinService->setPinForUser($conn, $ownerUserId, $newPin, [
                'must_change' => false,
                'bump_auth_version' => true,
            ]);

            $stmt = $conn->prepare(
                'UPDATE security_bootstrap_state
                    SET status = \'completed\',
                        completed_at = NOW(),
                        completed_by = ?,
                        owner_user_id = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = 1 AND status = \'pending\''
            );
            $stmt->bind_param('ii', $ownerUserId, $ownerUserId);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('BOOTSTRAP_COMPLETE_FAILED');
            }
            $stmt->close();
            posmain_tx_commit_if_owned($conn, $ownsTransaction);
        } catch (Throwable $exception) {
            posmain_tx_rollback_if_owned($conn, $ownsTransaction);
            throw $exception;
        }

        try {
            (new SecurityAuditLogger())->record($conn, 'owner_bootstrap_pin_changed', [
                'user_id' => $ownerUserId,
                'target_type' => 'user',
                'target_id' => $ownerUserId,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    public function resolveOwnerUserId(mysqli $conn): int
    {
        $seeded = RolePermissionSyncService::seedPresetRoles($conn);
        $ownerRoleId = (int) ($seeded['owner'] ?? 0);

        if ($ownerRoleId > 0) {
            $stmt = $conn->prepare(
                'SELECT id FROM users
                  WHERE userrole = ?
                    AND COALESCE(isdeleted, 0) != 1
                  ORDER BY id ASC
                  LIMIT 1'
            );
            $stmt->bind_param('i', $ownerRoleId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['id'];
            }
        }

        $result = $conn->query(
            'SELECT id FROM users WHERE COALESCE(isdeleted, 0) != 1 ORDER BY id ASC LIMIT 1'
        );
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

        return (int) ($row['id'] ?? 0);
    }

    private function userColumnExists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM users LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
