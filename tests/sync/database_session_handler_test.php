<?php

require_once __DIR__ . '/../../classes/Infrastructure/DatabaseSessionHandler.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    echo "database-session-handler-skip:mysql-unavailable\n";
    exit(0);
}

$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$table = 'app_sessions_test';
$conn->query('DROP TABLE IF EXISTS `' . $table . '`');

$handler = new DatabaseSessionHandler(
    static function () use ($host, $user, $pass, $db, $port): mysqli {
        $testConn = new mysqli($host, $user, $pass, $db, $port);
        $testConn->set_charset('utf8mb4');
        return $testConn;
    },
    $table,
    60,
    0
);

databaseSessionAssert($handler->open('', 'PHPSESSID') === true, 'handler should open cleanly');
databaseSessionAssert($handler->read('missing-session') === '', 'missing session should read as empty');
databaseSessionAssert($handler->write('session-one', 'user_id|i:42;') === true, 'handler should write a session payload');
databaseSessionAssert($handler->read('session-one') === 'user_id|i:42;', 'handler should read the stored payload');

$expiredAt = time() - 10;
$lastActivity = time() - 100;
$payload = 'expired|b:1;';
$stmt = $conn->prepare('INSERT INTO `' . $table . '` (id, payload, last_activity, expires_at) VALUES (?, ?, ?, ?)');
$expiredId = 'expired-session';
$stmt->bind_param('ssii', $expiredId, $payload, $lastActivity, $expiredAt);
$stmt->execute();
$stmt->close();

databaseSessionAssert($handler->read($expiredId) === '', 'expired session should read as empty');
databaseSessionAssert($handler->destroy('session-one') === true, 'handler should destroy a session');
databaseSessionAssert($handler->read('session-one') === '', 'destroyed session should be gone');

$handler->close();
$conn->query('DROP TABLE IF EXISTS `' . $table . '`');
$conn->close();

echo "database-session-handler-ok\n";

function databaseSessionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
