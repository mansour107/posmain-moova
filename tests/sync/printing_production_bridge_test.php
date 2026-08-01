<?php

require_once __DIR__ . '/../../classes/Pos/Service/LocalPrinterQueueService.php';
require_once __DIR__ . '/../../classes/Pos/Service/LocalPrintBridgeService.php';
require_once __DIR__ . '/../../classes/Pos/Service/LocalNetworkPrinterService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintBridgeClient.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintBridgeHttpHandler.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrinterHealthService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintUserMessageService.php';

function productionPrintAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$state = sys_get_temp_dir() . '/posmain-production-print-' . getmypid() . '-' . bin2hex(random_bytes(4));
$submissions = 0;
$runner = static function (array $command, ?string $stdin) use (&$submissions): array {
    if ($command === ['lpstat', '-p']) {
        return ['exit_code' => 0, 'stdout' => "printer Front_Receipt is idle. enabled since now\nprinter Kitchen_USB disabled since now\n", 'stderr' => ''];
    }
    if ($command === ['lpstat', '-v']) {
        return ['exit_code' => 0, 'stdout' => "device for Front_Receipt: usb://vendor/model\ndevice for Kitchen_USB: usb://vendor/kitchen\n", 'stderr' => ''];
    }
    if ($command === ['lp', '-d', 'Front_Receipt', '-o', 'raw']) {
        $submissions++;
        productionPrintAssert($stdin === "TEST\n", 'raw bytes must reach the operating-system spooler unchanged');
        return ['exit_code' => 0, 'stdout' => 'request id is Front_Receipt-42', 'stderr' => ''];
    }
    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'unexpected command'];
};

