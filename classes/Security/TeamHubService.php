<?php

require_once __DIR__ . '/RolePermissionSyncService.php';
require_once __DIR__ . '/PermissionService.php';

class TeamHubService
{
  /** @var array<string, string> */
  private const ROLE_COLORS = [
    'owner' => '#d4a574',
    'manager' => '#5b8def',
    'cashier' => '#4ade80',
    'waiter' => '#2dd4bf',
    'kitchen' => '#fb923c',
  ];

  private const CUSTOM_COLOR = '#9ca3af';

  /** @var array<string, string> */
  private const PERMISSION_LABELS = [
    'pos.open' => 'فتح نقطة البيع',
    'pos.sell.takeaway' => 'بيع سفري',
    'pos.table.open' => 'فتح طاولة',
    'pos.table.move' => 'نقل طاولة',
    'pos.table.merge' => 'دمج طاولات',
    'pos.payment.take' => 'تحصيل الدفع',
    'pos.discount.apply' => 'تطبيق خصم',
    'pos.discount.manager_override' => 'تجاوز خصم المدير',
    'pos.discount.manual_pct.limit' => 'حد الخصم اليدوي',
    'pos.price.override' => 'تجاوز السعر',
    'pos.recipe_stock_override' => 'تجاوز مخزون الوصفة',
    'pos.cancel.unpaid' => 'إلغاء غير مدفوع',
    'pos.void.post_send' => 'إلغاء بعد الإرسال',
    'pos.void.item_after_send' => 'إلغاء صنف بعد الإرسال',
    'pos.order.modify_others' => 'تعديل طلبات الآخرين',
    'pos.split' => 'تقسيم الفاتورة',
    'pos.shift.open' => 'فتح وردية',
    'pos.shift.close' => 'إغلاق وردية',
    'pos.shift.force_close' => 'إغلاق قسري للدرج',
    'pos.shift.force_close_others' => 'إغلاق ورديات الآخرين',
    'pos.cashdrawer.count' => 'جرد الدرج',
    'pos.drawer.no_sale' => 'فتح الدرج بدون بيع',
    'pos.drawer.payin' => 'إيداع نقدي',
    'pos.payout.over_limit' => 'صرف فوق الحد',
    'pos.drawer.payout.limit' => 'حد الصرف من الدرج',
    'pos.credit.sale' => 'بيع آجل',
    'pos.credit.sell' => 'بيع بالآجل',
    'pos.reprint' => 'إعادة طباعة',
    'pos.refund.limit' => 'حد الاسترجاع',
    'pos.void.paid' => 'إلغاء مدفوع',
    'pos.refund' => 'استرجاع',
    'menu.edit' => 'تعديل القائمة',
    'inventory.edit' => 'تعديل المخزون',
    'inventory.approve' => 'اعتماد المخزون',
    'moova.manage' => 'إدارة Moova',
    'moova.accept' => 'قبول طلبات Moova',
    'delivery.dispatch' => 'توزيع التوصيل',
    'delivery.zones.manage' => 'مناطق التوصيل',
    'kds.view' => 'عرض المطبخ',
    'kds.complete' => 'إكمال طلبات المطبخ',
    'kds.manage' => 'إدارة شاشة المطبخ',
    'accounting.view' => 'عرض المحاسبة',
    'reports.view' => 'عرض التقارير',
    'reports.own_shift' => 'تقرير ورديتي',
    'reports.branch_daily' => 'تقرير يومي للفرع',
    'reports.costs' => 'تقرير التكاليف',
    'users.manage' => 'إدارة الموظفين',
    'roles.manage' => 'إدارة الأدوار',
    'customers.manage' => 'إدارة العملاء',
    'system.health.view' => 'صحة النظام',
    'system.tools.run' => 'أدوات النظام',
  ];

  /** @var array<string, string> */
  private const GROUP_LABELS = [
    'POS' => 'نقطة البيع',
    'Inventory & menu' => 'المخزون والقائمة',
    'Delivery & KDS' => 'التوصيل والمطبخ',
    'Accounting & reports' => 'المحاسبة والتقارير',
    'Administration' => 'الإدارة',
  ];

  public function __construct(private mysqli $conn)
  {
  }

  public static function roleColor(?string $roleKey): string
  {
    $key = strtolower(trim((string) $roleKey));

    return self::ROLE_COLORS[$key] ?? self::CUSTOM_COLOR;
  }

