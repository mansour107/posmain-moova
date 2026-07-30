<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/IdempotencyService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_request_keys_idem_' . getmypid();

$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "pos-request-keys-idempotency-service-skip mysql-unavailable\n";
    exit(0);
}

$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    posRequestKeysCompletedReplayTest($conn);
    posRequestKeysStaleReclaimTest($conn);

    echo "pos-request-keys-idempotency-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
function posRequestKeysCompletedReplayTest(mysqli $conn): void
{
    $service = new IdempotencyService();
    $scope = 'test.scope.' . bin2hex(random_bytes(4));
    $key = 'key-' . bin2hex(random_bytes(8));
    $hash = $service->requestHash(['b' => 2, 'a' => 1]);
    $otherHash = $service->requestHash(['a' => 999]);

    $conn->begin_transaction();
    try {
        $started = $service->begin($conn, $scope, $key, $hash, [
            'user_id' => 7,
            'tenant' => 11,
            'branch' => 22,
        ]);
        posRequestKeysAssertSame('started', $started['status'], 'new request starts');

        $service->complete($conn, $scope, $key, $hash, [
            'success' => true,
            'order_id' => 123,
        ]);

        $replay = $service->begin($conn, $scope, $key, $hash);
        posRequestKeysAssertSame('completed', $replay['status'], 'same request replays completed response');
        posRequestKeysAssertSame(123, (int) $replay['response']['order_id'], 'replayed response preserves order id');

        $conflict = $service->begin($conn, $scope, $key, $otherHash);
        posRequestKeysAssertSame('conflict', $conflict['status'], 'changed payload conflicts');
        posRequestKeysAssertSame('IDEMPOTENCY_CONFLICT', $conflict['code'], 'conflict code is stable');
    } finally {
        $conn->rollback();
    }
}

function posRequestKeysStaleReclaimTest(mysqli $conn): void
{
    $service = new IdempotencyService();
    $scope = 'test.reclaim.' . bin2hex(random_bytes(4));
    $key = 'key-' . bin2hex(random_bytes(8));
    $hash = $service->requestHash(['request' => 'same']);

    $conn->begin_transaction();
    try {
        posRequestKeysAssertSame('started', $service->begin($conn, $scope, $key, $hash)['status'], 'first processing row starts');

        $same = $service->begin($conn, $scope, $key, $hash);
        posRequestKeysAssertSame('processing', $same['status'], 'same in-flight request is processing before stale TTL');

        $stmt = $conn->prepare("
            UPDATE pos_request_keys
               SET updated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             WHERE scope = ?
               AND idempotency_key = ?
        ");
        $stmt->bind_param('ss', $scope, $key);
        $stmt->execute();
        $stmt->close();

        $reclaimed = $service->begin($conn, $scope, $key, $hash, [
            'stale_after_seconds' => 60,
        ]);
        posRequestKeysAssertSame('reclaimed', $reclaimed['status'], 'explicit stale TTL reclaims processing row');
    } finally {
        $conn->rollback();
    }
}

function posRequestKeysAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
