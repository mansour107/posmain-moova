<?php

require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/RolePermissionSyncService.php';

class PostLoginRouteService
{
    public const WORKSPACE_DASHBOARD = 'dashboard';
    public const WORKSPACE_BACKOFFICE = 'backoffice';
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

        if (!empty($options['bootstrap_pending'])) {
            return [
                'workspace' => self::WORKSPACE_PIN_CHANGE,
                'url' => 'change_pin.php?bootstrap=1',
            ];
        }

        $roleKey = $this->roleKeyForUser($conn, $userId);

        // Frontline presets stay on their lane regardless of extra grants.
        if ($roleKey === 'cashier') {
            return ['workspace' => self::WORKSPACE_POS, 'url' => $this->posEntryUrl()];
        }

        if ($roleKey === 'kitchen') {
            return ['workspace' => self::WORKSPACE_KDS, 'url' => 'kds.php'];
        }

        if ($roleKey === 'waiter') {
            return ['workspace' => self::WORKSPACE_POS, 'url' => $this->posEntryUrl()];
        }

        $permissions = $this->effectivePermissionSet($conn, $userId);

        return $this->resolveBestLanding($permissions);
    }

    /**
     * Pure permission → landing resolver (back-office first, then POS/KDS).
     *
     * @param array<string, bool> $permissions
     * @return array{workspace: string, url: string, choices?: list<array{key: string, url: string, label: string}>}
     */
    public function resolveBestLanding(array $permissions): array
    {
        $backOffice = $this->firstBackOfficeLanding($permissions);
        if ($backOffice !== null) {
            return $backOffice;
        }

        $hasPos = !empty($permissions['pos.open']);
        $hasKds = !empty($permissions['kds.view']);

        if ($hasPos && $hasKds) {
            $choices = [
                [
                    'key' => self::WORKSPACE_POS,
                    'url' => $this->posEntryUrl(),
                    'label' => 'نقطة البيع',
                ],
                [
                    'key' => self::WORKSPACE_KDS,
                    'url' => 'kds.php',
                    'label' => 'شاشات المطبخ',
                ],
            ];

            return [
                'workspace' => self::WORKSPACE_CHOOSER,
                'url' => 'workspace.php',
                'choices' => $choices,
            ];
        }

        if ($hasPos) {
            return ['workspace' => self::WORKSPACE_POS, 'url' => $this->posEntryUrl()];
        }

        if ($hasKds) {
            return ['workspace' => self::WORKSPACE_KDS, 'url' => 'kds.php'];
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

    /**
     * @param array<string, bool> $permissions
     * @return array{workspace: string, url: string}|null
     */
    private function firstBackOfficeLanding(array $permissions): ?array
    {
        $has = static function (string $key) use ($permissions): bool {
            return !empty($permissions[$key]);
        };

        if ($has('erp.dashboard.main_cards')
            || $has('erp.dashboard.main_elements')
            || $has('erp.dashboard.main_tables')
        ) {
            return ['workspace' => self::WORKSPACE_DASHBOARD, 'url' => 'dashboard.php'];
        }

        if ($has('system.tools.run')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'setting.php'];
        }

        if ($has('users.manage') || $has('roles.manage')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'team.php'];
        }

        if ($has('reports.view')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'cash_flow_report.php?tab=overview'];
        }

        if ($has('reports.cash_flow')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'cash_flow_report.php'];
        }

        if ($has('accounting.view')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'daily_journal.php'];
        }

        if ($has('inventory.edit')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'inventory_dashboard.php'];
        }

        if ($has('menu.edit')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'myitems.php'];
        }

        if ($has('delivery.dispatch')) {
            return ['workspace' => self::WORKSPACE_BACKOFFICE, 'url' => 'delivery_board.php'];
        }

        return null;
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
}
