<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This smoke harness must be run from the command line.\n");
    exit(2);
}

$options = moova_smoke_parse_args($argv);
if (!empty($options['help'])) {
    echo "Usage: php tools/moova_reachability_smoke.php [--self-test] [--keep-temp]\n";
    echo "       [--moova-port=0] [--pos-port=0]\n";
    exit(0);
}

$runner = new MoovaReachabilitySmoke($options);
$summary = $runner->run();
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(moova_smoke_has_failures($summary['steps']) ? 1 : 0);

class MoovaReachabilitySmoke
{
    private array $options;
    private string $tmpRoot;
    private array $children = [];
    private ?array $moova = null;
    private ?array $pos = null;

    public function __construct(array $options)
    {
        $this->options = $options;
        $suffix = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $this->tmpRoot = sys_get_temp_dir() . '/posmain-moova-reachability-' . $suffix;
    }

    public function run(): array
    {
        mkdir($this->tmpRoot, 0777, true);
        $steps = [];

        try {
            $this->pos = $this->startPosServer((int) ($this->options['pos_port'] ?? 0));
            $this->moova = $this->startMoovaServer((int) ($this->options['moova_port'] ?? 0));

            $steps[] = $this->orderStep('online_order', 'new_order', true, 'applied');
            $steps[] = $this->orderStep('queued_new_order', 'new_order', true, 'applied');
            $steps[] = $this->orderStep('queued_edit_order', 'edit_order', true, 'applied');
            $steps[] = $this->orderStep('queued_cancel_order', 'cancel_order', true, 'applied');

            $posPort = (int) $this->pos['port'];
            $this->stopNamed('pos');
            $steps[] = $this->orderStep('pos_drop', 'new_order', false, 'pos_unreachable');
            $this->pos = $this->startPosServer($posPort);
            $steps[] = $this->orderStep('pos_recovery', 'new_order', true, 'applied');

            $moovaPort = (int) $this->moova['port'];
            $this->stopNamed('moova');
            $steps[] = $this->orderStep('moova_drop', 'new_order', false, 'moova_unreachable');
            $this->moova = $this->startMoovaServer($moovaPort);
            $steps[] = $this->orderStep('moova_recovery', 'new_order', true, 'applied');

            return [
                'ok' => !moova_smoke_has_failures($steps),
                'mode' => !empty($this->options['self_test']) ? 'self-test' : 'smoke',
                'mock_servers' => [
                    'moova' => $this->moova ? $this->moova['base_url'] : null,
                    'pos' => $this->pos ? $this->pos['base_url'] : null,
                ],
                'steps' => $steps,
                'temp_dir' => $this->tmpRoot,
            ];
        } finally {
            $this->stopAll();
            if (empty($this->options['keep_temp'])) {
                moova_smoke_remove_tree($this->tmpRoot);
            }
        }
    }

    private function startPosServer(int $port): array
    {
        $port = $port > 0 ? $port : moova_smoke_free_port();
        $router = $this->tmpRoot . '/pos-router-' . $port . '.php';
        file_put_contents($router, $this->posRouterSource());

        return $this->startServer('pos', $port, $router);
    }

    private function startMoovaServer(int $port): array
    {
        if (!$this->pos) {
            throw new RuntimeException('POS mock must start before Moova mock.');
        }

        $port = $port > 0 ? $port : moova_smoke_free_port();
        $router = $this->tmpRoot . '/moova-router-' . $port . '.php';
        file_put_contents($router, $this->moovaRouterSource($this->pos['base_url']));

        return $this->startServer('moova', $port, $router);
    }

    private function startServer(string $name, int $port, string $router): array
    {
        $log = $this->tmpRoot . '/' . $name . '-' . $port . '.log';
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->tmpRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start ' . $name . ' mock server.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = [
            'name' => $name,
            'port' => $port,
            'base_url' => 'http://127.0.0.1:' . $port,
            'process' => $process,
            'log' => $log,
        ];
        $this->waitForHealth($server);
        $this->children[$name] = $server;

        return $server;
    }

