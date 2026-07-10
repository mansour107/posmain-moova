<?php

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/RolePermissionSyncService.php';

class PostLoginRouteService
{
    public const WORKSPACE_DASHBOARD = 'dashboard';
    public const WORKSPACE_POS = 'pos';
    public const WORKSPACE_KDS = 'kds';
    public const WORKSPACE_CHOOSER = 'chooser';
    public const WORKSPACE_NONE = 'none';
    public const WORKSPACE_PIN_CHANGE = 'pin_change';

    /**
     * @return array{workspace: string, url: string, choices?: list<array{key: string, url: string, label: string}>}
     */
    public function resolve(mysqli $conn, int $userId, array $options = []): array
    {
        if ($userId < 1) {
            return ['workspace' => self::WORKSPACE_NONE, 'url' => 'no_access.php'];
        }

        if (!empty($options['must_change_pin']) || !empty($options['bootstrap_pending'])) {
            return [
                'workspace' => self::WORKSPACE_PIN_CHANGE,
                'url' => !empty($options['bootstrap_pending']) ? 'change_pin.php?bootstrap=1' : 'change_pin.php',
            ];
        }

        $roleKey = $this->roleKeyForUser($conn, $userId);
        $permissions = $this->effectivePermissionSet($conn, $userId);

        // Owner / manager always land on dashboard (even if they also have POS/KDS).
        if (in_array($roleKey, ['owner', 'manager'], true) || $this->isAdminUser($conn, $userId, $roleKey)) {
            return ['workspace' => self::WORKSPACE_DASHBOARD, 'url' => 'dashboard.php'];
        }

        if ($roleKey === 'cashier') {
            return ['workspace' => self::WORKSPACE_POS, 'url' => $this->posEntryUrl()];
        }

        if ($roleKey === 'kitchen') {
            return ['workspace' => self::WORKSPACE_KDS, 'url' => 'kds.php'];
        }

        if ($roleKey === 'waiter') {
            return ['workspace' => self::WORKSPACE_POS, 'url' => $this->posEntryUrl()];
        }

        // Custom / mixed roles: chooser when more than one workspace is permitted.
        $choices = [];
        if (!empty($permissions['pos.open'])) {
            $choices[] = [
                'key' => self::WORKSPACE_POS,
                'url' => $this->posEntryUrl(),
                'label' => 'نقطة البيع',
            ];
        }
        if (!empty($permissions['kds.view'])) {
            $choices[] = [
                'key' => self::WORKSPACE_KDS,
                'url' => 'kds.php',
                'label' => 'شاشات المطبخ',
            ];
        }
        if (!empty($permissions['reports.view'])
            || !empty($permissions['accounting.view'])
            || !empty($permissions['users.manage'])
            || !empty($permissions['roles.manage'])
            || !empty($permissions['erp.dashboard.main_cards'])
        ) {
            $choices[] = [
                'key' => self::WORKSPACE_DASHBOARD,
                'url' => 'dashboard.php',
                'label' => 'لوحة التحكم',
            ];
        }

        if (count($choices) > 1) {
            return [
                'workspace' => self::WORKSPACE_CHOOSER,
                'url' => 'workspace.php',
                'choices' => $choices,
            ];
        }
        if (count($choices) === 1) {
            return [
                'workspace' => $choices[0]['key'],
                'url' => $choices[0]['url'],
                'choices' => $choices,
            ];
        }

        return ['workspace' => self::WORKSPACE_NONE, 'url' => 'no_access.php'];
    }

    public function resolveRedirect(mysqli $conn, int $userId, array $options = []): string
    {
        return $this->resolve($conn, $userId, $options)['url'];
    }

    public function posEntryUrl(): string
    {
        // Prefer restaurant barcode POS; supermarket/clothes share the same auth contract later.
        return 'pos_barcode.php';
    }

    private function roleKeyForUser(mysqli $conn, int $userId): string
    {
        $stmt = $conn->prepare(
            'SELECT r.role_key, r.id AS role_id, u.userrole, u.usertype
               FROM users u
          LEFT JOIN usr_pwrs r ON r.id = u.userrole AND COALESCE(r.isdeleted, 0) != 1
              WHERE u.id = ? AND COALESCE(u.isdeleted, 0) != 1
              LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return '';
        }

        $key = trim((string) ($row['role_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        // Fallback: owner role id 1 / usertype 2 historically means admin.
        if ((int) ($row['role_id'] ?? 0) === 1 || (int) ($row['usertype'] ?? 0) === 2) {
            return 'owner';
        }

        return '';
    }

    /** @return array<string, bool> */
    private function effectivePermissionSet(mysqli $conn, int $userId): array
    {
        $service = PermissionService::forConnection($conn);
        $map = [];
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }
        foreach (array_keys(auth_guard_permission_map()) as $permission) {
            try {
                $map[$permission] = $service->check($userId, $permission);
            } catch (Throwable) {
                $map[$permission] = false;
            }
        }

        return $map;
    }

    private function isAdminUser(mysqli $conn, int $userId, string $roleKey): bool
    {
        if ($roleKey === 'owner') {
            return true;
        }
        $stmt = $conn->prepare(
            'SELECT usertype, userrole FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['usertype'] ?? 0) === 2 || (int) ($row['userrole'] ?? 0) === 1;
    }
}
