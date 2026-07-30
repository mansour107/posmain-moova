<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PrintWorkerService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $options = getopt('', ['limit::']);
    $limit = min(500, max(1, (int) ($options['limit'] ?? 50)));
    $conn = posmain_db_connect();
    $worker = new PrintWorkerService();
    $processed = [];

    for ($i = 0; $i < $limit; $i++) {
        $job = $worker->processNext($conn);
        if ($job === null) {
            break;
        }
        $processed[] = [
            'id' => $job['id'],
            'status' => $job['status'],
            'attempts' => $job['attempts'],
            'last_error' => $job['last_error'],
        ];
    }
    $conn->close();

    echo json_encode([
        'ok' => true,
        'processed_count' => count($processed),
        'jobs' => $processed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