  public static function permissionLabel(string $permission): string
  {
    return self::PERMISSION_LABELS[$permission] ?? $permission;
  }

  public static function groupLabel(string $group): string
  {
    return self::GROUP_LABELS[$group] ?? $group;
  }

  /** @return list<array<string, mixed>> */
  public function staffList(bool $includeDeactivated = false, bool $revealPins = false, bool $adminRevealOnly = true): array
  {
    // Reversible PIN reveal is retired. $revealPins is accepted for API compatibility only.
    unset($revealPins, $adminRevealOnly);
    $where = $includeDeactivated ? '1=1' : 'COALESCE(u.isdeleted, 0) != 1';
    $sql = "SELECT u.id, u.uname, u.display_name, u.phone, u.userrole, u.img, u.is_waiter,
                   u.pin_set_at, u.pin_locked_until, COALESCE(u.isdeleted, 0) AS isdeleted,
                   r.rollname AS role_name, r.role_key
            FROM users u
            LEFT JOIN usr_pwrs r ON r.id = u.userrole
            WHERE {$where}
            ORDER BY COALESCE(u.isdeleted, 0) ASC, u.id DESC";
    $result = $this->conn->query($sql);
    if (!$result) {
      return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
      $display = trim((string) ($row['display_name'] ?? ''));
      if ($display === '') {
        $display = (string) ($row['uname'] ?? '');
      }
      $initial = mb_substr($display, 0, 1, 'UTF-8');
      $pinLocked = !empty($row['pin_locked_until']) && strtotime((string) $row['pin_locked_until']) > time();
      $hasPin = !empty($row['pin_set_at']);
      $pinDisplay = null;
      $rows[] = [
        'id' => (int) $row['id'],
        'uname' => (string) ($row['uname'] ?? ''),
        'display_name' => $display,
        'phone' => (string) ($row['phone'] ?? ''),
        'role_id' => (int) ($row['userrole'] ?? 0),
        'role_name' => (string) ($row['role_name'] ?? '—'),
        'role_key' => (string) ($row['role_key'] ?? ''),
        'role_color' => self::roleColor($row['role_key'] ?? ''),
        'initial' => $initial !== '' ? $initial : '?',
        'img' => (string) ($row['img'] ?? ''),
        'has_pin' => $hasPin,
        'pin_display' => $pinDisplay,
        'pin_locked' => $pinLocked,
        'is_deactivated' => (int) ($row['isdeleted'] ?? 0) === 1,
        'is_waiter' => (int) ($row['is_waiter'] ?? 0) === 1,
      ];
    }

    return $rows;
  }

  /** @return list<array<string, mixed>> */
  public function loadRoles(): array
  {
    RolePermissionSyncService::seedPresetRoles($this->conn);
    $permissionService = new PermissionService($this->conn);

    $sql = "SELECT p.id, p.rollname, p.info, p.role_key, COALESCE(p.is_system, 0) AS is_system,
                   (SELECT COUNT(*) FROM users u WHERE u.userrole = p.id AND COALESCE(u.isdeleted, 0) != 1) AS staff_count
            FROM usr_pwrs p
            WHERE COALESCE(p.isdeleted, 0) != 1
            ORDER BY FIELD(COALESCE(p.role_key, ''), 'owner', 'manager', 'cashier', 'waiter', 'kitchen', ''), p.id";
    $result = $this->conn->query($sql);
    if (!$result) {
      return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
      $roleId = (int) $row['id'];
      $flagsStmt = $this->conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
      $flagsStmt->bind_param('i', $roleId);
      $flagsStmt->execute();
      $flags = $flagsStmt->get_result()->fetch_assoc() ?: [];
      $flagsStmt->close();
      $enabled = RolePermissionSyncService::enabledPermissionsFromRoleFlags($flags, $this->conn);

      $rows[] = [
        'id' => $roleId,
        'name' => (string) ($row['rollname'] ?? ''),
        'info' => (string) ($row['info'] ?? ''),
        'role_key' => (string) ($row['role_key'] ?? ''),
        'color' => self::roleColor($row['role_key'] ?? ''),
        'is_preset' => trim((string) ($row['role_key'] ?? '')) !== '',
        'is_owner' => $permissionService->isOwnerRole($roleId),
        'is_locked' => $permissionService->isOwnerRole($roleId),
        'staff_count' => (int) ($row['staff_count'] ?? 0),
        'permission_count' => count($enabled),
        'can_delete' => !$permissionService->isSystemRole($roleId) && (int) ($row['staff_count'] ?? 0) === 0,
      ];
    }

    return $rows;
  }

