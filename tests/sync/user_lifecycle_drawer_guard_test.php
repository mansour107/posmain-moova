<?php

require_once __DIR__ . '/security_test_database.php';

$fixture = SecurityTestDatabase::create();
require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Security/UserLifecycleGuardService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';

$conn = posmain_db_connect();
$conn->set_charset('utf8mb4');
$fixture->provisionDrawerGuardSchema($conn);

try {
    $guard = new UserLifecycleGuardService();
    $userId = (int) ($conn->query('SELECT id FROM users WHERE COALESCE(isdeleted, 0) != 1 ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
    userLifecycleDrawerAssert($userId > 0, 'need at least one fixture user');
    userLifecycleDrawerAssert(
        $guard->findOpenDrawerSessionsForUser($conn, $userId) === [],
        'closed adoption row should not block'
    );

    $uuid = sprintf(
        '%08s-%04s-%04s-%04s-%12s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
    $stmt = $conn->prepare(
        "INSERT INTO drawer_sessions (
            uuid, user_id, tenant, branch, fund_account_id, opened_at, opened_by,
            opening_cash, status, closed_at
        ) VALUES (?, ?, 0, 0, NULL, NOW(), ?, 0, 'open', NULL)"
    );
    $stmt->bind_param('sii', $uuid, $userId, $userId);
    $stmt->execute();
    $drawerId = (int) $conn->insert_id;
    $stmt->close();
    userLifecycleDrawerAssert($drawerId > 0, 'failed to seed open drawer');

    $open = $guard->findOpenDrawerSessionsForUser($conn, $userId);
    userLifecycleDrawerAssert(count($open) === 1, 'should detect one open drawer');

    try {
        $guard->assertNoOpenDrawerForUser($conn, $userId);
        userLifecycleDrawerAssert(false, 'expected DRAWER_SESSION_OPEN');
    } catch (RuntimeException $exception) {
        userLifecycleDrawerAssert($exception->getMessage() === 'DRAWER_SESSION_OPEN', 'unexpected exception: ' . $exception->getMessage());
    }

    $conn->query("UPDATE drawer_sessions SET status = 'closed', closed_at = NOW() WHERE id = {$drawerId}");
    userLifecycleDrawerAssert($guard->findOpenDrawerSessionsForUser($conn, $userId) === [], 'closed drawer should not block');

    $conn->query("UPDATE drawer_sessions SET status = 'open', closed_at = NOW() WHERE id = {$drawerId}");
    userLifecycleDrawerAssert($guard->findOpenDrawerSessionsForUser($conn, $userId) === [], 'open status with closed_at should not block');

    echo "user-lifecycle-drawer-guard-ok fixture=" . $fixture->databaseName() . "\n";
} finally {
    $conn->close();
    $fixture->close();
}

function userLifecycleDrawerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
