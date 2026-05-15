<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Security/LoginThrottleService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_login_throttle_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new LoginThrottleService();
    $options = [
        'max_attempts' => 3,
        'window_seconds' => 300,
        'lock_seconds' => 600,
        'user_agent' => 'phase3-login-test',
    ];

    loginThrottleAssert(!$service->isBlocked($conn, 'Admin', '10.0.0.1', $options), 'fresh login should not be blocked');
    $first = $service->recordFailure($conn, 'Admin', '10.0.0.1', $options);
    loginThrottleAssert((int) $first['attempt_count'] === 1, 'first attempt count mismatch');
    loginThrottleAssert(!$service->isBlocked($conn, 'admin', '10.0.0.1', $options), 'first failure should not block');

    $second = $service->recordFailure($conn, 'admin', '10.0.0.1', $options);
    loginThrottleAssert((int) $second['attempt_count'] === 2, 'second attempt count mismatch');
    loginThrottleAssert(!$service->isBlocked($conn, 'admin', '10.0.0.1', $options), 'second failure should not block');

    $third = $service->recordFailure($conn, 'ADMIN', '10.0.0.1', $options);
    loginThrottleAssert((int) $third['attempt_count'] === 3, 'third attempt count mismatch');
    loginThrottleAssert($service->isBlocked($conn, 'admin', '10.0.0.1', $options), 'third failure should block');

    $service->recordSuccess($conn, 'admin', '10.0.0.1');
    loginThrottleAssert(!$service->isBlocked($conn, 'admin', '10.0.0.1', $options), 'success should clear throttling');

    echo "login-throttle-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function loginThrottleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
