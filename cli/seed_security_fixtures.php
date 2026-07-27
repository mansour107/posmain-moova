#!/usr/bin/env php
<?php

/**
 * Seeds RBAC/PIN security fixtures for local PHPUnit and Playwright.
 * Usage: POSMAIN_PIN_SECRET=test-pin-secret php cli/seed_security_fixtures.php
 */

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';

if (trim((string) getenv('POSMAIN_PIN_SECRET')) === '') {
    putenv('POSMAIN_PIN_SECRET=posmain-test-pin-secret-do-not-use-in-prod');
    $_ENV['POSMAIN_PIN_SECRET'] = 'posmain-test-pin-secret-do-not-use-in-prod';
}

$fixtureDb = [
    'host' => (string) (getenv('POSMAIN_DB_HOST') ?: getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1'),
    'port' => (int) (getenv('POSMAIN_DB_PORT') ?: getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307),
    'user' => (string) (getenv('POSMAIN_DB_USER') ?: getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root'),
    'pass' => (string) (getenv('POSMAIN_DB_PASS') ?: getenv('POSMAIN_TEST_MYSQL_PASS') ?: ''),
    'name' => (string) (getenv('POSMAIN_TEST_MYSQL_DB') ?: getenv('POSMAIN_DB_NAME') ?: 'kody2'),
    'charset' => 'utf8mb4',
];

$conn = posmain_db_connect(['database' => $fixtureDb]);
$conn->set_charset('utf8mb4');

(new SyncSchemaManager())->apply($conn);

$demoPassword = PasswordService::hashPassword(getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!');
$pinService = new PinService();

RolePermissionSyncService::backfillRoleCapabilitiesFromLegacyFlags($conn);
$roles = RolePermissionSyncService::seedPresetRoles($conn);
$ownerRoleId = $roles['owner'] ?? 1;
$cashierRoleId = $roles['cashier'] ?? 3;
$managerRoleId = $roles['manager'] ?? 2;
$kitchenRoleId = $roles['kitchen'] ?? 5;

// Ensure Phase 6 manager/waiter personas exist for E2E.
$phase6Personas = [
    ['p6_manager', 'Phase 6 Manager', 'manager'],
    ['p6_waiter', 'Phase 6 Waiter', 'waiter'],
];
foreach ($phase6Personas as [$personaUname, $personaDisplay, $presetKey]) {
    $personaStmt = $conn->prepare('SELECT id FROM users WHERE uname = ? LIMIT 1');
    $personaStmt->bind_param('s', $personaUname);
    $personaStmt->execute();
    $personaRow = $personaStmt->get_result()->fetch_assoc();
    $personaStmt->close();
    $presetRoleId = (int) ($roles[$presetKey] ?? 0);
    if ($presetRoleId < 1) {
        continue;
    }
    if (!$personaRow) {
        $personaIsWaiter = $presetKey === 'waiter' ? 1 : 0;
        $personaImg = '';
        $insPersona = $conn->prepare(
            'INSERT INTO users (uname, password, usertype, userrole, display_name, is_waiter, img, isdeleted)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $insPersona->bind_param(
            'ssiisis',
            $personaUname,
            $demoPassword,
            $presetRoleId,
            $presetRoleId,
            $personaDisplay,
            $personaIsWaiter,
            $personaImg
        );
        $insPersona->execute();
        $insPersona->close();
        fwrite(STDOUT, "Created E2E persona {$personaUname}\n");
    } else {
        $personaUserId = (int) $personaRow['id'];
        $personaIsWaiter = $presetKey === 'waiter' ? 1 : 0;
        $updPersona = $conn->prepare(
            'UPDATE users SET password = ?, userrole = ?, display_name = ?, is_waiter = ?, isdeleted = 0 WHERE id = ?'
        );
        $updPersona->bind_param('sisii', $demoPassword, $presetRoleId, $personaDisplay, $personaIsWaiter, $personaUserId);
        $updPersona->execute();
        $updPersona->close();
    }
}

// Ensure Phase 6 kitchen persona exists for §8.2.2 E2E.
$kitchenStmt = $conn->prepare('SELECT id FROM users WHERE uname = ? LIMIT 1');
$kitchenUname = 'p6_kitchen';
$kitchenStmt->bind_param('s', $kitchenUname);
$kitchenStmt->execute();
$kitchenRow = $kitchenStmt->get_result()->fetch_assoc();
$kitchenStmt->close();
if (!$kitchenRow) {
    $kitchenDisplay = 'Phase 6 Kitchen';
    $kitchenIsWaiter = 0;
    $kitchenImg = '';
    $insKitchen = $conn->prepare(
        'INSERT INTO users (uname, password, usertype, userrole, display_name, is_waiter, img, isdeleted)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $insKitchen->bind_param(
        'ssiisis',
        $kitchenUname,
        $demoPassword,
        $kitchenRoleId,
        $kitchenRoleId,
        $kitchenDisplay,
        $kitchenIsWaiter,
        $kitchenImg
    );
    $insKitchen->execute();
    $insKitchen->close();
    fwrite(STDOUT, "Created E2E persona {$kitchenUname}\n");
} else {
    $kitchenUserId = (int) $kitchenRow['id'];
    $updKitchen = $conn->prepare(
        'UPDATE users SET password = ?, userrole = ?, display_name = ?, isdeleted = 0 WHERE id = ?'
    );
    $kitchenDisplay = 'Phase 6 Kitchen';
    $updKitchen->bind_param('sisi', $demoPassword, $kitchenRoleId, $kitchenDisplay, $kitchenUserId);
    $updKitchen->execute();
    $updKitchen->close();
}

// Bind Phase 6 personas to RBAC preset roles (not legacy P6 Demo * roles with leaked flags).
$waiterRoleId = $roles['waiter'] ?? 4;
$personaRoleBinding = [
    'p6_admin' => $ownerRoleId,
    'p6_manager' => $managerRoleId,
    'p6_cashier' => $cashierRoleId,
    'p6_waiter' => $waiterRoleId,
    'p6_kitchen' => $kitchenRoleId,
];
foreach ($personaRoleBinding as $uname => $roleId) {
    $bindStmt = $conn->prepare('SELECT id FROM users WHERE uname = ? LIMIT 1');
    $bindStmt->bind_param('s', $uname);
    $bindStmt->execute();
    $bindRow = $bindStmt->get_result()->fetch_assoc();
    $bindStmt->close();
    if (!$bindRow) {
        continue;
    }
    $personaUserId = (int) $bindRow['id'];
    $roleStmt = $conn->prepare('UPDATE users SET userrole = ?, display_name = ?, isdeleted = 0 WHERE id = ?');
    $roleStmt->bind_param('isi', $roleId, $uname, $personaUserId);
    $roleStmt->execute();
    $roleStmt->close();

    $grantTable = $conn->query("SHOW TABLES LIKE 'user_permission_grants'");
    if ($grantTable && $grantTable->num_rows > 0) {
        $clearGrants = $conn->prepare('DELETE FROM user_permission_grants WHERE user_id = ?');
        $clearGrants->bind_param('i', $personaUserId);
        $clearGrants->execute();
        $clearGrants->close();
    }

    $modeCol = $conn->query("SHOW COLUMNS FROM users LIKE 'permission_mode'");
    if ($modeCol && $modeCol->num_rows > 0) {
        $mode = 'role_only';
        $modeStmt = $conn->prepare('UPDATE users SET permission_mode = ? WHERE id = ?');
        $modeStmt->bind_param('si', $mode, $personaUserId);
        $modeStmt->execute();
        $modeStmt->close();
    }

    fwrite(STDOUT, "Bound {$uname} to preset role_id={$roleId}\n");
}

$e2ePersonaPins = [
    'p6_admin' => getenv('POSMAIN_TEST_PIN_ADMIN') ?: '2468',
    'p6_manager' => getenv('POSMAIN_TEST_PIN_MANAGER') ?: '1357',
    'p6_cashier' => getenv('POSMAIN_TEST_PIN_CASHIER') ?: '9753',
    'p6_waiter' => getenv('POSMAIN_TEST_PIN_WAITER') ?: '8642',
    'p6_kitchen' => getenv('POSMAIN_TEST_PIN_KITCHEN') ?: '7531',
];

foreach ($e2ePersonaPins as $uname => $pin) {
    $stmt = $conn->prepare('SELECT id FROM users WHERE uname = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
    $stmt->bind_param('s', $uname);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        continue;
    }
    $userId = (int) $row['id'];
    try {
        $lookup = $pinService->pinLookup($pin);
        $clear = $conn->prepare(
            'UPDATE users SET pin_lookup = NULL, pin_set_at = NULL WHERE pin_lookup = ? AND id != ?'
        );
        $clear->bind_param('si', $lookup, $userId);
        $clear->execute();
        $clear->close();

        $pinService->setPinForUser($conn, $userId, $pin, ['bump_auth_version' => false]);
        $pinService->clearUserFailures($conn, $userId);
        fwrite(STDOUT, "PIN set on E2E persona {$uname}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Skip PIN for {$uname}: " . $e->getMessage() . "\n");
    }
}

// Test fixtures represent an already-enrolled installation. Leaving bootstrap
// pending forces every persona into change_pin.php and makes the HTTP fixtures
// unlike a production-ready local installation.
$bootstrapTable = $conn->query("SHOW TABLES LIKE 'security_bootstrap_state'");
if ($bootstrapTable && $bootstrapTable->num_rows > 0) {
    $ownerId = 0;
    $ownerStmt = $conn->prepare('SELECT id FROM users WHERE uname = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
    $ownerUname = 'p6_admin';
    $ownerStmt->bind_param('s', $ownerUname);
    $ownerStmt->execute();
    $ownerRow = $ownerStmt->get_result()->fetch_assoc();
    $ownerStmt->close();
    $ownerId = (int) ($ownerRow['id'] ?? 0);

    if ($ownerId > 0) {
        $completeBootstrap = $conn->prepare(
            "INSERT INTO security_bootstrap_state
                (id, status, owner_user_id, started_at, completed_at, completed_by)
             VALUES (1, 'completed', ?, NOW(), NOW(), ?)
             ON DUPLICATE KEY UPDATE
                status = 'completed',
                owner_user_id = VALUES(owner_user_id),
                completed_at = COALESCE(completed_at, NOW()),
                completed_by = VALUES(completed_by)"
        );
        $completeBootstrap->bind_param('ii', $ownerId, $ownerId);
        $completeBootstrap->execute();
        $completeBootstrap->close();
        fwrite(STDOUT, "Marked local security bootstrap completed\n");
    }
}

// Dedicated PHPUnit users with non-conflicting PINs.
$rbacUsers = [
    ['rbac_pin_admin', 'RBAC Admin', $ownerRoleId, 0, '4826'],
    ['rbac_pin_manager', 'RBAC Manager', $managerRoleId, 0, '5739'],
    ['rbac_pin_cashier', 'RBAC Cashier', $cashierRoleId, 0, '6847'],
];

foreach ($rbacUsers as [$uname, $display, $roleId, $isWaiter, $pin]) {
    $stmt = $conn->prepare('SELECT id FROM users WHERE uname = ? LIMIT 1');
    $stmt->bind_param('s', $uname);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $userId = (int) $row['id'];
        $upd = $conn->prepare(
            'UPDATE users SET password = ?, userrole = ?, display_name = ?, is_waiter = ?, isdeleted = 0 WHERE id = ?'
        );
        $upd->bind_param('sisii', $demoPassword, $roleId, $display, $isWaiter, $userId);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare(
            'INSERT INTO users (uname, password, usertype, userrole, display_name, is_waiter, img, isdeleted)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $usertype = $roleId;
        $emptyImg = '';
        $ins->bind_param('ssiisis', $uname, $demoPassword, $usertype, $roleId, $display, $isWaiter, $emptyImg);
        $ins->execute();
        $userId = (int) $conn->insert_id;
        $ins->close();
    }

    try {
        $lookup = $pinService->pinLookup($pin);
        $clear = $conn->prepare(
            'UPDATE users SET pin_lookup = NULL, pin_set_at = NULL WHERE pin_lookup = ? AND id != ?'
        );
        $clear->bind_param('si', $lookup, $userId);
        $clear->execute();
        $clear->close();

        $pinService->setPinForUser($conn, $userId, $pin, ['bump_auth_version' => false]);
        $pinService->clearUserFailures($conn, $userId);
        fwrite(STDOUT, "Seeded user {$uname} (id={$userId}) with PIN\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "Skip PIN for {$uname}: " . $e->getMessage() . "\n");
    }
}

if (!function_exists('posmain_drawer_sessions_table_exists')) {
    require_once __DIR__ . '/../includes/pos_shift_guard.php';
}
if (posmain_drawer_sessions_table_exists($conn)) {
    $closed = $conn->query(
        "UPDATE drawer_sessions
            SET status = 'closed',
                closed_at = COALESCE(closed_at, NOW()),
                open_branch_lock = NULL,
                open_register_lock = NULL,
                open_user_lock = NULL
          WHERE status = 'open' AND closed_at IS NULL"
    );
    if ($closed) {
        fwrite(STDOUT, 'Closed stale open drawer sessions: ' . $conn->affected_rows . "\n");
    }
}

// Clear auth throttles so E2E re-seeds are not blocked by prior failed PIN/pair attempts.
$throttleCleared = @$conn->query('DELETE FROM failed_login_attempts');
if ($throttleCleared) {
    fwrite(STDOUT, 'Cleared failed_login_attempts: ' . $conn->affected_rows . "\n");
}

foreach (array_keys(RolePermissionSyncService::presetRoleDefinitions()) as $presetRoleKey) {
    try {
        RolePermissionSyncService::restorePresetRole($conn, $presetRoleKey);
    } catch (Throwable $presetException) {
        fwrite(STDERR, "restorePresetRole {$presetRoleKey}: " . $presetException->getMessage() . "\n");
    }
}

fwrite(STDOUT, "security-fixtures-ok\n");
