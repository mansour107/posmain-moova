<?php

class UserLifecycleGuardService
{
    public function assertNoPrivilegeEscalation(
        mysqli $conn,
        int $actorUserId,
        ?int $targetUserId = null,
        ?int $targetRoleId = null
    ): void {
        if ($actorUserId < 1 || $this->isAdminActor($conn, $actorUserId)) {
            return;
        }

        if ($targetRoleId !== null && $targetRoleId > 0 && $this->isAdminRole($conn, $targetRoleId)) {
            throw new RuntimeException('PRIVILEGE_ESCALATION_BLOCKED');
        }

        if ($targetUserId !== null && $targetUserId > 0) {
            if ($this->isAdminUser($conn, $targetUserId)) {
                throw new RuntimeException('PRIVILEGE_ESCALATION_BLOCKED');
            }
            if ($this->userHasUsersManage($conn, $targetUserId)) {
                throw new RuntimeException('PRIVILEGE_ESCALATION_BLOCKED');
            }
        }
    }

    public static function privilegeEscalationMessage(string $code): string
    {
        if ($code === 'PRIVILEGE_ESCALATION_BLOCKED') {
            return 'لا يمكنك إدارة مستخدم بصلاحيات مساوية أو أعلى من صلاحياتك';
        }

        return $code;
    }
    public function assertDisplayNameUnique(mysqli $conn, string $displayName, ?int $excludeUserId = null): void
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return;
        }

        if (!$this->columnExists($conn, 'display_name')) {
            return;
        }

        $sql = 'SELECT id FROM users WHERE display_name = ? AND COALESCE(isdeleted, 0) != 1';
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $sql .= ' AND id != ?';
        }
        $sql .= ' LIMIT 1';

        $stmt = $conn->prepare($sql);
        if ($excludeUserId !== null && $excludeUserId > 0) {
            $stmt->bind_param('si', $displayName, $excludeUserId);
        } else {
            $stmt->bind_param('s', $displayName);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            throw new RuntimeException('DISPLAY_NAME_TAKEN');
        }
    }

    public function assertNotLastAdmin(mysqli $conn, int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c
               FROM users u
              INNER JOIN usr_pwrs r ON r.id = u.userrole
              WHERE COALESCE(u.isdeleted, 0) != 1
                AND COALESCE(r.isdeleted, 0) != 1
                AND (r.id = 1 OR r.role_key = 'owner' OR LOWER(r.rollname) LIKE '%admin%')"
        );
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        if ($count > 1) {
            return;
        }

        $stmt = $conn->prepare(
            "SELECT 1
               FROM users u
              INNER JOIN usr_pwrs r ON r.id = u.userrole
              WHERE u.id = ?
                AND COALESCE(u.isdeleted, 0) != 1
                AND (r.id = 1 OR r.role_key = 'owner' OR LOWER(r.rollname) LIKE '%admin%')
              LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $isAdmin = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($isAdmin) {
            throw new RuntimeException('LAST_ADMIN_BLOCKED');
        }
    }

    public function assertNoOpenDrawerForUser(mysqli $conn, int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $result = $conn->query("SHOW TABLES LIKE 'drawer_sessions'");
        if (!$result || $result->num_rows < 1) {
            return;
        }

        $stmt = $conn->prepare(
            "SELECT 1 FROM drawer_sessions WHERE user_id = ? AND status = 'open' LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $open = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($open) {
            throw new RuntimeException('DRAWER_SESSION_OPEN');
        }
    }

    public function softDeleteUser(mysqli $conn, int $userId): void
    {
        $this->assertNotLastAdmin($conn, $userId);
        $this->assertNoOpenDrawerForUser($conn, $userId);

        $stmt = $conn->prepare('UPDATE users SET isdeleted = 1 WHERE id = ? AND COALESCE(isdeleted, 0) != 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function columnExists(mysqli $conn, string $column): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function isAdminActor(mysqli $conn, int $userId): bool
    {
        if ($userId === 1) {
            return true;
        }

        return $this->isAdminUser($conn, $userId);
    }

    private function isAdminUser(mysqli $conn, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $stmt = $conn->prepare(
            "SELECT u.id, u.userrole, r.role_key, r.rollname
               FROM users u
              INNER JOIN usr_pwrs r ON r.id = u.userrole
              WHERE u.id = ?
                AND COALESCE(u.isdeleted, 0) != 1
                AND COALESCE(r.isdeleted, 0) != 1
              LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return $this->isAdminRole(
            $conn,
            (int) ($row['userrole'] ?? 0),
            (string) ($row['role_key'] ?? ''),
            (string) ($row['rollname'] ?? '')
        );
    }

    private function isAdminRole(mysqli $conn, int $roleId, ?string $roleKey = null, ?string $rollname = null): bool
    {
        if ($roleId === 1) {
            return true;
        }

        if ($roleKey === null || $rollname === null) {
            $stmt = $conn->prepare(
                'SELECT role_key, rollname FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
            );
            $stmt->bind_param('i', $roleId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $roleKey = (string) ($row['role_key'] ?? '');
            $rollname = (string) ($row['rollname'] ?? '');
        }

        if (strtolower($roleKey) === 'owner') {
            return true;
        }

        return stripos($rollname, 'admin') !== false || stripos($rollname, 'مالك') !== false;
    }

    private function userHasUsersManage(mysqli $conn, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/PermissionService.php';
        }

        return (new PermissionService($conn))->check($userId, 'users.manage');
    }
}
