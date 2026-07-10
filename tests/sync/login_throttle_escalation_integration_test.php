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

function throttleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new LoginThrottleService();
    $options = [
        'max_attempts' => 5,
        'window_seconds' => 3600,
        'lock_seconds' => 60,
        'max_lock_seconds' => 300,
        'escalate' => true,
    ];
    $row = [];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $row = $service->recordFailure($conn, 'register:test', 'register', $options);
    }
    throttleAssert($service->isBlocked($conn, 'register:test', 'register'), 'fifth failure must lock');
    $firstLockSeconds = strtotime((string) $row['locked_until']) - time();
    throttleAssert($firstLockSeconds >= 55 && $firstLockSeconds <= 65, 'first cooldown should be about 60 seconds');

    $conn->query("UPDATE failed_login_attempts SET locked_until = NULL WHERE username = 'register:test'");
    for ($attempt = 6; $attempt <= 10; $attempt++) {
        $row = $service->recordFailure($conn, 'register:test', 'register', $options);
    }
    $secondLockSeconds = strtotime((string) $row['locked_until']) - time();
    throttleAssert($secondLockSeconds >= 115 && $secondLockSeconds <= 125, 'second cooldown should escalate to about 120 seconds');

    echo "login-throttle-escalation-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}

