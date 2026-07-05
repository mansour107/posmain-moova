<?php

use PHPUnit\Framework\TestCase;

$projectRoot = dirname(__DIR__, 2);

require_once $projectRoot . '/includes/session_bootstrap.php';
require_once $projectRoot . '/includes/auth_guard.php';
require_once $projectRoot . '/classes/Security/PinService.php';
require_once $projectRoot . '/classes/Security/PermissionService.php';
require_once $projectRoot . '/classes/Security/UserLifecycleGuardService.php';
require_once $projectRoot . '/classes/Security/UserPermissionGrantService.php';
require_once $projectRoot . '/classes/Security/RolePermissionSyncService.php';
require_once $projectRoot . '/classes/Security/SecurityAuditLogger.php';
require_once $projectRoot . '/classes/Pos/Service/ManagerApprovalService.php';
require_once $projectRoot . '/classes/Pos/Service/PosOrderMutationService.php';

final class ProductionRbacPinTest extends TestCase
{
    private static ?mysqli $conn = null;

    public static function setUpBeforeClass(): void
    {
        if (trim((string) getenv('POSMAIN_PIN_SECRET')) === '') {
            putenv('POSMAIN_PIN_SECRET=posmain-test-pin-secret-do-not-use-in-prod');
        }

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
        $db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

        self::$conn = @new mysqli($host, $user, $pass, $db, $port);
        if (self::$conn->connect_error) {
            self::$conn = null;

            return;
        }

        require_once dirname(__DIR__, 2) . '/classes/Sync/SchemaManager.php';
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database unavailable');
        }
        $_SESSION = [];
        if (function_exists('auth_guard_invalidate_capabilities_cache')) {
            auth_guard_invalidate_capabilities_cache();
        }
    }

    public function test_pin_secret_available(): void
    {
        $this->assertNotEmpty(posmain_pin_secret());
    }

    public function test_pin_normalize_strips_non_digits(): void
    {
        $svc = new PinService();
        $this->assertSame('1234', $svc->normalizePin('12-34'));
    }

    public function test_pin_blacklist_rejects_1234(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PinService())->validatePinFormat('1234');
    }

    public function test_pin_lookup_is_deterministic(): void
    {
        $svc = new PinService();
        $this->assertSame($svc->pinLookup('2468'), $svc->pinLookup('2468'));
    }

    public function test_pin_hash_and_verify(): void
    {
        $svc = new PinService();
        $hash = $svc->hashPin('2468');
        $this->assertTrue($svc->verifyPin('2468', $hash));
        $this->assertFalse($svc->verifyPin('2469', $hash));
    }

    public function test_pos_acting_user_falls_back_to_terminal(): void
    {
        $_SESSION['userid'] = 5;
        $_SESSION['login'] = 'terminal';
        $this->assertSame(5, pos_acting_user_id());
    }

    public function test_pos_acting_user_prefers_acting_session(): void
    {
        $_SESSION['userid'] = 5;
        $_SESSION['pos_acting_user_id'] = 9;
        $this->assertSame(9, pos_acting_user_id());
    }

    public function test_pos_barcode_unlock_allows_acting_user_mismatch(): void
    {
        $_SESSION['userid'] = 1;
        $_SESSION['login'] = 'terminal';
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = 9;
        $_SESSION['pos_acting_user_id'] = 9;
        $this->assertTrue(auth_guard_is_pos_barcode_unlocked());
    }

    public function test_permission_service_reads_version(): void
    {
        $svc = new PermissionService(self::$conn);
        $this->assertMatchesRegularExpression('/^\d+$/', $svc->permissionsVersion());
    }

    public function test_preset_roles_seeded(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $this->assertArrayHasKey('cashier', $seeded);
        $this->assertArrayHasKey('manager', $seeded);
    }

    public function test_role_capability_limits_for_cashier(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $limits = (new PermissionService(self::$conn))->roleCapabilityLimits((int) $seeded['cashier']);
        $this->assertArrayHasKey('pos.discount.apply', $limits);
        $this->assertFalse($limits['pos.discount.apply']['is_unlimited']);
    }

    public function test_system_role_detection(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $this->assertTrue((new PermissionService(self::$conn))->isSystemRole((int) $seeded['owner']));
    }

    public function test_pin_set_find_and_clear_roundtrip(): void
    {
        $svc = new PinService();
        $uname = 'pin_test_' . bin2hex(random_bytes(4));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, 1, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('sss', $uname, $pass, $img);
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        $pin = '9' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        while (strlen($pin) < 4 || $pin === '2468') {
            $pin = (string) random_int(1000, 9999);
        }

        $svc->setPinForUser(self::$conn, $id, $pin);
        $this->assertTrue($svc->anyActiveUserHasPin(self::$conn));
        $found = $svc->findUserByPin(self::$conn, $pin);
        $this->assertSame($id, (int) ($found['id'] ?? 0));

        $svc->clearPinForUser(self::$conn, $id);
        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $id);
    }

    public function test_manager_approval_request_has_expiry(): void
    {
        $approval = (new ManagerApprovalService())->requestApproval(self::$conn, [
            'action_type' => 'discount.override',
            'target_type' => 'order',
            'target_id' => 1,
            'requested_by' => 1,
            'permission_key' => 'pos.discount.manager_override',
        ]);
        $this->assertNotEmpty($approval['expires_at']);
        $expiresIn = strtotime((string) $approval['expires_at']) - time();
        $this->assertGreaterThanOrEqual(85, $expiresIn);
        $this->assertLessThanOrEqual(95, $expiresIn);
    }

    public function test_manager_approval_consume_marks_consumed(): void
    {
        $service = new ManagerApprovalService();
        $approval = $service->requestApproval(self::$conn, [
            'action_type' => 'test.consume',
            'target_type' => 'order',
            'requested_by' => 1,
        ]);
        $service->decide(self::$conn, (int) $approval['id'], ['approved_by' => 1, 'status' => 'approved']);
        $consumed = $service->consumeApproval(self::$conn, (int) $approval['id'], 1);
        $this->assertNotEmpty($consumed['consumed_at']);
    }

    public function test_display_name_unique_guard(): void
    {
        $guard = new UserLifecycleGuardService();
        $name = 'Unique Name ' . bin2hex(random_bytes(3));
        $guard->assertDisplayNameUnique(self::$conn, $name);
        $this->assertTrue(true);
    }

    public function test_capabilities_version_includes_permissions_version(): void
    {
        $hash = auth_guard_capabilities_version(self::$conn);
        $this->assertSame(64, strlen($hash));
    }

    public function test_pin_login_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/pos_pin_login.php');
    }

    public function test_pos_lock_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/pos_lock.php');
    }

    public function test_pin_available_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/pin_available.php');
    }

    public function test_override_auth_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/pos_override_auth.php');
    }

    public function test_rbac_manifest_includes_pin_routes(): void
    {
        $manifest = require dirname(__DIR__, 2) . '/config/rbac_route_manifest.php';
        $this->assertArrayHasKey('ajax/pos_pin_login.php', $manifest);
        $this->assertArrayHasKey('ajax/pos_override_auth.php', $manifest);
    }

    public function test_role_capabilities_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/role_capabilities.php');
    }

    public function test_user_permissions_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/user_permissions.php');
    }

    public function test_audit_logger_does_not_reference_pin_literal_in_source(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/classes/Security/SecurityAuditLogger.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("'pin'", strtolower($source));
    }

    public function test_permission_service_check_unknown_key_throws(): void
    {
        $svc = new PermissionService(self::$conn);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PERMISSION_KEY_UNKNOWN');
        $svc->check(1, 'not.a.real.permission');
    }

    public function test_permission_service_check_amount_respects_cashier_discount_limit(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];

        $uname = 'limit_test_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $cashierRoleId, $img);
        $stmt->execute();
        $userId = (int) self::$conn->insert_id;
        $stmt->close();

        $svc = new PermissionService(self::$conn);
        $this->assertTrue($svc->checkAmount($userId, 'pos.discount.apply', 10.0, $cashierRoleId));
        $this->assertFalse($svc->checkAmount($userId, 'pos.discount.apply', 15.0, $cashierRoleId));

        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $userId);
    }

    public function test_role_capability_denies_when_disabled(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];

        $stmt = self::$conn->prepare(
            "INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited, tenant, branch)
             VALUES (?, 'pos.discount.apply', 0, NULL, 1, 0, 0)
             ON DUPLICATE KEY UPDATE is_enabled = 0"
        );
        $stmt->bind_param('i', $cashierRoleId);
        $stmt->execute();
        $stmt->close();

        $roleStmt = self::$conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
        $roleStmt->bind_param('i', $cashierRoleId);
        $roleStmt->execute();
        $roleFlags = $roleStmt->get_result()->fetch_assoc() ?: [];
        $roleStmt->close();

        $this->assertFalse(auth_guard_session_has_permission(
            'pos.discount.apply',
            $roleFlags,
            ['userid' => 99, 'login' => true, 'usrole' => $cashierRoleId],
            self::$conn
        ));

        $stmt = self::$conn->prepare(
            "UPDATE role_capabilities SET is_enabled = 1 WHERE role_id = ? AND permission_key = 'pos.discount.apply'"
        );
        $stmt->bind_param('i', $cashierRoleId);
        $stmt->execute();
        $stmt->close();
    }

    public function test_restore_preset_role_is_idempotent(): void
    {
        $first = RolePermissionSyncService::restorePresetRole(self::$conn, 'cashier');
        $second = RolePermissionSyncService::restorePresetRole(self::$conn, 'cashier');
        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $first);
    }

    public function test_admin_role_immutable_constant(): void
    {
        $this->assertTrue(PermissionService::ADMIN_ROLE_IMMUTABLE);
    }

    public function test_pos_override_csrf_scope_in_manifest(): void
    {
        $manifest = require dirname(__DIR__, 2) . '/config/rbac_route_manifest.php';
        $this->assertSame('pos_override', $manifest['ajax/pos_override_auth.php']['csrf'] ?? null);
    }

    public function test_lifecycle_handlers_exist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root . '/do/do_user_deactivate.php');
        $this->assertFileExists($root . '/do/do_user_reactivate.php');
        $this->assertFileExists($root . '/do/do_user_reset_pin.php');
        $this->assertFileExists($root . '/do/do_user_unlock_pin.php');
    }

    public function test_schema_manager_idempotent_second_apply(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/Sync/SchemaManager.php';
        $mgr = new SyncSchemaManager();
        $mgr->apply(self::$conn);
        $afterFirst = $mgr->pendingStatements(self::$conn);
        $mgr->apply(self::$conn);
        $afterSecond = $mgr->pendingStatements(self::$conn);
        $this->assertSame($afterFirst, $afterSecond);
    }

    public function test_last_admin_guard_blocks_deactivate(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $ownerRoleId = (int) ($seeded['owner'] ?? 1);
        $soleAdminId = $this->createTestUser($ownerRoleId);

        $hidden = [];
        $res = self::$conn->query(
            "SELECT u.id FROM users u
              INNER JOIN usr_pwrs r ON r.id = u.userrole
              WHERE COALESCE(u.isdeleted, 0) != 1
                AND u.id != " . (int) $soleAdminId . "
                AND (r.id = 1 OR r.role_key = 'owner' OR LOWER(r.rollname) LIKE '%admin%')"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $hidden[] = (int) $row['id'];
            }
        }
        foreach ($hidden as $hid) {
            self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $hid);
        }

        try {
            $guard = new UserLifecycleGuardService();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('LAST_ADMIN_BLOCKED');
            $guard->assertNotLastAdmin(self::$conn, $soleAdminId);
        } finally {
            foreach ($hidden as $hid) {
                self::$conn->query('UPDATE users SET isdeleted = 0 WHERE id = ' . $hid);
            }
            $this->cleanupTestUser($soleAdminId);
        }
    }

    public function test_manager_approval_expired_is_rejected(): void
    {
        $service = new ManagerApprovalService();
        $approval = $service->requestApproval(self::$conn, [
            'action_type' => 'pos.refund',
            'target_type' => 'order',
            'target_id' => 99,
            'requested_by' => 1,
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $service->decide(self::$conn, (int) $approval['id'], ['approved_by' => 1, 'status' => 'approved']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPROVAL_EXPIRED');
        $service->consumeApproval(self::$conn, (int) $approval['id'], 1);
    }

    public function test_manager_approval_consume_twice_fails(): void
    {
        $service = new ManagerApprovalService();
        $approval = $service->requestApproval(self::$conn, [
            'action_type' => 'test.consume2',
            'target_type' => 'order',
            'requested_by' => 1,
        ]);
        $service->decide(self::$conn, (int) $approval['id'], ['approved_by' => 1, 'status' => 'approved']);
        $service->consumeApproval(self::$conn, (int) $approval['id'], 1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPROVAL_ALREADY_CONSUMED');
        $service->consumeApproval(self::$conn, (int) $approval['id'], 1);
    }

    public function test_pin_lockout_count_increments_on_lock(): void
    {
        $svc = new PinService();
        $uname = 'lockout_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted, pin_lockout_count) VALUES (?, ?, 1, 1, ?, 0, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('sss', $uname, $pass, $img);
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        for ($i = 0; $i < 5; $i++) {
            $svc->recordUserFailure(self::$conn, $id);
        }
        $row = self::$conn->query('SELECT pin_lockout_count, pin_locked_until FROM users WHERE id = ' . $id)->fetch_assoc();
        $this->assertGreaterThanOrEqual(1, (int) ($row['pin_lockout_count'] ?? 0));
        $this->assertNotEmpty($row['pin_locked_until']);
        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $id);
    }

    public function test_pos_refund_limit_permission_known(): void
    {
        $svc = new PermissionService(self::$conn);
        $svc->assertKnownPermissionKey('pos.refund.limit');
        $this->addToAssertionCount(1);
    }

    public function test_acting_user_limits_include_payout(): void
    {
        if (!function_exists('posmain_acting_user_limits')) {
            require_once dirname(__DIR__, 2) . '/includes/layout_capabilities.php';
        }
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $limits = posmain_acting_user_limits(self::$conn, (int) $seeded['cashier']);
        $this->assertArrayHasKey('pos.payout.over_limit', $limits);
    }

    public function test_drawer_no_sale_endpoint_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/ajax/pos_drawer_no_sale.php');
    }

    public function test_force_close_drawer_handler_exists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2) . '/do/do_force_close_drawer.php');
    }

    public function test_escalation_permission_keys_known(): void
    {
        $svc = new PermissionService(self::$conn);
        foreach ([
            'pos.price.override',
            'pos.drawer.no_sale',
            'pos.payout.over_limit',
            'pos.credit.sale',
            'pos.shift.force_close',
        ] as $key) {
            $svc->assertKnownPermissionKey($key);
        }
        $this->addToAssertionCount(1);
    }

    public function test_payout_limit_blocks_amount_over_ceiling(): void
    {
        RolePermissionSyncService::restorePresetRole(self::$conn, 'cashier');
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $uname = 'payout_limit_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $cashierRoleId, $img);
        $stmt->execute();
        $userId = (int) self::$conn->insert_id;
        $stmt->close();

        $svc = new PermissionService(self::$conn);
        $limit = $svc->limit($userId, 'pos.payout.over_limit', $cashierRoleId);
        $this->assertNotNull($limit);
        $this->assertFalse(!empty($limit['is_unlimited']));
        $this->assertGreaterThan(0, (float) ($limit['limit_value'] ?? 0));

        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $userId);
    }

    public function test_approver_limit_exceeded_blocks_manager_override(): void
    {
        RolePermissionSyncService::restorePresetRole(self::$conn, 'manager');
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $managerRoleId = (int) $seeded['manager'];

        $pinSvc = new PinService();
        $uname = 'mgr_limit_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $managerRoleId, $img);
        $stmt->execute();
        $managerId = (int) self::$conn->insert_id;
        $stmt->close();

        $pin = (string) random_int(5000, 8999);
        while (strlen($pin) < 4) {
            $pin = (string) random_int(5000, 8999);
        }
        $pinSvc->setPinForUser(self::$conn, $managerId, $pin);

        $service = new ManagerApprovalService();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APPROVER_LIMIT_EXCEEDED');
        try {
            $service->authenticateManagerOverride(
                self::$conn,
                $pin,
                'pos.discount.manual_pct.limit',
                1,
                [
                    'limit_permission_key' => 'pos.discount.apply',
                    'amount' => 30.0,
                ]
            );
        } finally {
            $pinSvc->clearPinForUser(self::$conn, $managerId);
            self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $managerId);
        }
    }

    public function test_approver_within_limit_grants_override(): void
    {
        RolePermissionSyncService::restorePresetRole(self::$conn, 'manager');
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $managerRoleId = (int) $seeded['manager'];

        $pinSvc = new PinService();
        $uname = 'mgr_ok_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $managerRoleId, $img);
        $stmt->execute();
        $managerId = (int) self::$conn->insert_id;
        $stmt->close();

        $pin = (string) random_int(5000, 8999);
        $pinSvc->setPinForUser(self::$conn, $managerId, $pin);

        $service = new ManagerApprovalService();
        try {
            $approval = $service->authenticateManagerOverride(
                self::$conn,
                $pin,
                'pos.discount.manual_pct.limit',
                1,
                [
                    'limit_permission_key' => 'pos.discount.apply',
                    'amount' => 20.0,
                ]
            );
            $this->assertGreaterThan(0, (int) ($approval['id'] ?? 0));
            $this->assertSame('approved', (string) ($approval['status'] ?? ''));
        } finally {
            $pinSvc->clearPinForUser(self::$conn, $managerId);
            self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $managerId);
        }
    }

    public function test_privilege_escalation_blocked_for_admin_role_assignment(): void
    {
        $guard = new UserLifecycleGuardService();
        $roleName = 'hr_mgr_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare(
            'INSERT INTO usr_pwrs (rollname, add_users, edit_users, delete_users, isdeleted) VALUES (?, 1, 1, 0, 0)'
        );
        $stmt->bind_param('s', $roleName);
        $stmt->execute();
        $hrRoleId = (int) self::$conn->insert_id;
        $stmt->close();

        $uname = 'hr_actor_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $hrRoleId, $img);
        $stmt->execute();
        $actorId = (int) self::$conn->insert_id;
        $stmt->close();

        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $ownerRoleId = (int) ($seeded['owner'] ?? 1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PRIVILEGE_ESCALATION_BLOCKED');
            $guard->assertNoPrivilegeEscalation(self::$conn, $actorId, null, $ownerRoleId);
        } finally {
            self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $actorId);
            self::$conn->query('UPDATE usr_pwrs SET isdeleted = 1 WHERE id = ' . $hrRoleId);
        }
    }

    public function test_missing_t022_permission_keys_registered(): void
    {
        $svc = new PermissionService(self::$conn);
        foreach ([
            'pos.order.modify_others',
            'pos.void.item_after_send',
            'pos.drawer.payin',
            'pos.shift.force_close_others',
            'reports.own_shift',
            'reports.branch_daily',
            'reports.costs',
            'pos.credit.sell',
            'pos.drawer.payout.limit',
        ] as $key) {
            $svc->assertKnownPermissionKey($key);
        }
        $this->addToAssertionCount(1);
    }

    public function test_credit_sell_alias_matches_credit_sale_legacy_flags(): void
    {
        $map = auth_guard_permission_map();
        $this->assertSame($map['pos.credit.sale'], $map['pos.credit.sell']);
        $this->assertSame($map['pos.payout.over_limit'], $map['pos.drawer.payout.limit']);
    }

    public function test_escalation_attribution_line_on_consumed_approval(): void
    {
        $performerName = 'كاشير اختبار ' . bin2hex(random_bytes(2));
        $approverName = 'مدير اختبار ' . bin2hex(random_bytes(2));
        $orderId = 88000 + random_int(1, 999);

        $stmt = self::$conn->prepare('INSERT INTO users (uname, display_name, password, usertype, userrole, img, isdeleted) VALUES (?, ?, ?, 1, 1, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssss', $performerName, $performerName, $pass, $img);
        $stmt->execute();
        $performerId = (int) self::$conn->insert_id;
        $stmt->close();

        $stmt = self::$conn->prepare('INSERT INTO users (uname, display_name, password, usertype, userrole, img, isdeleted) VALUES (?, ?, ?, 1, 1, ?, 0)');
        $stmt->bind_param('ssss', $approverName, $approverName, $pass, $img);
        $stmt->execute();
        $approverId = (int) self::$conn->insert_id;
        $stmt->close();

        $service = new ManagerApprovalService();
        $approval = $service->requestApproval(self::$conn, [
            'action_type' => 'pos.refund',
            'target_type' => 'order',
            'target_id' => $orderId,
            'requested_by' => $performerId,
        ]);
        $service->decide(self::$conn, (int) $approval['id'], ['approved_by' => $approverId, 'status' => 'approved']);
        $service->consumeApproval(self::$conn, (int) $approval['id'], $performerId);

        try {
            $line = (new PosOrderMutationService())->escalationAttributionLineForOrder(self::$conn, $orderId);
            $this->assertNotNull($line);
            $this->assertStringContainsString('بواسطة', $line);
            $this->assertStringContainsString('بموافقة', $line);
            $this->assertStringContainsString($performerName, $line);
            $this->assertStringContainsString($approverName, $line);
        } finally {
            self::$conn->query('DELETE FROM manager_approvals WHERE id = ' . (int) $approval['id']);
            self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id IN (' . $performerId . ',' . $approverId . ')');
        }
    }

    public function test_user_override_grant_wins_over_role_capability_deny(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $permissionKey = 'reports.costs';
        $stmt = self::$conn->prepare(
            "INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited, tenant, branch)
             VALUES (?, ?, 0, NULL, 1, 0, 0)
             ON DUPLICATE KEY UPDATE is_enabled = 0"
        );
        $stmt->bind_param('is', $cashierRoleId, $permissionKey);
        $stmt->execute();
        $stmt->close();
        (new PermissionService(self::$conn))->bumpPermissionsVersion();

        $svc = new PermissionService(self::$conn);
        $plainUserId = $this->createTestUser($cashierRoleId);
        $overrideUserId = $this->createTestUser($cashierRoleId);
        $this->assertFalse($svc->check($plainUserId, $permissionKey, $cashierRoleId));

        $this->enableUserOverrides($overrideUserId);
        $this->setUserGrant($overrideUserId, $permissionKey, 'grant');
        $overrides = (new UserPermissionGrantService())->activeOverridesForUser(self::$conn, $overrideUserId);
        $this->assertSame('grant', $overrides[$permissionKey] ?? '');
        $this->assertTrue($svc->check($overrideUserId, $permissionKey, $cashierRoleId));

        $this->cleanupTestUser($plainUserId);
        $this->cleanupTestUser($overrideUserId);
        self::$conn->query(
            "DELETE FROM role_capabilities WHERE role_id = " . (int) $cashierRoleId . " AND permission_key = '" . self::$conn->real_escape_string($permissionKey) . "'"
        );
        (new PermissionService(self::$conn))->bumpPermissionsVersion();
    }

    public function test_user_override_limit_wins_over_role_limit(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $userId = $this->createTestUser($cashierRoleId);

        $this->enableUserOverrides($userId);
        $this->setUserGrant($userId, 'pos.discount.apply', 'grant', 25.0, false);

        $svc = new PermissionService(self::$conn);
        $this->assertTrue($svc->checkAmount($userId, 'pos.discount.apply', 20.0, $cashierRoleId));
        $this->assertTrue($svc->checkAmount($userId, 'pos.discount.apply', 25.0, $cashierRoleId));
        $this->assertFalse($svc->checkAmount($userId, 'pos.discount.apply', 25.01, $cashierRoleId));

        $this->cleanupTestUser($userId);
    }

    public function test_role_capability_edit_affects_users_without_override_only(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $plainUserId = $this->createTestUser($cashierRoleId);
        $overrideUserId = $this->createTestUser($cashierRoleId);
        $this->enableUserOverrides($overrideUserId);
        $this->setUserGrant($overrideUserId, 'pos.discount.apply', 'grant', 30.0, false);

        $stmt = self::$conn->prepare(
            "UPDATE role_capabilities SET limit_value = 5, is_unlimited = 0 WHERE role_id = ? AND permission_key = 'pos.discount.apply'"
        );
        $stmt->bind_param('i', $cashierRoleId);
        $stmt->execute();
        $stmt->close();

        $svc = new PermissionService(self::$conn);
        $this->assertTrue($svc->checkAmount($plainUserId, 'pos.discount.apply', 5.0, $cashierRoleId));
        $this->assertFalse($svc->checkAmount($plainUserId, 'pos.discount.apply', 5.01, $cashierRoleId));
        $this->assertTrue($svc->checkAmount($overrideUserId, 'pos.discount.apply', 25.0, $cashierRoleId));

        $this->cleanupTestUser($plainUserId);
        $this->cleanupTestUser($overrideUserId);
        RolePermissionSyncService::restorePresetRole(self::$conn, 'cashier');
    }

    public function test_role_change_clears_user_permission_overrides(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $waiterRoleId = (int) ($seeded['waiter'] ?? $cashierRoleId);
        $userId = $this->createTestUser($cashierRoleId);
        $this->enableUserOverrides($userId);
        $this->setUserGrant($userId, 'pos.shift.open', 'grant');

        $grantService = new UserPermissionGrantService();
        $this->assertNotEmpty($grantService->activeOverridesForUser(self::$conn, $userId));

        $clearGrants = self::$conn->prepare('DELETE FROM user_permission_grants WHERE user_id = ?');
        $clearGrants->bind_param('i', $userId);
        $clearGrants->execute();
        $clearGrants->close();
        $modeStmt = self::$conn->prepare("UPDATE users SET permission_mode = 'role_only', userrole = ? WHERE id = ?");
        $modeStmt->bind_param('ii', $waiterRoleId, $userId);
        $modeStmt->execute();
        $modeStmt->close();

        $this->assertSame([], $grantService->activeOverridesForUser(self::$conn, $userId));
        $this->assertFalse($grantService->userUsesOverrides(self::$conn, $userId));

        $this->cleanupTestUser($userId);
    }

    public function test_limit_boundary_zero_blocks_positive_amount(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $userId = $this->createTestUser($cashierRoleId);

        $stmt = self::$conn->prepare(
            "UPDATE role_capabilities SET limit_value = 0, is_unlimited = 0, is_enabled = 1
             WHERE role_id = ? AND permission_key = 'pos.discount.apply'"
        );
        $stmt->bind_param('i', $cashierRoleId);
        $stmt->execute();
        $stmt->close();

        $svc = new PermissionService(self::$conn);
        $this->assertTrue($svc->checkAmount($userId, 'pos.discount.apply', 0.0, $cashierRoleId));
        $this->assertFalse($svc->checkAmount($userId, 'pos.discount.apply', 0.01, $cashierRoleId));

        $this->cleanupTestUser($userId);
        RolePermissionSyncService::restorePresetRole(self::$conn, 'cashier');
    }

    public function test_unlimited_limit_allows_billion_scale_amount(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $managerRoleId = (int) $seeded['manager'];
        $userId = $this->createTestUser($managerRoleId);

        $stmt = self::$conn->prepare(
            "INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited, tenant, branch)
             VALUES (?, 'pos.discount.apply', 1, NULL, 1, 0, 0)
             ON DUPLICATE KEY UPDATE limit_value = NULL, is_unlimited = 1, is_enabled = 1"
        );
        $stmt->bind_param('i', $managerRoleId);
        $stmt->execute();
        $stmt->close();

        $svc = new PermissionService(self::$conn);
        $this->assertTrue($svc->check($userId, 'pos.discount.apply', $managerRoleId));
        $limit = $svc->limit($userId, 'pos.discount.apply', $managerRoleId);
        $this->assertNotNull($limit);
        $this->assertTrue(!empty($limit['is_unlimited']));
        $this->assertTrue($svc->checkAmount($userId, 'pos.discount.apply', 999999999.0, $managerRoleId));

        $this->cleanupTestUser($userId);
        RolePermissionSyncService::restorePresetRole(self::$conn, 'manager');
    }

    public function test_legacy_flag_fallback_without_role_capability_row(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $managerRoleId = (int) $seeded['manager'];

        $stmt = self::$conn->prepare("DELETE FROM role_capabilities WHERE role_id = ? AND permission_key = 'pos.discount.apply'");
        $stmt->bind_param('i', $managerRoleId);
        $stmt->execute();
        $stmt->close();

        $roleStmt = self::$conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
        $roleStmt->bind_param('i', $managerRoleId);
        $roleStmt->execute();
        $roleFlags = $roleStmt->get_result()->fetch_assoc() ?: [];
        $roleStmt->close();
        $this->assertSame(1, (int) ($roleFlags['edit_sales'] ?? 0));

        $userId = $this->createTestUser($managerRoleId);
        $this->assertTrue(auth_guard_session_has_permission(
            'pos.discount.apply',
            $roleFlags,
            ['userid' => $userId, 'login' => true, 'usrole' => $managerRoleId],
            self::$conn
        ));

        $this->cleanupTestUser($userId);
        RolePermissionSyncService::restorePresetRole(self::$conn, 'manager');
    }

    public function test_capabilities_cache_rebuilds_after_permissions_version_bump(): void
    {
        $_SESSION['userid'] = 1;
        $_SESSION['login'] = true;
        $_SESSION['usrole'] = 1;
        $_SESSION['posmain_capabilities_cache'] = ['pos.open' => false];
        $_SESSION['posmain_capabilities_version'] = 'stale-version';

        $before = auth_guard_effective_permissions(self::$conn, true);
        $this->assertArrayHasKey('pos.open', $before);

        (new PermissionService(self::$conn))->bumpPermissionsVersion();
        auth_guard_invalidate_capabilities_cache();
        unset($_SESSION['posmain_capabilities_cache'], $_SESSION['posmain_capabilities_version']);

        $after = auth_guard_effective_permissions(self::$conn, true);
        $this->assertNotSame('stale-version', (string) ($_SESSION['posmain_capabilities_version'] ?? ''));
        $this->assertArrayHasKey('pos.open', $after);
        $this->assertIsBool($after['pos.open']);
    }

    public function test_pin_duplicate_lookup_raises_duplicate_error(): void
    {
        $pinSvc = new PinService();
        $pin = $this->uniquePin();
        $userA = $this->createTestUser(1);
        $userB = $this->createTestUser(1);
        $pinSvc->setPinForUser(self::$conn, $userA, $pin);

        try {
            $pinSvc->setPinForUser(self::$conn, $userB, $pin);
            $this->fail('Expected duplicate PIN assignment to fail');
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $this->assertTrue(
                str_contains($message, 'duplicate')
                || str_contains($message, '1062')
                || str_contains($message, 'pin_lookup')
                || $exception instanceof mysqli_sql_exception,
                'Unexpected exception: ' . $exception->getMessage()
            );
        } finally {
            $pinSvc->clearPinForUser(self::$conn, $userA);
            $this->cleanupTestUser($userA);
            $this->cleanupTestUser($userB);
        }
    }

    public function test_pin_format_rejects_seven_digit_pin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PinService())->validatePinFormat('1234567');
    }

    public function test_deactivated_user_not_resolved_by_pin_lookup(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $cashierRoleId = (int) $seeded['cashier'];
        $pinSvc = new PinService();
        $userId = $this->createTestUser($cashierRoleId);
        $pin = $this->uniquePin();
        $pinSvc->setPinForUser(self::$conn, $userId, $pin);
        $this->assertNotNull($pinSvc->findUserByPin(self::$conn, $pin));

        $stmt = self::$conn->prepare('UPDATE users SET isdeleted = 1 WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        $this->assertNull($pinSvc->findUserByPin(self::$conn, $pin));
    }

    public function test_reactivated_user_pin_hash_persists_until_reset(): void
    {
        $pinSvc = new PinService();
        $userId = $this->createTestUser(1);
        $pin = $this->uniquePin();
        $pinSvc->setPinForUser(self::$conn, $userId, $pin);

        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $userId);
        $this->assertNull($pinSvc->findUserByPin(self::$conn, $pin));

        $stmt = self::$conn->prepare('UPDATE users SET isdeleted = 0 WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $found = $pinSvc->findUserByPin(self::$conn, $pin);
        $this->assertSame($userId, (int) ($found['id'] ?? 0));

        $pinSvc->clearPinForUser(self::$conn, $userId);
        $this->cleanupTestUser($userId);
    }

    public function test_pin_invalid_response_is_generic_for_unknown_and_wrong_pin(): void
    {
        $this->assertSame('PIN_INVALID', $this->pinLoginFailureCode('5891'));
        $pinSvc = new PinService();
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $userId = $this->createTestUser((int) $seeded['cashier']);
        $pin = $this->uniquePin();
        $pinSvc->setPinForUser(self::$conn, $userId, $pin);
        $wrongPin = $pin === '5892' ? '5893' : '5892';
        $this->assertSame('PIN_INVALID', $this->pinLoginFailureCode($wrongPin));
        $pinSvc->clearPinForUser(self::$conn, $userId);
        $this->cleanupTestUser($userId);
    }

    public function test_pin_lockout_base_seconds_documented_deviation_from_spec(): void
    {
        $ref = new ReflectionClass(PinService::class);
        $lockSeconds = (int) $ref->getConstant('LOCK_SECONDS');
        $this->assertSame(900, $lockSeconds, 'Accepted deviation: 900s base lockout vs spec 60s (see DECISIONS.md)');
    }

    public function test_terminal_pin_freeze_after_ten_failures(): void
    {
        $pinSvc = new PinService();
        $ip = '127.0.0.' . random_int(10, 250);
        $pinSvc->clearTerminalFailures(self::$conn, $ip);
        for ($i = 0; $i < 10; $i++) {
            $pinSvc->recordTerminalFailure(self::$conn, $ip);
        }
        $this->assertTrue($pinSvc->isTerminalFrozen(self::$conn, $ip));
        $pinSvc->clearTerminalFailures(self::$conn, $ip);
    }

    public function test_privilege_escalation_blocked_against_admin_user_target(): void
    {
        $adminRow = self::$conn->query(
            "SELECT u.id FROM users u
              INNER JOIN usr_pwrs r ON r.id = u.userrole
             WHERE COALESCE(u.isdeleted, 0) = 0
               AND u.uname IN ('p6_admin', 'rbac_pin_admin', 'admin')
             ORDER BY FIELD(u.uname, 'p6_admin', 'rbac_pin_admin', 'admin')
             LIMIT 1"
        )->fetch_assoc();
        if (!$adminRow) {
            $this->markTestSkipped('No admin user in fixture database');
        }
        $adminId = (int) $adminRow['id'];

        $guard = new UserLifecycleGuardService();
        $hrRoleId = $this->createHrManagerRole();
        $actorId = $this->createTestUser($hrRoleId);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PRIVILEGE_ESCALATION_BLOCKED');
            $guard->assertNoPrivilegeEscalation(self::$conn, $actorId, $adminId, null);
        } finally {
            $this->cleanupTestUser($actorId);
            self::$conn->query('UPDATE usr_pwrs SET isdeleted = 1 WHERE id = ' . $hrRoleId);
        }
    }

    public function test_privilege_escalation_blocked_against_users_manage_holder(): void
    {
        $guard = new UserLifecycleGuardService();
        $hrRoleId = $this->createHrManagerRole();
        $actorId = $this->createTestUser($hrRoleId);
        $targetId = $this->createTestUser($hrRoleId);
        $this->enableUserOverrides($targetId);
        $this->setUserGrant($targetId, 'users.manage', 'grant');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PRIVILEGE_ESCALATION_BLOCKED');
            $guard->assertNoPrivilegeEscalation(self::$conn, $actorId, $targetId, null);
        } finally {
            $this->cleanupTestUser($actorId);
            $this->cleanupTestUser($targetId);
            self::$conn->query('UPDATE usr_pwrs SET isdeleted = 1 WHERE id = ' . $hrRoleId);
        }
    }

    public function test_open_drawer_blocks_soft_delete(): void
    {
        $seeded = RolePermissionSyncService::seedPresetRoles(self::$conn);
        $userId = $this->createTestUser((int) $seeded['cashier']);
        $result = self::$conn->query("SHOW TABLES LIKE 'drawer_sessions'");
        if (!$result || $result->num_rows < 1) {
            $this->markTestSkipped('drawer_sessions table missing');
        }

        $uuid = bin2hex(random_bytes(16));
        $uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20, 12);
        $stmt = self::$conn->prepare(
            "INSERT INTO drawer_sessions (uuid, user_id, opened_at, opened_by, opening_cash, status) VALUES (?, ?, NOW(), ?, 0, 'open')"
        );
        $stmt->bind_param('sii', $uuid, $userId, $userId);
        $stmt->execute();
        $drawerId = (int) self::$conn->insert_id;
        $stmt->close();

        $guard = new UserLifecycleGuardService();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('DRAWER_SESSION_OPEN');
            $guard->softDeleteUser(self::$conn, $userId);
        } finally {
            self::$conn->query('DELETE FROM drawer_sessions WHERE id = ' . $drawerId);
            $this->cleanupTestUser($userId);
        }
    }

    public function test_lifecycle_handlers_use_soft_delete_not_hard_delete(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['do/do_deluser.php', 'do/do_user_deactivate.php'] as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);
            $this->assertStringNotContainsString('DELETE FROM users', $source, $relative);
            $this->assertStringContainsString('softDeleteUser', $source, $relative);
        }
    }

    public function test_approval_consume_records_performed_by_and_approved_by(): void
    {
        $performerId = $this->createTestUser(1);
        $approverId = $this->createTestUser(1);
        $service = new ManagerApprovalService();
        $approval = $service->requestApproval(self::$conn, [
            'action_type' => 'spec.audit',
            'target_type' => 'order',
            'target_id' => 42,
            'requested_by' => $performerId,
        ]);
        $service->decide(self::$conn, (int) $approval['id'], ['approved_by' => $approverId, 'status' => 'approved']);
        $service->consumeApproval(self::$conn, (int) $approval['id'], $performerId);

        $stmt = self::$conn->prepare('SELECT performed_by, approved_by FROM manager_approvals WHERE id = ? LIMIT 1');
        $approvalId = (int) $approval['id'];
        $stmt->bind_param('i', $approvalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertSame($performerId, (int) ($row['performed_by'] ?? 0));
        $this->assertSame($approverId, (int) ($row['approved_by'] ?? 0));

        self::$conn->query('DELETE FROM manager_approvals WHERE id = ' . $approvalId);
        $this->cleanupTestUser($performerId);
        $this->cleanupTestUser($approverId);
    }

    public function test_phase_audit_event_pos_pin_login_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/ajax/pos_pin_login.php');
        $this->assertStringContainsString('pos_pin_login', $source);
    }

    public function test_phase_audit_event_user_created_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/do/doadd_user.php');
        $this->assertStringContainsString('user_created', $source);
    }

    public function test_phase_audit_event_user_deactivated_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/do/do_user_deactivate.php');
        $this->assertStringContainsString('user_deactivated', $source);
    }

    public function test_phase_audit_event_user_reactivated_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/do/do_user_reactivate.php');
        $this->assertStringContainsString('user_reactivated', $source);
    }

    public function test_phase_audit_event_user_pin_reset_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/do/do_user_reset_pin.php');
        $this->assertStringContainsString('user_pin_reset', $source);
    }

    public function test_phase_audit_event_user_role_changed_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/do/doedit_user.php');
        $this->assertStringContainsString('user_role_changed', $source);
    }

    public function test_phase_audit_event_manager_override_registered(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/ajax/pos_override_auth.php');
        $this->assertStringContainsString('manager_override_granted', $source);
    }

    public function test_audit_metadata_never_contains_bcrypt_hash_prefix(): void
    {
        $logger = new SecurityAuditLogger();
        $record = $logger->record(self::$conn, 'spec.metadata_scan', [
            'user_id' => 1,
            'metadata' => [
                'action' => 'pin_reset',
                'user_id' => 99,
                'note' => 'no secrets here',
            ],
        ]);

        $stmt = self::$conn->prepare('SELECT metadata_json FROM security_audit_log WHERE id = ? LIMIT 1');
        $id = (int) $record['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertIsString($row['metadata_json'] ?? null);
        $this->assertStringNotContainsString('$2y$', (string) $row['metadata_json']);
        self::$conn->query('DELETE FROM security_audit_log WHERE id = ' . $id);
    }

    private function createTestUser(int $roleId): int
    {
        $uname = 'rbac_u_' . bin2hex(random_bytes(4));
        $stmt = self::$conn->prepare('INSERT INTO users (uname, password, usertype, userrole, img, isdeleted) VALUES (?, ?, 1, ?, ?, 0)');
        $pass = password_hash('x', PASSWORD_DEFAULT);
        $img = '';
        $stmt->bind_param('ssis', $uname, $pass, $roleId, $img);
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function cleanupTestUser(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        self::$conn->query('DELETE FROM user_permission_grants WHERE user_id = ' . $userId);
        self::$conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $userId);
    }

    private function createHrManagerRole(): int
    {
        $roleName = 'hr_mgr_' . bin2hex(random_bytes(3));
        $stmt = self::$conn->prepare(
            'INSERT INTO usr_pwrs (rollname, add_users, edit_users, delete_users, isdeleted) VALUES (?, 1, 1, 0, 0)'
        );
        $stmt->bind_param('s', $roleName);
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function enableUserOverrides(int $userId): void
    {
        $stmt = self::$conn->prepare("UPDATE users SET permission_mode = 'role_with_overrides' WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function setUserGrant(int $userId, string $permissionKey, string $effect, ?float $limitValue = null, bool $unlimited = true): void
    {
        $stmt = self::$conn->prepare(
            "INSERT INTO user_permission_grants (user_id, permission_key, effect, limit_value, is_unlimited, tenant, branch)
             VALUES (?, ?, ?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE effect = VALUES(effect), limit_value = VALUES(limit_value), is_unlimited = VALUES(is_unlimited)"
        );
        $isUnlimited = $unlimited ? 1 : 0;
        $limitParam = $limitValue;
        $stmt->bind_param('issdi', $userId, $permissionKey, $effect, $limitParam, $isUnlimited);
        $stmt->execute();
        $stmt->close();
    }

    private function uniquePin(): string
    {
        do {
            $pin = (string) random_int(5000, 9899);
        } while (in_array($pin, ['1234', '5678', '2468', '9753'], true));

        return $pin;
    }

    private function roleFlagsForTestUser(int $userId): array
    {
        $stmt = self::$conn->prepare(
            'SELECT p.* FROM users u INNER JOIN usr_pwrs p ON p.id = u.userrole WHERE u.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        return $row;
    }

    private function pinLoginFailureCode(string $pin): string
    {
        $pinSvc = new PinService();
        try {
            $user = $pinSvc->findUserByPin(self::$conn, $pin);
        } catch (InvalidArgumentException $exception) {
            return 'PIN_INVALID';
        }
        if (!$user) {
            return 'PIN_INVALID';
        }
        if (!$pinSvc->verifyPin($pin, (string) ($user['pin_hash'] ?? ''))) {
            return 'PIN_INVALID';
        }

        return 'PIN_OK';
    }
}
