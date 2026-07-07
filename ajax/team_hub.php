<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/team_hub.php', $conn);
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Security/TeamHubService.php';
require_once __DIR__ . '/../classes/Security/TeamHubMutationService.php';
require_once __DIR__ . '/../classes/Security/PinService.php';

header('Content-Type: application/json; charset=utf-8');

function team_hub_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function team_hub_require_csrf(string $namespace): void
{
    $token = trim((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verify_csrf_token($token, $namespace)) {
        team_hub_json(403, ['success' => false, 'code' => 'CSRF_INVALID']);
    }
}

function team_hub_actor_id(): int
{
    return function_exists('current_user_id') ? current_user_id() : (int) ($_SESSION['userid'] ?? 0);
}

function team_hub_drawer_session_payload(int $userId, mysqli $conn): array
{
    require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';
    $guard = new UserLifecycleGuardService();
    $sessions = $guard->findOpenDrawerSessionsForUser($conn, $userId);

    return array_map(static function (array $session): array {
        return [
            'id' => (int) ($session['id'] ?? 0),
            'opened_at' => (string) ($session['opened_at'] ?? ''),
            'tenant' => (int) ($session['tenant'] ?? 0),
            'branch' => (int) ($session['branch'] ?? 0),
        ];
    }, $sessions);
}

function team_hub_handle_mutation(callable $callback): void
{
    try {
        $result = $callback();
        team_hub_json(200, $result);
    } catch (InvalidArgumentException $exception) {
        $code = $exception->getMessage();
        if (str_starts_with($code, 'ROLE_HAS_STAFF:')) {
            team_hub_json(409, [
                'success' => false,
                'code' => 'ROLE_HAS_STAFF',
                'staff_count' => (int) substr($code, strlen('ROLE_HAS_STAFF:')),
            ]);
        }
        team_hub_json(422, ['success' => false, 'code' => $code]);
    } catch (RuntimeException $exception) {
        $code = $exception->getMessage();
        if ($code === 'USER_NOT_FOUND') {
            team_hub_json(404, ['success' => false, 'code' => $code]);
        }
        if ($code === 'DRAWER_SESSION_OPEN') {
            global $conn;
            $userId = (int) ($_POST['user_id'] ?? 0);
            team_hub_json(409, [
                'success' => false,
                'code' => $code,
                'drawer_sessions' => team_hub_drawer_session_payload($userId, $conn),
            ]);
        }
        if (str_starts_with($code, 'PRIVILEGE_ESCALATION') || $code === 'ROLE_HAS_STAFF') {
            team_hub_json(403, ['success' => false, 'code' => $code]);
        }
        team_hub_json(422, ['success' => false, 'code' => $code]);
    } catch (Throwable $exception) {
        team_hub_json(422, ['success' => false, 'code' => $exception->getMessage()]);
    }
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$hub = new TeamHubService($conn);
$mutations = new TeamHubMutationService($conn, $hub);
$canUsers = auth_guard_has_permission('users.manage', $conn) || auth_guard_is_admin_session();
$canRoles = auth_guard_has_permission('roles.manage', $conn) || auth_guard_is_admin_session();

if ($action === 'staff_lifecycle_blockers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$canUsers) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    $id = (int) ($_GET['user_id'] ?? 0);
    if ($id < 1) {
        team_hub_json(422, ['success' => false, 'code' => 'USER_ID_REQUIRED']);
    }
    team_hub_json(200, [
        'success' => true,
        'blockers' => $hub->staffLifecycleBlockers($id),
    ]);
}

if ($action === 'staff_detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$canUsers) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    $id = (int) ($_GET['id'] ?? 0);
    $detail = $hub->staffDetail($id);
    if (!$detail) {
        team_hub_json(404, ['success' => false, 'code' => 'NOT_FOUND']);
    }
    team_hub_json(200, ['success' => true, 'staff' => $detail, 'roles' => $hub->loadRoles()]);
}

if ($action === 'role_detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$canRoles) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    $id = (int) ($_GET['id'] ?? 0);
    try {
        team_hub_json(200, ['success' => true, 'role' => $hub->roleDetail($id)]);
    } catch (RuntimeException $exception) {
        team_hub_json(404, ['success' => false, 'code' => $exception->getMessage()]);
    }
}

if ($action === 'generate_pin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$canUsers) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    $exclude = (int) ($_GET['exclude_user_id'] ?? 0);
    try {
        $pin = (new PinService())->generateAvailablePin($conn, $exclude);
        team_hub_json(200, ['success' => true, 'pin' => $pin]);
    } catch (RuntimeException $exception) {
        team_hub_json(500, ['success' => false, 'code' => $exception->getMessage()]);
    }
}

if ($action === 'user_permissions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$canUsers) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    $id = (int) ($_GET['user_id'] ?? 0);
    try {
        team_hub_json(200, ['success' => true, 'permissions' => $hub->userPermissionsDetail($id)]);
    } catch (RuntimeException $exception) {
        team_hub_json(404, ['success' => false, 'code' => $exception->getMessage()]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    team_hub_json(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED']);
}

$userActions = [
    'create_staff' => 'users_write',
    'update_staff' => 'users_write',
    'deactivate_staff' => 'users_write',
    'delete_staff' => 'users_write',
    'reactivate_staff' => 'users_write',
    'unlock_pin' => 'users_write',
    'reset_pin' => 'users_write',
    'save_user_permissions' => 'users_write',
];
$roleActions = [
    'create_role' => 'roles_write',
    'save_role_permissions' => 'roles_write',
    'restore_role_preset' => 'roles_write',
    'delete_role' => 'roles_write',
];

if (isset($userActions[$action])) {
    if (!$canUsers) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    team_hub_require_csrf($userActions[$action]);
} elseif (isset($roleActions[$action])) {
    if (!$canRoles) {
        team_hub_json(403, ['success' => false, 'code' => 'FORBIDDEN']);
    }
    team_hub_require_csrf($roleActions[$action]);
} else {
    team_hub_json(400, ['success' => false, 'code' => 'UNKNOWN_ACTION']);
}

$actorUserId = team_hub_actor_id();

switch ($action) {
    case 'create_staff':
        team_hub_handle_mutation(fn () => $mutations->createStaff($_POST, $actorUserId));
    case 'update_staff':
        team_hub_handle_mutation(fn () => $mutations->updateStaff($_POST, $actorUserId));
    case 'create_role':
        team_hub_handle_mutation(fn () => $mutations->createRole($_POST));
    case 'save_role_permissions':
        team_hub_handle_mutation(fn () => $mutations->saveRolePermissions($_POST));
    case 'restore_role_preset':
        team_hub_handle_mutation(fn () => $mutations->restoreRolePreset(
            (int) ($_POST['role_id'] ?? 0),
            trim((string) ($_POST['role_key'] ?? ''))
        ));
    case 'delete_role':
        team_hub_handle_mutation(fn () => $mutations->deleteRole((int) ($_POST['role_id'] ?? 0)));
    case 'deactivate_staff':
        team_hub_handle_mutation(fn () => $mutations->deactivateStaff((int) ($_POST['user_id'] ?? 0), $actorUserId));
    case 'delete_staff':
        team_hub_handle_mutation(fn () => $mutations->deleteStaff((int) ($_POST['user_id'] ?? 0), $actorUserId));
    case 'reactivate_staff':
        team_hub_handle_mutation(fn () => $mutations->reactivateStaff((int) ($_POST['user_id'] ?? 0), $actorUserId));
    case 'unlock_pin':
        team_hub_handle_mutation(fn () => $mutations->unlockPin((int) ($_POST['user_id'] ?? 0)));
    case 'reset_pin':
        team_hub_handle_mutation(fn () => $mutations->resetPin(
            (int) ($_POST['user_id'] ?? 0),
            trim((string) ($_POST['pin'] ?? ''))
        ));
    case 'save_user_permissions':
        team_hub_handle_mutation(fn () => $mutations->saveUserPermissions($_POST, $actorUserId));
    default:
        team_hub_json(400, ['success' => false, 'code' => 'UNKNOWN_ACTION']);
}
