<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = (string) ($argv[1] ?? '');
$orderId = (int) ($argv[2] ?? 0);
$tableId = (int) ($argv[3] ?? 0);
$mutationVersion = (int) ($argv[4] ?? 0);
$idempotencyKey = (string) ($argv[5] ?? '');
if ($db === '' || $orderId < 1 || $tableId < 1 || $mutationVersion < 1 || $idempotencyKey === '') {
    fwrite(STDERR, "CONCURRENT_WORKER_ARGS_REQUIRED\n");
    exit(2);
}

$conn = new mysqli(
    getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
    getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
    getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
    $db,
    (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307)
);
$conn->set_charset('utf8mb4');

try {
    $result = (new PosOrderMutationService())->payTableOrder($conn, [
        'table_id' => $tableId,
        'order_id' => $orderId,
        'paid' => '10.00',
        'payment_method' => 'cash',
        'mutation_version' => $mutationVersion,
        'idempotency_key' => $idempotencyKey,
    ], ['user_id' => 7]);

    echo json_encode([
        'status' => 'success',
        'mutation_version' => (int) ($result['data']['mutation_version'] ?? 0),
        'payment_id' => (int) ($result['data']['payment_id'] ?? 0),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'error',
        'code' => $exception->getMessage(),
        'class' => get_class($exception),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $conn->close();
}