  /** @return array<string, mixed> */
  public function roleDetail(int $roleId): array
  {
    $stmt = $this->conn->prepare(
      'SELECT id, rollname, info, role_key FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
    );
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      throw new RuntimeException('ROLE_NOT_FOUND');
    }

    $flagsStmt = $this->conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
    $flagsStmt->bind_param('i', $roleId);
    $flagsStmt->execute();
    $flags = $flagsStmt->get_result()->fetch_assoc() ?: [];
    $flagsStmt->close();

    $permissionService = new PermissionService($this->conn);
    $enabled = RolePermissionSyncService::enabledPermissionsFromRoleFlags($flags, $this->conn);
    $limits = $permissionService->roleCapabilityLimits($roleId);
    $groups = [];

    foreach (RolePermissionSyncService::permissionGroups() as $groupKey => $permissions) {
      $items = [];
      $enabledCount = 0;
      foreach ($permissions as $permission) {
        $isOn = in_array($permission, $enabled, true);
        if ($isOn) {
          $enabledCount++;
        }
        $limitRow = $limits[$permission] ?? null;
        $items[] = [
          'key' => $permission,
          'label' => self::permissionLabel($permission),
          'enabled' => $isOn,
          'has_limit' => in_array($permission, RolePermissionSyncService::limitablePermissions(), true),
          'is_unlimited' => $limitRow === null || !empty($limitRow['is_unlimited']),
          'limit_value' => $limitRow['limit_value'] ?? null,
        ];
      }
      $groups[] = [
        'key' => $groupKey,
        'label' => self::groupLabel($groupKey),
        'enabled_count' => $enabledCount,
        'total_count' => count($items),
        'permissions' => $items,
      ];
    }

