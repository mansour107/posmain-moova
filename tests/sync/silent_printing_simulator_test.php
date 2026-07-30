<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintJobService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrinterRoutingService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrinterTransportService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintWorkerService.php';
require_once __DIR__ . '/../../classes/Pos/Service/SilentPrintDispatchService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_silent_print_' . getmypid();
$simulator = sys_get_temp_dir() . '/posmain-silent-print-' . getmypid() . '-' . bin2hex(random_bytes(4));
putenv('POSMAIN_PRINT_MODE=silent');
putenv('POSMAIN_PRINT_SIMULATOR_DIR=' . $simulator);

$admin = new mysqli($host, $user, $pass, '', $port);
$conn = null;
$terminalTwoConn = null;

try {
    $admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn = new mysqli($host, $user, $pass, $db, $port);
    $terminalTwoConn = new mysqli($host, $user, $pass, $db, $port);
    (new SyncSchemaManager())->apply($conn);

    $jobs = new PrintJobService();
    $routing = new PrinterRoutingService($jobs);
    $worker = new PrintWorkerService($jobs);
    $dispatch = new SilentPrintDispatchService(null, $jobs, $routing, $worker);
    $scope = ['tenant' => 4, 'branch' => 9];

    $food = $jobs->savePrinter($conn, [
        'name' => 'Food kitchen',
        'printer_type' => 'kitchen',
        'connection_type' => 'file',
        'tenant' => 4,
        'branch' => 9,
        'config' => $routing->buildPrinterConfig([
            'connection_type' => 'file',
            'simulator_key' => 'food',
            'functions' => ['kot'],
            'category_ids' => [10],
        ]),
    ]);
    $drinks = $jobs->savePrinter($conn, [
        'name' => 'Drinks kitchen',
        'printer_type' => 'kitchen',
        'connection_type' => 'file',
        'tenant' => 4,
        'branch' => 9,
        'config' => $routing->buildPrinterConfig([
            'connection_type' => 'file',
            'simulator_key' => 'drinks',
            'functions' => ['kot'],
            'category_ids' => [20],
        ]),
    ]);
    $receipt = $jobs->savePrinter($conn, [
        'name' => 'Front receipt',
        'printer_type' => 'receipt',
        'connection_type' => 'file',
        'tenant' => 4,
        'branch' => 9,
        'config' => $routing->buildPrinterConfig([
            'connection_type' => 'file',
            'simulator_key' => 'front',
            'functions' => ['receipt', 'report', 'document'],
            'all_categories' => true,
        ]),
    ]);
    $jobs->savePrinter($conn, [
        'name' => 'Legacy browser receipt',
        'printer_type' => 'receipt',
        'connection_type' => 'browser',
        'tenant' => 4,
        'branch' => 9,
        'config' => $routing->buildPrinterConfig([
            'connection_type' => 'browser',
            'functions' => ['receipt'],
            'all_categories' => true,
        ]),
    ]);
    $receiptRoutes = $routing->route(
        $conn,
        'receipt',
        ['document_type' => 'receipt', 'lines' => []],
        $scope
    );
    silentSimulatorAssert(
        count($receiptRoutes) === 1
        && (int) $receiptRoutes[0]['printer']['id'] === (int) $receipt['id'],
        'legacy browser printer records must be retained but excluded from silent routing'
    );

    $payload = [
        'document_type' => 'kot',
        'order' => ['id' => 700, 'pro_id' => 'T-700'],
        'table' => ['name' => 'A7'],
        'customer' => [],
        'totals' => ['total' => '9.00', 'discount' => '0.00', 'net' => '9.00', 'paid' => '0.00', 'remaining' => '9.00'],
        'lines' => [
            ['detail_id' => 1, 'item_id' => 101, 'item_group_id' => 10, 'name' => 'Burger', 'qty' => '1.000', 'line_total' => '6.00'],
            ['detail_id' => 2, 'item_id' => 202, 'item_group_id' => 20, 'name' => 'Cola', 'qty' => '1.000', 'line_total' => '3.00'],
        ],
    ];
    $first = $dispatch->dispatchPayload($conn, 'kot', $payload, 'browser:double-click-700', $scope, 8, 700);
    silentSimulatorAssert($first['status'] === 'printed', 'split KOT should print synchronously');
    silentSimulatorAssert(count($first['jobs']) === 2, 'food and drinks must create two jobs');
    $byPrinter = [];
    foreach ($first['jobs'] as $job) {
        $byPrinter[$job['printer_id']] = $job;
    }
    $foodText = file_get_contents($byPrinter[$food['id']]['delivery_receipt']['text_path']);
    $drinkText = file_get_contents($byPrinter[$drinks['id']]['delivery_receipt']['text_path']);
    silentSimulatorAssert(strpos($foodText, 'Burger') !== false && strpos($foodText, 'Cola') === false, 'food route must contain only food');
    silentSimulatorAssert(strpos($drinkText, 'Cola') !== false && strpos($drinkText, 'Burger') === false, 'drink route must contain only drinks');

    $duplicate = $dispatch->dispatchPayload($conn, 'kot', $payload, 'browser:double-click-700', $scope, 8, 700);
    silentSimulatorAssert(
        array_column($duplicate['jobs'], 'id') === array_column($first['jobs'], 'id'),
        'same request key must return the same durable jobs'
    );
    silentSimulatorAssert(count(glob($simulator . '/food/*.txt')) === 1, 'double click must not create a second food artifact');
    silentSimulatorAssert(count(glob($simulator . '/drinks/*.txt')) === 1, 'double click must not create a second drink artifact');

    silentSimulatorExpect(function () use ($dispatch, $conn, $payload, $scope) {
        $conflicting = $payload;
        $conflicting['lines'][0]['qty'] = '2.000';
        $dispatch->dispatchPayload(
            $conn,
            'kot',
            $conflicting,
            'browser:double-click-700',
            $scope,
            8,
            700
        );
    }, 'PRINT_IDEMPOTENCY_CONFLICT');

    silentSimulatorExpect(function () use ($dispatch, $conn, $payload, $scope) {
        $unassigned = $payload;
        $unassigned['lines'][] = [
            'detail_id' => 3,
            'item_id' => 303,
            'item_group_id' => 30,
            'name' => 'Dessert',
            'qty' => '1.000',
            'line_total' => '4.00',
        ];
        $dispatch->dispatchPayload($conn, 'kot', $unassigned, 'browser:unassigned-700', $scope, 8, 700);
    }, 'PRINT_KOT_LINE_UNROUTED');
    $orderJobCount = (int) $conn->query(
        'SELECT COUNT(*) AS c FROM print_jobs WHERE order_id = 700'
    )->fetch_assoc()['c'];
    silentSimulatorAssert(
        $orderJobCount === 2,
        'unrouted or conflicting KOT requests must not create partial additional jobs'
    );

    $responseLossJob = $jobs->enqueue($conn, [
        'job_type' => 'document',
        'printer_id' => $receipt['id'],
        'payload' => ['title' => 'Response loss', 'content_text' => 'One physical intent'],
        'idempotency_key' => 'print:' . hash('sha256', 'response-loss'),
    ]);
    $printedOnce = $worker->processJob($conn, $responseLossJob['id'], 'worker-one');
    $conn->query(
        "UPDATE print_jobs
         SET status = 'queued', locked_by = NULL, locked_until = NULL, printed_at = NULL
         WHERE id = " . (int) $responseLossJob['id']
    );
    $replayed = $worker->processJob($conn, $responseLossJob['id'], 'worker-two');
    silentSimulatorAssert($printedOnce['delivery_receipt']['replayed'] === false, 'first simulator acceptance must be new');
    silentSimulatorAssert($replayed['delivery_receipt']['replayed'] === true, 'response-loss retry must reuse simulator receipt');
    silentSimulatorAssert(count(glob($simulator . '/front/*.txt')) === 1, 'response-loss replay must not write duplicate simulator output');

    $claimJob = $jobs->enqueue($conn, [
        'job_type' => 'document',
        'printer_id' => $receipt['id'],
        'payload' => ['title' => 'Claim race', 'content_text' => 'Single worker'],
        'idempotency_key' => 'print:' . hash('sha256', 'claim-race'),
    ]);
    $jobs->claim($conn, $claimJob['id'], 'terminal-one', 45);
    silentSimulatorExpect(
        static fn() => $jobs->claim($terminalTwoConn, $claimJob['id'], 'terminal-two', 45),
        'PRINT_JOB_ALREADY_CLAIMED'
    );
    $nextJob = $jobs->enqueue($conn, [
        'job_type' => 'document',
        'printer_id' => $receipt['id'],
        'payload' => ['title' => 'Next claim', 'content_text' => 'Skip active competing lease'],
        'idempotency_key' => 'print:' . hash('sha256', 'next-after-claim-race'),
    ]);
    $nextProcessed = $worker->processNext($terminalTwoConn, 'terminal-two');
    silentSimulatorAssert(
        $nextProcessed !== null
        && $nextProcessed['id'] === $nextJob['id']
        && $nextProcessed['status'] === 'printed',
        'worker must skip an actively claimed job and safely deliver the next eligible job'
    );

    $flaky = new class extends PrinterTransportService {
        public int $calls = 0;
        public function send(array $job, array $printer, array $rendered): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                throw new PrintTransportException('PRINT_NETWORK_CONNECT_FAILED', true);
            }
            return [
                'transport' => 'test',
                'accepted' => true,
                'job_id' => $job['id'],
                'printer_id' => $printer['id'],
            ];
        }
    };
    $retryWorker = new PrintWorkerService($jobs, null, $flaky);
    $retryJob = $jobs->enqueue($conn, [
        'job_type' => 'document',
        'printer_id' => $receipt['id'],
        'payload' => ['title' => 'Retry', 'content_text' => 'Safe reconnect'],
        'idempotency_key' => 'print:' . hash('sha256', 'safe-retry'),
    ]);
    $queued = $retryWorker->processJob($conn, $retryJob['id'], 'retry-one');
    silentSimulatorAssert($queued['status'] === 'queued' && $queued['attempts'] === 1, 'zero-byte connection failure should queue a retry');
    $conn->query("UPDATE print_jobs SET next_retry_at = NULL WHERE id = " . (int) $retryJob['id']);
    $retried = $retryWorker->processJob($conn, $retryJob['id'], 'retry-two');
    silentSimulatorAssert($retried['status'] === 'printed' && $retried['attempts'] === 2, 'safe retry should eventually print once');

    $uncertainTransport = new class extends PrinterTransportService {
        public function send(array $job, array $printer, array $rendered): array
        {
            throw new PrintTransportException('PRINT_NETWORK_DELIVERY_UNCERTAIN', false);
        }
    };
    $uncertainWorker = new PrintWorkerService($jobs, null, $uncertainTransport);
    $uncertainJob = $jobs->enqueue($conn, [
        'job_type' => 'document',
        'printer_id' => $receipt['id'],
        'payload' => ['title' => 'Uncertain', 'content_text' => 'Inspect paper before retry'],
        'idempotency_key' => 'print:' . hash('sha256', 'uncertain-delivery'),
    ]);
    $uncertainFailed = $uncertainWorker->processJob($conn, $uncertainJob['id'], 'uncertain-one');
    silentSimulatorAssert(
        $uncertainFailed['status'] === 'failed'
        && $uncertainFailed['last_error'] === 'PRINT_NETWORK_DELIVERY_UNCERTAIN',
        'uncertain delivery must fail without automatic retry'
    );
    silentSimulatorExpect(
        static fn() => $dispatch->retryFailedJob($conn, $uncertainJob['id'], $scope, false),
        'PRINT_UNCERTAIN_RETRY_CONFIRMATION_REQUIRED'
    );
    $uncertainRetried = $dispatch->retryFailedJob($conn, $uncertainJob['id'], $scope, true);
    silentSimulatorAssert(
        $uncertainRetried['status'] === 'printed',
        'manager-confirmed uncertain delivery may be retried explicitly'
    );

    silentSimulatorExpect(function () use ($jobs, $conn, $food) {
        $jobs->savePrinter($conn, [
            'id' => $food['id'],
            'name' => 'Cross-scope update',
            'printer_type' => 'kitchen',
            'connection_type' => 'file',
            'tenant' => 99,
            'branch' => 99,
            'config' => ['simulator_key' => 'bad'],
        ]);
    }, 'PRINTER_NOT_FOUND');

    echo "silent-printing-simulator-ok db={$db}\n";
} finally {
    if ($terminalTwoConn instanceof mysqli) {
        $terminalTwoConn->close();
    }
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    $admin->close();
    silentSimulatorRemoveTree($simulator);
    putenv('POSMAIN_PRINT_MODE');
    putenv('POSMAIN_PRINT_SIMULATOR_DIR');
}

function silentSimulatorExpect(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        silentSimulatorAssert(
            str_starts_with($exception->getMessage(), $code),
            'expected ' . $code . ', got ' . $exception->getMessage()
        );
        return;
    }
    throw new RuntimeException('expected exception ' . $code);
}

function silentSimulatorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function silentSimulatorRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            silentSimulatorRemoveTree($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}
