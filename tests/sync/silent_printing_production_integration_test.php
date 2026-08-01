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
$db = 'posmain_silent_production_' . getmypid();
$admin = new mysqli($host, $user, $pass, '', $port);
$conn = null;

try {
    $admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn = new mysqli($host, $user, $pass, $db, $port);
    (new SyncSchemaManager())->apply($conn);
    $jobs = new PrintJobService();
    $routing = new PrinterRoutingService($jobs);
    $transport = new class extends PrinterTransportService {
        public array $deliveries = [];
        public function send(array $job, array $printer, array $rendered): array
        {
            $this->deliveries[] = ['job' => $job, 'printer' => $printer, 'bytes' => (string) ($rendered['bytes'] ?? '')];
            return ['accepted' => true, 'transport' => $printer['connection_type'], 'printer_id' => $printer['id'], 'replayed' => false];
        }
    };
    $worker = new PrintWorkerService($jobs, null, $transport);
    $dispatch = new SilentPrintDispatchService(null, $jobs, $routing, $worker);
    $scope = ['tenant' => 4, 'branch' => 9];

    $food = $jobs->savePrinter($conn, [
        'name' => 'Food network', 'printer_type' => 'kitchen', 'connection_type' => 'network', 'tenant' => 4, 'branch' => 9,
        'config' => $routing->buildPrinterConfig(['connection_type' => 'network', 'host' => '192.0.2.20', 'port' => 9100, 'functions' => ['kot'], 'category_ids' => [10]]),
    ]);
    $drinks = $jobs->savePrinter($conn, [
        'name' => 'Drinks cable', 'printer_type' => 'kitchen', 'connection_type' => 'usb', 'tenant' => 4, 'branch' => 9,
        'config' => $routing->buildPrinterConfig(['connection_type' => 'usb', 'queue_name' => 'Kitchen_Drinks', 'functions' => ['kot'], 'category_ids' => [20]]),
    ]);
    $receipt = $jobs->savePrinter($conn, [
        'name' => 'Receipt cable', 'printer_type' => 'receipt', 'connection_type' => 'usb', 'tenant' => 4, 'branch' => 9,
        'config' => $routing->buildPrinterConfig(['connection_type' => 'usb', 'queue_name' => 'Front_Receipt', 'functions' => ['receipt', 'document'], 'all_categories' => true]),
    ]);
    $jobs->savePrinter($conn, [
        'name' => 'Legacy browser', 'printer_type' => 'receipt', 'connection_type' => 'browser', 'tenant' => 4, 'branch' => 9,
        'config' => $routing->buildPrinterConfig(['connection_type' => 'browser', 'functions' => ['receipt'], 'all_categories' => true]),
    ]);
    $receiptRoutes = $routing->route($conn, 'receipt', ['document_type' => 'receipt', 'lines' => []], $scope);
    productionIntegrationAssert(count($receiptRoutes) === 1 && (int) $receiptRoutes[0]['printer']['id'] === (int) $receipt['id'], 'legacy browser records must never receive silent jobs');

    $payload = [
        'document_type' => 'kot', 'order' => ['id' => 700, 'pro_id' => 'T-700'], 'table' => ['name' => 'A7'], 'customer' => [],
        'totals' => ['total' => '9.00', 'discount' => '0.00', 'net' => '9.00', 'paid' => '0.00', 'remaining' => '9.00'],
        'lines' => [
            ['detail_id' => 1, 'item_id' => 101, 'item_group_id' => 10, 'name' => 'Burger', 'qty' => '1.000', 'line_total' => '6.00'],
            ['detail_id' => 2, 'item_id' => 202, 'item_group_id' => 20, 'name' => 'Cola', 'qty' => '1.000', 'line_total' => '3.00'],
        ],
    ];
    $first = $dispatch->dispatchPayload($conn, 'kot', $payload, 'browser:production-routing-700', $scope, 8, 700);
    productionIntegrationAssert($first['status'] === 'printed' && count($first['jobs']) === 2, 'food and drinks must create exactly two completed jobs');
    $byPrinter = [];
    foreach ($transport->deliveries as $delivery) $byPrinter[(int) $delivery['printer']['id']] = $delivery['bytes'];
    productionIntegrationAssert(strpos($byPrinter[$food['id']], 'Burger') !== false && strpos($byPrinter[$food['id']], 'Cola') === false, 'food printer must receive only food');
    productionIntegrationAssert(strpos($byPrinter[$drinks['id']], 'Cola') !== false && strpos($byPrinter[$drinks['id']], 'Burger') === false, 'drinks printer must receive only drinks');

    $duplicate = $dispatch->dispatchPayload($conn, 'kot', $payload, 'browser:production-routing-700', $scope, 8, 700);
    productionIntegrationAssert(array_column($duplicate['jobs'], 'id') === array_column($first['jobs'], 'id'), 'double click must resolve to the original durable jobs');
    productionIntegrationAssert(count($transport->deliveries) === 2, 'double click must not call either physical transport again');

    $unassigned = $payload;
    $unassigned['lines'][] = ['detail_id' => 3, 'item_id' => 303, 'item_group_id' => 30, 'name' => 'Dessert', 'qty' => '1.000', 'line_total' => '4.00'];
    productionIntegrationExpect(static fn() => $dispatch->dispatchPayload($conn, 'kot', $unassigned, 'browser:unassigned-700', $scope, 8, 700), 'PRINT_KOT_LINE_UNROUTED');
    $count = (int) $conn->query('SELECT COUNT(*) AS c FROM print_jobs WHERE order_id = 700')->fetch_assoc()['c'];
    productionIntegrationAssert($count === 2, 'unrouted kitchen request must not create partial jobs');

    echo "silent-printing-production-integration-ok db={$db}\n";
} finally {
    if ($conn instanceof mysqli) $conn->close();
    $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    $admin->close();
}

function productionIntegrationAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function productionIntegrationExpect(callable $callback, string $code): void
{
    try { $callback(); } catch (Throwable $exception) {
        productionIntegrationAssert(str_starts_with($exception->getMessage(), $code), 'expected ' . $code . ', got ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('expected exception ' . $code);
}