try {
    $queues = new LocalPrinterQueueService('Darwin', $runner);
    $printers = $queues->printers();
    productionPrintAssert(count($printers) === 2, 'installed cable queues must be discovered from the real OS contract');
    productionPrintAssert($printers[0]['queue'] === 'Front_Receipt' && $printers[0]['connected'] === true, 'ready queue must show connected');
    productionPrintAssert($printers[1]['connected'] === false, 'disabled queue must show disconnected');

    $bridge = new LocalPrintBridgeService($queues, $state);
    $payload = [
        'delivery_key' => 'job:91:printer:7',
        'transport' => 'cable',
        'queue' => 'Front_Receipt',
        'job_id' => 91,
        'printer_id' => 7,
        'payload_sha256' => hash('sha256', "TEST\n"),
        'payload_base64' => base64_encode("TEST\n"),
    ];
    $first = $bridge->print($payload);
    $replay = $bridge->print($payload);
    productionPrintAssert($first['accepted'] === true && $first['replayed'] === false, 'first cable submission must be accepted');
    productionPrintAssert($replay['replayed'] === true && $submissions === 1, 'double click or response-loss retry must not spool twice');

    $secret = str_repeat('b', 64);
    $handler = new PrintBridgeHttpHandler($bridge, $secret);
    $unauthorized = $handler->handle('GET', '/v1/printers', [], '');
    productionPrintAssert($unauthorized['status'] === 401, 'bridge inventory must reject unauthenticated callers');
    $authenticatedClient = new PrintBridgeClient('http://127.0.0.1:17981', $secret, static function (string $method, string $path, array $headers, string $body) use ($handler): array {
        $response = $handler->handle($method, $path, $headers, $body);
        return ['_http_status' => $response['status']] + $response['body'];
    });
    productionPrintAssert(count($authenticatedClient->printers()) === 2, 'authenticated application must discover real OS queues');
    $authenticatedReplay = $authenticatedClient->print(
        ['id' => 91, 'idempotency_key' => 'job:91:printer:7'],
        ['id' => 7, 'connection_type' => 'usb', 'config' => ['queue_name' => 'Front_Receipt']],
        ['bytes' => "TEST\n"]
    );
    productionPrintAssert($authenticatedReplay['replayed'] === true && $submissions === 1, 'authenticated response replay must remain exactly once');

    $networkConnections = 0;
    $networkPeers = [];
    $network = new LocalNetworkPrinterService(static function () use (&$networkConnections, &$networkPeers) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $networkConnections++;
        $networkPeers[] = $pair[1];
        return $pair[0];
    });
    $networkBridge = new LocalPrintBridgeService($queues, $state . '-network', $network);
    $networkPayload = $payload;
    $networkPayload['delivery_key'] = 'job:92:printer:8';
    $networkPayload['transport'] = 'network';
    $networkPayload['queue'] = '';
    $networkPayload['host'] = '192.0.2.25';
    $networkPayload['port'] = 9100;
    $networkFirst = $networkBridge->print($networkPayload);
    $networkReplay = $networkBridge->print($networkPayload);
    productionPrintAssert($networkFirst['transport'] === 'network' && $networkReplay['replayed'] === true, 'network delivery must use the same durable bridge receipt');
    productionPrintAssert($networkConnections === 1, 'network response-loss retry must not open a second printer connection');
    foreach ($networkPeers as $peer) fclose($peer);

    $conflict = false;
    try {
        $changed = $payload;
        $changed['payload_base64'] = base64_encode('CHANGED');
        $changed['payload_sha256'] = hash('sha256', 'CHANGED');
        $bridge->print($changed);
    } catch (Throwable $exception) {
        $conflict = $exception->getMessage() === 'PRINT_BRIDGE_IDEMPOTENCY_CONFLICT';
    }
    productionPrintAssert($conflict, 'same delivery key with different bytes must fail closed');

    $fakeClient = new PrintBridgeClient('http://127.0.0.1:17981', str_repeat('a', 64), static function (): array {
        return ['_http_status' => 200, 'ok' => true, 'printers' => [[
            'queue' => 'Front_Receipt', 'name' => 'Front receipt', 'connected' => true, 'state' => 'ready',
        ]]];
    });
    $cableHealth = (new PrinterHealthService($fakeClient))->check([
        'is_active' => true, 'connection_type' => 'usb', 'config' => ['queue_name' => 'Front_Receipt'],
    ]);
    productionPrintAssert($cableHealth['connected'] === true && $cableHealth['label'] === 'متصلة', 'cable health must use the authenticated bridge inventory');
    $networkHealth = (new PrinterHealthService($fakeClient, static fn(): bool => false))->check([
        'is_active' => true, 'connection_type' => 'network', 'config' => ['host' => '192.0.2.10', 'port' => 9100],
    ]);
    productionPrintAssert($networkHealth['connected'] === false && $networkHealth['label'] === 'غير متصلة', 'unreachable network printer must not be shown connected');

    foreach (['PRINT_BRIDGE_QUEUE_OFFLINE', 'PRINT_NETWORK_CONNECT_FAILED', 'PRINT_RELIABLE_SCHEMA_REQUIRED'] as $code) {
        $message = PrintUserMessageService::forCode($code);
        productionPrintAssert(strpos($message, '_') === false, 'operator message must never expose an internal code');
        productionPrintAssert(preg_match('/[\x{0600}-\x{06FF}]/u', $message) === 1, 'operator message must be readable Arabic');
    }
    $page = file_get_contents(__DIR__ . '/../../printer_management.php');
    productionPrintAssert(strpos($page, 'محاكي') === false && strpos($page, 'simulator_key') === false, 'production settings must not expose a simulator mode');
    productionPrintAssert(strpos($page, 'value="usb"') !== false && strpos($page, 'value="network"') !== false, 'settings must expose network and cable production transports');
    $macInstaller = file_get_contents(__DIR__ . '/../../deploy/printing/install-macos.sh');
    $linuxInstaller = file_get_contents(__DIR__ . '/../../deploy/printing/install-linux.sh');
    $macBridgeTemplate = file_get_contents(__DIR__ . '/../../deploy/printing/launchd/com.posmain.print-bridge.plist');
    $macWorkerTemplate = file_get_contents(__DIR__ . '/../../deploy/printing/launchd/com.posmain.print-worker.plist');
    $linuxWorkerTemplate = file_get_contents(__DIR__ . '/../../deploy/printing/systemd/posmain-print-worker.service');
    foreach ([$macInstaller, $linuxInstaller] as $installer) {
        productionPrintAssert(strpos($installer, 'POSMAIN_PRINT_BRIDGE_LISTEN') !== false, 'installer must support a host bridge listen address');
        productionPrintAssert(strpos($installer, 'POSMAIN_PRINT_BRIDGE_APP_URL') !== false, 'installer must support a container-reachable application URL');
        productionPrintAssert(strpos($installer, 'POSMAIN_PRINT_BRIDGE_WORKER_URL') !== false, 'installer must preserve a host-local worker URL');
    }
    productionPrintAssert(strpos($macBridgeTemplate, '__BRIDGE_LISTEN__') !== false, 'macOS bridge service must use the selected listen address');
    productionPrintAssert(strpos($macWorkerTemplate, '__BRIDGE_WORKER_URL__') !== false, 'macOS worker must use its host-local bridge URL');
    productionPrintAssert(strpos($linuxWorkerTemplate, '__BRIDGE_WORKER_URL__') !== false, 'Linux worker must use its host-local bridge URL');

    echo "printing-production-bridge-ok\n";
} finally {
    productionPrintRemoveTree($state);
    productionPrintRemoveTree($state . '-network');
}

function productionPrintRemoveTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($target) ? productionPrintRemoveTree($target) : @unlink($target);
    }
    @rmdir($path);
}