    private function waitForHealth(array $server): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            $response = moova_smoke_http('GET', $server['base_url'] . '/health');
            if (($response['http_status'] ?? 0) === 200) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Mock server did not become healthy: ' . $server['name']);
    }

    private function orderStep(string $name, string $eventType, bool $expectOk, string $expectStatus): array
    {
        if (!$this->moova) {
            $response = [
                'ok' => false,
                'http_status' => 0,
                'body' => [
                    'status' => 'moova_unreachable',
                    'code' => 'MOOVA_UNREACHABLE',
                ],
            ];
        } else {
            $payload = [
                'idempotencyKey' => 'smoke:' . $name . ':' . bin2hex(random_bytes(3)),
                'cofeOrderId' => 'smoke-order-' . $name,
                'eventType' => $eventType,
                'branchId' => 'mock-branch',
                'tableNumber' => '1',
                'items' => [
                    ['item_id' => 'TEMP-TEST', 'qty' => 1],
                ],
            ];
            $response = moova_smoke_http(
                'POST',
                $this->moova['base_url'] . '/bridge/order',
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                ['Content-Type: application/json']
            );
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $actualOk = !empty($response['ok']) && !empty($body['success']);
        $actualStatus = (string) ($body['status'] ?? $body['syncStatus'] ?? '');
        if ($actualStatus === '' && ($response['http_status'] ?? 0) === 0) {
            $actualStatus = 'moova_unreachable';
        }

        return [
            'name' => $name,
            'passed' => $actualOk === $expectOk && $actualStatus === $expectStatus,
            'expected' => [
                'ok' => $expectOk,
                'status' => $expectStatus,
            ],
            'actual' => [
                'ok' => $actualOk,
                'status' => $actualStatus,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'code' => $body['code'] ?? null,
                'message' => $body['message'] ?? ($response['error'] ?? null),
            ],
        ];
    }

    private function stopNamed(string $name): void
    {
        if (!isset($this->children[$name])) {
            return;
        }

        $this->stopServer($this->children[$name]);
        unset($this->children[$name]);
        if ($name === 'moova') {
            $this->moova = null;
        }
        if ($name === 'pos') {
            $this->pos = null;
        }
    }

    private function stopAll(): void
    {
        foreach (array_keys($this->children) as $name) {
            $this->stopNamed($name);
        }
    }

    private function stopServer(array $server): void
    {
        $process = $server['process'] ?? null;
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process);
        $deadline = microtime(true) + 2.0;
        do {
            $status = proc_get_status($process);
            if (empty($status['running'])) {
                proc_close($process);
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        proc_terminate($process, 9);
        proc_close($process);
        usleep(150000);
    }

    private function posRouterSource(): string
    {
        return <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/health') {
    mock_json(200, ['ok' => true, 'service' => 'pos']);
}
if ($path === '/ajax/moova_confirm_order.php') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    mock_json(200, [
        'success' => true,
        'applied' => true,
        'status' => 'applied',
        'deliveryPath' => 'widget',
        'applyPath' => 'direct_widget',
        'syncEventType' => 'new_order',
        'syncStatus' => 'applied',
        'providerStatus' => 'created',
        'providerOrderId' => 'mock-pos-' . substr((string) ($payload['idempotencyKey'] ?? 'order'), -8),
        'providerReferenceId' => (string) ($payload['idempotencyKey'] ?? ''),
    ]);
}
mock_json(404, ['success' => false, 'status' => 'not_found']);

function mock_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
PHP;
    }

    private function moovaRouterSource(string $posBaseUrl): string
    {
        $posBaseUrlLiteral = var_export($posBaseUrl, true);

        return <<<PHP
<?php
\$posBaseUrl = {$posBaseUrlLiteral};
\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (\$path === '/health') {
    mock_json(200, ['ok' => true, 'service' => 'moova']);
}
if (\$path === '/bridge/order') {
    \$payload = file_get_contents('php://input');
    \$posResponse = mock_http('POST', \$posBaseUrl . '/ajax/moova_confirm_order.php', \$payload, ['Content-Type: application/json']);
    if ((\$posResponse['http_status'] ?? 0) === 0) {
        mock_json(502, [
            'success' => false,
            'status' => 'pos_unreachable',
            'code' => 'POS_UNREACHABLE',
            'message' => 'POS mock is unreachable from Moova mock.',
            'retryable' => true,
        ]);
    }
    \$body = is_array(\$posResponse['body'] ?? null) ? \$posResponse['body'] : [];
    if ((\$posResponse['http_status'] ?? 0) >= 400 || empty(\$body['success'])) {
        mock_json(502, [
            'success' => false,
            'status' => 'pos_failed',
            'code' => \$body['code'] ?? 'POS_FAILED',
            'message' => \$body['message'] ?? 'POS mock returned an error.',
            'retryable' => true,
            'posResponse' => \$body,
        ]);
    }
    \$body['status'] = \$body['syncStatus'] ?? 'applied';
    mock_json(200, \$body);
}
mock_json(404, ['success' => false, 'status' => 'not_found']);

function mock_http(string \$method, string \$url, ?string \$body = null, array \$headers = []): array
{
    \$headerLines = [];
    foreach (\$headers as \$name => \$value) {
        \$headerLines[] = \$name . ': ' . \$value;
    }
    \$error = null;
    set_error_handler(static function (\$severity, \$message) use (&\$error): bool {
        \$error = \$message;
        return true;
    });
    \$raw = file_get_contents(\$url, false, stream_context_create([
        'http' => [
            'method' => \$method,
            'header' => implode("\\r\\n", \$headerLines),
            'content' => \$body ?? '',
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]));
    restore_error_handler();
    if (\$raw === false) {
        return ['ok' => false, 'http_status' => 0, 'error' => \$error];
    }
    \$status = 0;
    foreach (\$http_response_header ?? [] as \$line) {
        if (preg_match('/^HTTP\\/\\S+\\s+(\\d+)/', \$line, \$matches)) {
            \$status = (int) \$matches[1];
            break;
        }
    }
    \$decoded = json_decode(\$raw, true);
    return [
        'ok' => \$status >= 200 && \$status < 300,
        'http_status' => \$status,
        'body' => is_array(\$decoded) ? \$decoded : ['raw' => \$raw],
    ];
}

function mock_json(int \$status, array \$payload): void
{
    http_response_code(\$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(\$payload, JSON_UNESCAPED_SLASHES);
    exit;
}
PHP;
    }
}

function moova_smoke_parse_args(array $argv): array
{
    $options = [
        'moova_port' => 0,
        'pos_port' => 0,
        'self_test' => false,
        'keep_temp' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--self-test') {
            $options['self_test'] = true;
            continue;
        }
        if ($arg === '--keep-temp') {
            $options['keep_temp'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if (str_starts_with($arg, '--moova-port=')) {
            $options['moova_port'] = (int) substr($arg, strlen('--moova-port='));
            continue;
        }
        if (str_starts_with($arg, '--pos-port=')) {
            $options['pos_port'] = (int) substr($arg, strlen('--pos-port='));
            continue;
        }
        throw new InvalidArgumentException('Unknown option: ' . $arg);
    }

    return $options;
}

function moova_smoke_free_port(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        throw new RuntimeException('Could not allocate a local port: ' . $errstr);
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    $parts = explode(':', (string) $name);

    return (int) end($parts);
}

function moova_smoke_http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $error = null;
    set_error_handler(static function ($severity, $message) use (&$error): bool {
        $error = $message;
        return true;
    });
    $raw = file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]));
    restore_error_handler();

    if ($raw === false) {
        return [
            'ok' => false,
            'http_status' => 0,
            'body' => [
                'success' => false,
                'status' => 'moova_unreachable',
                'code' => 'MOOVA_UNREACHABLE',
                'message' => $error ?: 'Moova mock is unreachable.',
            ],
            'error' => $error,
        ];
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    $decoded = json_decode($raw, true);

    return [
        'ok' => $status >= 200 && $status < 300,
        'http_status' => $status,
        'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
    ];
}

function moova_smoke_has_failures(array $steps): bool
{
    foreach ($steps as $step) {
        if (empty($step['passed'])) {
            return true;
        }
    }

    return false;
}

function moova_smoke_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $fullPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($fullPath)) {
            moova_smoke_remove_tree($fullPath);
        } else {
            @unlink($fullPath);
        }
    }
    @rmdir($path);
}