    return [
      'id' => $roleId,
      'name' => (string) $row['rollname'],
      'info' => (string) ($row['info'] ?? ''),
      'role_key' => (string) ($row['role_key'] ?? ''),
      'color' => self::roleColor($row['role_key'] ?? ''),
      'is_owner' => $permissionService->isOwnerRole($roleId),
      'is_readonly' => $permissionService->isOwnerRole($roleId),
      'is_preset' => trim((string) ($row['role_key'] ?? '')) !== '',
      'enabled_count' => count($enabled),
      'can_delete' => !$permissionService->isSystemRole($roleId)
        && !$permissionService->isOwnerRole($roleId)
        && $this->countActiveStaffForRole($roleId) === 0,
      'groups' => $groups,
      'clone_templates' => array_keys(RolePermissionSyncService::presetRoleDefinitions()),
    ];
  }

  /** @return array<string, mixed>|null */
  public function staffDetail(int $userId, ?bool $revealPin = null): ?array
  {
    // Reversible PIN reveal is retired; reset-and-display-once is the only reveal path.
    unset($revealPin);
    $stmt = $this->conn->prepare(
      "SELECT u.*, r.rollname AS role_name, r.role_key
       FROM users u
       LEFT JOIN usr_pwrs r ON r.id = u.userrole
       WHERE u.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      return null;
    }

    $display = trim((string) ($row['display_name'] ?? ''));
    if ($display === '') {
      $display = (string) ($row['uname'] ?? '');
    }

    $pinLocked = !empty($row['pin_locked_until']) && strtotime((string) $row['pin_locked_until']) > time();
    $hasPin = !empty($row['pin_set_at']);
    $pinDisplay = null;

    return [
      'id' => (int) $row['id'],
      'uname' => (string) ($row['uname'] ?? ''),
      'display_name' => $display,
      'phone' => (string) ($row['phone'] ?? ''),
      'role_id' => (int) ($row['userrole'] ?? 0),
      'role_name' => (string) ($row['role_name'] ?? ''),
      'role_key' => (string) ($row['role_key'] ?? ''),
      'has_pin' => $hasPin,
      'pin_display' => $pinDisplay,
      'pin_locked' => $pinLocked,
      'is_deactivated' => (int) ($row['isdeleted'] ?? 0) === 1,
      'is_waiter' => (int) ($row['is_waiter'] ?? 0) === 1,
      'permission_mode' => (string) ($row['permission_mode'] ?? 'role_only'),
      'uses_overrides' => ($row['permission_mode'] ?? 'role_only') === 'role_with_overrides',
    ];
  }

  /** @return array<string, mixed> */
  public function userPermissionsDetail(int $userId): array
  {
    $staff = $this->staffDetail($userId);
    if (!$staff) {
      throw new RuntimeException('USER_NOT_FOUND');
    }

    require_once __DIR__ . '/UserPermissionGrantService.php';
    $grantService = new UserPermissionGrantService();
    $usesOverrides = $grantService->userUsesOverrides($this->conn, $userId);
    $overrides = $usesOverrides ? $grantService->activeOverridesForUser($this->conn, $userId) : [];

    $roleId = (int) ($staff['role_id'] ?? 0);
    $roleEnabled = [];
    if ($roleId > 0) {
      $flagsStmt = $this->conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
      $flagsStmt->bind_param('i', $roleId);
      $flagsStmt->execute();
      $flags = $flagsStmt->get_result()->fetch_assoc() ?: [];
      $flagsStmt->close();
      $roleEnabled = RolePermissionSyncService::enabledPermissionsFromRoleFlags($flags, $this->conn);
    }

    $permissionService = new PermissionService($this->conn);
    $effective = $permissionService->effectivePermissionsForUser($userId, $roleId);

    $groups = [];
    $enabledCount = 0;
    foreach (RolePermissionSyncService::permissionGroups() as $groupKey => $permissions) {
      $items = [];
      foreach ($permissions as $permission) {
        $fromRole = in_array($permission, $roleEnabled, true);
        $override = $overrides[$permission] ?? null;
        $enabled = !empty($effective[$permission]);
        if ($enabled) {
          $enabledCount++;
        }
        $items[] = [
          'key' => $permission,
          'label' => self::permissionLabel($permission),
          'from_role' => $fromRole,
          'override' => $override,
          'enabled' => $enabled,
          'customized' => $enabled !== $fromRole,
        ];
      }
      if ($items === []) {
        continue;
      }
      $groups[] = [
        'key' => $groupKey,
        'label' => self::groupLabel($groupKey),
        'permissions' => $items,
      ];
    }

    return [
      'user_id' => $userId,
      'display_name' => $staff['display_name'],
      'role_name' => $staff['role_name'],
      'uses_overrides' => $usesOverrides,
      'permission_mode' => $staff['permission_mode'] ?? 'role_only',
      'enabled_count' => $enabledCount,
      'groups' => $groups,
    ];
  }

  /** @return list<array<string, mixed>> */
  public function staffLifecycleBlockers(int $userId): array
  {
    if ($userId < 1) {
      return [];
    }

    require_once __DIR__ . '/UserLifecycleGuardService.php';
    $guard = new UserLifecycleGuardService();
    $openSessions = $guard->findOpenDrawerSessionsForUser($this->conn, $userId);
    if ($openSessions === []) {
      return [];
    }

    return [[
      'code' => 'DRAWER_SESSION_OPEN',
      'drawer_sessions' => array_map(static function (array $session): array {
        return [
          'id' => (int) ($session['id'] ?? 0),
          'opened_at' => (string) ($session['opened_at'] ?? ''),
          'tenant' => (int) ($session['tenant'] ?? 0),
          'branch' => (int) ($session['branch'] ?? 0),
        ];
      }, $openSessions),
    ]];
  }

  public function stats(): array
  {
    $roles = $this->loadRoles();
    $staff = $this->staffList();
    $activeStaff = array_filter($staff, static fn(array $s): bool => !$s['is_deactivated']);

    return [
      'role_count' => count($roles),
      'preset_count' => count(array_filter($roles, static fn(array $r): bool => $r['is_preset'])),
      'staff_count' => count($activeStaff),
    ];
  }

  private function countActiveStaffForRole(int $roleId): int
  {
    $stmt = $this->conn->prepare(
      'SELECT COUNT(*) AS c FROM users WHERE userrole = ? AND COALESCE(isdeleted, 0) != 1'
    );
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
  }

  public static function slugifyUsername(string $name): string
  {
    $ascii = strtolower(trim(preg_replace('/\s+/', '_', $name) ?? ''));
    $ascii = preg_replace('/[^a-z0-9_]/', '', $ascii) ?? '';
    $ascii = trim($ascii, '_');
    if ($ascii === '' || !preg_match('/^[a-z]/', $ascii)) {
      $ascii = 'user_' . substr((string) time(), -6) . '_' . substr(md5($name), 0, 4);
    }

    return $ascii;
  }
}
