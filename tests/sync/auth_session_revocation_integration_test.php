<?php

require_once __DIR__ . '/../../includes/session_bootstrap.php';
require_once __DIR__ . '/../../classes/Security/MainAuthenticationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_auth_revocation_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function authRevocationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query(
        'CREATE TABLE users (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            userrole INT NOT NULL,
            usertype INT NOT NULL,
            auth_version INT UNSIGNED NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB'
    );
    $conn->query(
        'CREATE TABLE session_time (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB'
    );
    $conn->query(
        "INSERT INTO users (id, uname, userrole, usertype, auth_version, isdeleted)
         VALUES (7, 'hosted_user', 2, 2, 3, 0)"
    );

    $auth = new MainAuthenticationService();
    $auth->establishSession($conn, [
        'id' => 7,
        'uname' => 'hosted_user',
        'userrole' => 2,
        'usertype' => 2,
        'auth_version' => 3,
    ], ['auth_method' => 'password']);

    authRevocationAssert(
        (int) ($_SESSION['posmain_auth_version'] ?? 0) === 3,
        'password sessions must capture the current auth_version'
    );
    authRevocationAssert(
        $auth->sessionAuthVersionValid($conn),
        'fresh password session should be valid'
    );

    $conn->query('UPDATE users SET auth_version = auth_version + 1 WHERE id = 7');
    authRevocationAssert(
        !$auth->sessionAuthVersionValid($conn),
        'auth_version bump must revoke an existing password session'
    );

    echo "auth-session-revocation-integration-ok db={$db}\n";
} finally {
    $_SESSION = [];
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}

