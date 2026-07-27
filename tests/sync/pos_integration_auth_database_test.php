<?php

require_once __DIR__ . '/../../classes/Pos/Security/PosIntegrationAuth.php';
require_once __DIR__ . '/../../includes/pos_default_accounts.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_integration_auth_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-integration-auth-database-skipped-db-unavailable\n";
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
    $conn->query("INSERT INTO settings (isdeleted) VALUES (0)");

    $settings = posmain_load_pos_settings_row($conn);
    integrationAuthAssert(
        array_key_exists('cofe_integration_secret', $settings),
        'settings loader must expose the Cofe integration secret'
    );
    $secret = 'local-cofe-secret-' . getmypid();
    $stmt = $conn->prepare('UPDATE settings SET cofe_integration_secret = ? WHERE id = 1');
    $stmt->bind_param('s', $secret);
    $stmt->execute();
    $stmt->close();

    $payload = [
        'cofeOrderId' => 'auth-proof',
        'items' => [['itemId' => '1', 'qty' => 1]],
    ];
    try {
        PosIntegrationAuth::requireCofeSignature($payload, [], $conn);
        throw new RuntimeException('missing signature should fail');
    } catch (RuntimeException $exception) {
        integrationAuthAssert(
            $exception->getMessage() === 'INTEGRATION_SIGNATURE_REQUIRED',
            'configured secret must require a signature'
        );
    }

    try {
        PosIntegrationAuth::requireCofeSignature(
            $payload,
            ['HTTP_X_COFE_SIGNATURE' => hash_hmac('sha256', 'wrong-body', $secret)],
            $conn
        );
        throw new RuntimeException('invalid signature should fail');
    } catch (RuntimeException $exception) {
        integrationAuthAssert(
            $exception->getMessage() === 'INTEGRATION_SIGNATURE_INVALID',
            'invalid signature must be rejected'
        );
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    PosIntegrationAuth::requireCofeSignature(
        $payload,
        ['HTTP_X_COFE_SIGNATURE' => hash_hmac('sha256', (string) $body, $secret)],
        $conn
    );

    echo "pos-integration-auth-database-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function integrationAuthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
