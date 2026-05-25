<?php

if (($argv[1] ?? '') === '--child') {
    recipeManagerOverrideEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_manager_override_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeManagerOverrideEndpointRuntimeCreateSchema($conn);
    recipeManagerOverrideEndpointRuntimeSeedRows($conn);

    $success = recipeManagerOverrideEndpointRuntimeRunChild($db, [
        'action' => 'approve_recipe_stock_override',
        'item_id' => 7001,
        'reason' => 'endpoint manager override smoke',
        'unavailable_reason' => 'Required ingredient out of stock.',
        'role_id' => 2,
        'user_type' => 1,
        'csrf_valid' => true,
    ]);
    recipeManagerOverrideEndpointRuntimeAssert(($success['success'] ?? false) === true, 'manager override endpoint should approve with permission');
    recipeManagerOverrideEndpointRuntimeAssert((int) ($success['approval_id'] ?? 0) > 0, 'manager override endpoint should return approval id');

    $approvalId = (int) $success['approval_id'];
    $approval = recipeManagerOverrideEndpointRuntimeOne($conn, "SELECT * FROM manager_approvals WHERE id = {$approvalId}");
    recipeManagerOverrideEndpointRuntimeAssert($approval !== null, 'manager override endpoint should write an approval row');
    recipeManagerOverrideEndpointRuntimeAssert($approval['action_type'] === 'recipe.stock_override', 'approval should use recipe stock override action type');
    recipeManagerOverrideEndpointRuntimeAssert($approval['target_type'] === 'item', 'approval should target item');
    recipeManagerOverrideEndpointRuntimeAssert((int) $approval['target_id'] === 7001, 'approval should target posted item id');
    recipeManagerOverrideEndpointRuntimeAssert((int) $approval['requested_by'] === 7, 'approval should stamp requesting user');
    recipeManagerOverrideEndpointRuntimeAssert((int) $approval['approved_by'] === 7, 'approval should stamp current manager');
    recipeManagerOverrideEndpointRuntimeAssert($approval['status'] === 'approved', 'approval should be approved in one transaction');
    recipeManagerOverrideEndpointRuntimeAssert($approval['reason'] === 'endpoint manager override smoke', 'approval should preserve reason');

    $metadata = json_decode((string) $approval['metadata_json'], true);
    recipeManagerOverrideEndpointRuntimeAssert(is_array($metadata), 'approval metadata should be JSON');
    recipeManagerOverrideEndpointRuntimeAssert(($metadata['source'] ?? '') === 'pos_grid', 'approval metadata should record POS grid source');
    recipeManagerOverrideEndpointRuntimeAssert(($metadata['unavailable_reason'] ?? '') === 'Required ingredient out of stock.', 'approval metadata should preserve unavailable reason');

    $badCsrf = recipeManagerOverrideEndpointRuntimeRunChild($db, [
        'action' => 'approve_recipe_stock_override',
        'item_id' => 7002,
        'role_id' => 2,
        'user_type' => 1,
        'csrf_valid' => false,
    ]);
    recipeManagerOverrideEndpointRuntimeAssert(($badCsrf['success'] ?? true) === false, 'bad CSRF should fail');
    recipeManagerOverrideEndpointRuntimeAssert(($badCsrf['code'] ?? '') === 'CSRF_INVALID', 'bad CSRF should return CSRF_INVALID');
    recipeManagerOverrideEndpointRuntimeAssert(
        recipeManagerOverrideEndpointRuntimeCount($conn, 'SELECT COUNT(*) AS c FROM manager_approvals') === 1,
        'bad CSRF should not write approvals'
    );

    $denied = recipeManagerOverrideEndpointRuntimeRunChild($db, [
        'action' => 'approve_recipe_stock_override',
        'item_id' => 7003,
        'role_id' => 3,
        'user_type' => 1,
        'csrf_valid' => true,
    ]);
    recipeManagerOverrideEndpointRuntimeAssert(($denied['success'] ?? true) === false, 'missing permission should fail');
    recipeManagerOverrideEndpointRuntimeAssert(($denied['code'] ?? '') === 'PERMISSION_DENIED', 'missing permission should return PERMISSION_DENIED');
    recipeManagerOverrideEndpointRuntimeAssert(
        recipeManagerOverrideEndpointRuntimeCount($conn, 'SELECT COUNT(*) AS c FROM manager_approvals') === 1,
        'missing permission should not write approvals'
    );

    echo "recipe-manager-override-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeManagerOverrideEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-manager-override-csrf-fixed';
    $postedCsrf = !empty($payload['csrf_valid']) ? $csrf : 'wrong-recipe-manager-override-csrf';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'ajax/manager_approval.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/manager_approval.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $postedCsrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $postedCsrf;
    $_POST = [
        'action' => (string) ($payload['action'] ?? ''),
        'item_id' => (int) ($payload['item_id'] ?? 0),
        'reason' => (string) ($payload['reason'] ?? ''),
        'unavailable_reason' => (string) ($payload['unavailable_reason'] ?? ''),
        'csrf_token' => $postedCsrf,
    ];

    session_id('recipemanageroverride' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'recipe_manager_override_smoke';
    $_SESSION['userid'] = 7;
    $_SESSION['user_id'] = 7;
    $_SESSION['usrole'] = (int) ($payload['role_id'] ?? 2);
    $_SESSION['userrole'] = (int) ($payload['role_id'] ?? 2);
    $_SESSION['usty'] = (int) ($payload['user_type'] ?? 1);
    $_SESSION['posmain_csrf_tokens'] = [
        'pos_browser' => $csrf,
    ];

    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/manager_approval.php';
    exit(0);
}

function recipeManagerOverrideEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_ENABLE_RECIPES' => '1',
        'POSMAIN_RECIPE_MODE' => 'availability_pilot',
        'POSMAIN_RECIPE_AVAILABILITY' => '1',
        'POSMAIN_RECIPE_ALLOW_NEGATIVE_STOCK_WITH_APPROVAL' => '1',
        'POSMAIN_RECIPE_MOOVA_SYNC' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start manager override endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Manager override endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Manager override endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function recipeManagerOverrideEndpointRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass) VALUES ('ar', '')");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            edit_sales TINYINT(1) NOT NULL DEFAULT 0,
            edit_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE manager_approvals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_type VARCHAR(80) NOT NULL,
            target_type VARCHAR(80) NOT NULL,
            target_id BIGINT UNSIGNED NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            status ENUM('requested','approved','declined','expired') NOT NULL DEFAULT 'requested',
            reason VARCHAR(500) NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_manager_approvals_status (status, created_at),
            KEY idx_manager_approvals_target (target_type, target_id),
            KEY idx_manager_approvals_action (action_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeManagerOverrideEndpointRuntimeSeedRows(mysqli $conn): void
{
    $conn->query("INSERT INTO towns (id, tname) VALUES (1, 'Runtime Town')");
    $conn->query("
        INSERT INTO usr_pwrs (id, rollname, edit_sales, edit_stock, isdeleted) VALUES
        (2, 'Recipe Manager', 1, 1, 0),
        (3, 'Cashier Without Override', 0, 0, 0)
    ");
}

function recipeManagerOverrideEndpointRuntimeOne(mysqli $conn, string $sql): ?array
{
    $row = $conn->query($sql)->fetch_assoc();

    return $row ?: null;
}

function recipeManagerOverrideEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = recipeManagerOverrideEndpointRuntimeOne($conn, $sql);

    return (int) ($row['c'] ?? 0);
}

function recipeManagerOverrideEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
