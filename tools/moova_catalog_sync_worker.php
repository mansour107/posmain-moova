<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Moova/MoovaCatalogSyncWorker.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $options = getopt('', ['batch-size::']);
    $result = (new MoovaCatalogSyncWorker())->runOnce($conn, posmain_app_config(), [
        'batch_size' => max(1, (int) ($options['batch-size'] ?? 10)),
    ]);
    $conn->close();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
