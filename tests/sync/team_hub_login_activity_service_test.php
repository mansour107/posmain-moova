<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Security/TeamHubService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "team-hub-login-activity-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_team_login_activity_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    $conn->query('CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uname VARCHAR(64) NOT NULL,
        userrole INT NULL,
        isdeleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE session_time (
        id INT AUTO_INCREMENT PRIMARY KEY,
        `user` INT NOT NULL,
        crtime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    // Minimal tables TeamHubService::stats may touch if called — not needed here.
    $conn->query("INSERT INTO users (id, uname) VALUES (1, 'p6_admin'), (2, 'p6_manager')");
    $conn->query("INSERT INTO session_time (`user`, crtime) VALUES
        (2, '2026-07-12 10:00:00'),
        (1, '2026-07-12 11:00:00'),
        (2, '2026-07-12 12:00:00')");

    $hub = new TeamHubService($conn);
    $summary = $hub->loginActivitySummary();
    teamLoginServiceAssert($summary['available'] === true, 'summary available');
    teamLoginServiceAssert((int) $summary['total'] === 3, 'total login count');

    $recent = $hub->recentLogins(2);
    teamLoginServiceAssert(count($recent) === 2, 'respects limit');
    teamLoginServiceAssert($recent[0]['uname'] === 'p6_manager', 'newest login first');
    teamLoginServiceAssert($recent[1]['uname'] === 'p6_admin', 'second newest');

    echo "team-hub-login-activity-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function teamLoginServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "team-hub-login-activity-service-fail: {$message}\n");
        exit(1);
    }
}
