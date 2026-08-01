#!/usr/bin/env php
<?php

require_once __DIR__ . '/../classes/Pos/Service/LocalPrinterQueueService.php';
require_once __DIR__ . '/../classes/Pos/Service/LocalPrintBridgeService.php';
require_once __DIR__ . '/../classes/Pos/Service/PrintBridgeHttpHandler.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['listen:', 'secret-file:', 'state-dir:', 'check', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/print_bridge.php [--listen=127.0.0.1:17981] [--secret-file=/protected/printing.secret] [--state-dir=/protected/print-state] [--check]\n");
    exit(0);
}

$listen = trim((string) ($options['listen'] ?? getenv('POSMAIN_PRINT_BRIDGE_LISTEN') ?: '127.0.0.1:17981'));
$stateDirectory = trim((string) ($options['state-dir'] ?? getenv('POSMAIN_PRINT_BRIDGE_STATE_DIR') ?: ''));
$secretFile = trim((string) ($options['secret-file'] ?? getenv('POSMAIN_PRINT_BRIDGE_SECRET_FILE') ?: ''));
$secret = trim((string) (getenv('POSMAIN_PRINT_BRIDGE_SECRET') ?: ''));
if ($secretFile !== '') {
    if (!is_file($secretFile) || !is_readable($secretFile)) {
        fwrite(STDERR, "ملف حماية خدمة الطباعة غير موجود أو لا يمكن قراءته.\n");
        exit(2);
    }
    $secret = trim((string) file_get_contents($secretFile));
}
if (strlen($secret) < 32) {
    fwrite(STDERR, "إعداد حماية خدمة الطباعة غير مكتمل. أعد تشغيل أداة التثبيت.\n");
    exit(2);
}
if (preg_match('/^(127\.0\.0\.1|0\.0\.0\.0|\[::1\]):([0-9]{1,5})$/', $listen, $match) !== 1) {
    fwrite(STDERR, "عنوان تشغيل خدمة الطباعة المحلية غير صالح.\n");
    exit(2);
}
$port = (int) $match[2];
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "منفذ خدمة الطباعة المحلية غير صالح.\n");
    exit(2);
}

$service = new LocalPrintBridgeService(null, $stateDirectory !== '' ? $stateDirectory : null);
if (isset($options['check'])) {
    fwrite(STDOUT, json_encode($service->health(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}
$handler = new PrintBridgeHttpHandler($service, $secret);
$server = @stream_socket_server('tcp://' . $listen, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
if (!is_resource($server)) {
    fwrite(STDERR, "تعذر تشغيل خدمة الطباعة المحلية. قد تكون الخدمة تعمل بالفعل أو المنفذ مستخدماً.\n");
    exit(2);
}
stream_set_blocking($server, true);
$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}
fwrite(STDOUT, json_encode([
    'ok' => true,
    'service' => 'POSMAIN local print service',
    'listen' => $listen,
    'pid' => getmypid(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

while ($running) {
    $client = @stream_socket_accept($server, 1);
    if (!is_resource($client)) {
        continue;
    }
    try {
        stream_set_timeout($client, 5);
        $request = posmainPrintBridgeReadRequest($client);
        $response = $handler->handle(
            $request['method'],
            $request['path'],
            $request['headers'],
            $request['body']
        );
        posmainPrintBridgeWriteResponse($client, $response['status'], $response['body']);
    } catch (Throwable $exception) {
        error_log('POSMAIN_PRINT_BRIDGE_HTTP_ERROR ' . get_class($exception));
        posmainPrintBridgeWriteResponse($client, 400, [
            'ok' => false,
            'code' => 'PRINT_BRIDGE_REQUEST_INVALID',
        ]);
    } finally {
        fclose($client);
    }
}
fclose($server);

function posmainPrintBridgeReadRequest($client): array
{
    $raw = '';
    while (strpos($raw, "\r\n\r\n") === false) {
        $chunk = fread($client, 4096);
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
        }
        $raw .= $chunk;
        if (strlen($raw) > 32768) {
            throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
        }
    }
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    if (preg_match('/^(GET|POST)\s+([^\s]+)\s+HTTP\/1\.[01]$/', (string) $requestLine, $match) !== 1) {
        throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
    }
    $headers = [];
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) {
            throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[trim($name)] = trim($value);
    }
    $length = (int) ($headers['Content-Length'] ?? $headers['content-length'] ?? 0);
    if ($length < 0 || $length > 6 * 1024 * 1024) {
        throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
    }
    while (strlen($body) < $length) {
        $chunk = fread($client, min(65536, $length - strlen($body)));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('PRINT_BRIDGE_REQUEST_INVALID');
        }
        $body .= $chunk;
    }
    return [
        'method' => $match[1],
        'path' => $match[2],
        'headers' => $headers,
        'body' => substr($body, 0, $length),
    ];
}

function posmainPrintBridgeWriteResponse($client, int $status, array $body): void
{
    $phrases = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 422 => 'Unprocessable Entity', 503 => 'Service Unavailable'];
    $status = isset($phrases[$status]) ? $status : 500;
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{"ok":false,"code":"PRINT_BRIDGE_RESPONSE_INVALID"}';
        $status = 500;
    }
    $response = 'HTTP/1.1 ' . $status . ' ' . ($phrases[$status] ?? 'Internal Server Error') . "\r\n"
        . "Content-Type: application/json; charset=utf-8\r\n"
        . 'Content-Length: ' . strlen($json) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $json;
    fwrite($client, $response);
}
