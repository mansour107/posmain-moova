<?php

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/PrinterTransportService.php';

class PrintBridgeClient
{
    private string $baseUrl;
    private string $secret;
    private $requester;

    public function __construct(?string $baseUrl = null, ?string $secret = null, ?callable $requester = null)
    {
        $config = posmain_app_config();
        $printing = is_array($config['printing'] ?? null) ? $config['printing'] : [];
        $this->baseUrl = rtrim(trim((string) ($baseUrl ?? ($printing['bridge_url'] ?? ''))), '/');
        $this->secret = trim((string) ($secret ?? ($printing['bridge_secret'] ?? '')));
        $this->requester = $requester;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->secret) >= 32;
    }

    public function health(): array
    {
        return $this->request('GET', '/v1/health');
    }

    /** @return array<int,array> */
    public function printers(): array
    {
        $response = $this->request('GET', '/v1/printers');
        return is_array($response['printers'] ?? null) ? array_values($response['printers']) : [];
    }

    public function printer(string $queue): ?array
    {
        foreach ($this->printers() as $printer) {
            if (hash_equals((string) ($printer['queue'] ?? ''), $queue)) {
                return $printer;
            }
        }
        return null;
    }

    public function checkNetwork(string $host, int $port): array
    {
        return $this->request('POST', '/v1/network-health', ['host' => $host, 'port' => $port]);
    }

    public function print(array $job, array $printer, array $rendered): array
    {
        $config = is_array($printer['config'] ?? null) ? $printer['config'] : [];
        $transport = strtolower(trim((string) ($printer['connection_type'] ?? '')));
        $queue = trim((string) ($config['queue_name'] ?? ''));
        if ($transport === 'usb' && $queue === '') {
            throw new PrintTransportException('PRINT_CABLE_QUEUE_REQUIRED', false);
        }
        if (!in_array($transport, ['usb', 'network'], true)) {
            throw new PrintTransportException('SILENT_PRINT_TRANSPORT_UNSUPPORTED', false);
        }
        $bytes = (string) ($rendered['bytes'] ?? '');
        $deliveryKey = trim((string) ($job['idempotency_key'] ?? ''));
        if ($deliveryKey === '') {
            $deliveryKey = 'job:' . (int) ($job['id'] ?? 0) . ':printer:' . (int) ($printer['id'] ?? 0);
        }

        return $this->request('POST', '/v1/print', [
            'delivery_key' => $deliveryKey,
            'transport' => $transport === 'usb' ? 'cable' : 'network',
            'queue' => $queue,
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 0),
            'job_id' => (int) ($job['id'] ?? 0),
            'printer_id' => (int) ($printer['id'] ?? 0),
            'payload_sha256' => hash('sha256', $bytes),
            'payload_base64' => base64_encode($bytes),
        ]);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            throw new PrintTransportException('PRINT_BRIDGE_NOT_CONFIGURED', false);
        }
        $body = $payload === null
            ? ''
            : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new PrintTransportException('PRINT_BRIDGE_PAYLOAD_INVALID', false);
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac(
            'sha256',
            $timestamp . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $body),
            $this->secret
        );
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
            'X-Posmain-Timestamp' => $timestamp,
            'X-Posmain-Nonce' => $nonce,
            'X-Posmain-Signature' => $signature,
        ];

        if (is_callable($this->requester)) {
            $response = call_user_func($this->requester, $method, $path, $headers, $body);
            if (!is_array($response)) {
                throw new PrintTransportException('PRINT_BRIDGE_RESPONSE_INVALID', $path !== '/v1/print');
            }
            return $this->normalizeResponse($response, $path);
        }

        return $this->nativeRequest($method, $path, $headers, $body);
    }

    private function nativeRequest(string $method, string $path, array $headers, string $body): array
    {
        $url = parse_url($this->baseUrl);
        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        $host = trim((string) ($url['host'] ?? ''));
        $port = (int) ($url['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $port < 1 || $port > 65535) {
            throw new PrintTransportException('PRINT_BRIDGE_NOT_CONFIGURED', false);
        }
        $prefix = rtrim((string) ($url['path'] ?? ''), '/');
        $requestPath = ($prefix !== '' ? $prefix : '') . $path;
        $config = posmain_app_config();
        $timeoutMs = (int) ($config['printing']['bridge_timeout_ms'] ?? 3000);
        $timeout = max(0.5, min(15, $timeoutMs / 1000));
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new PrintTransportException('PRINT_BRIDGE_UNAVAILABLE', true);
        }
        stream_set_timeout($socket, (int) ceil($timeout));

        $headerLines = [
            $method . ' ' . $requestPath . ' HTTP/1.1',
            'Host: ' . $host . ':' . $port,
            'Connection: close',
            'Content-Length: ' . strlen($body),
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $request = implode("\r\n", $headerLines) . "\r\n\r\n" . $body;
        $written = 0;
        try {
            while ($written < strlen($request)) {
                $chunk = @fwrite($socket, substr($request, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new PrintTransportException(
                        $written > 0 && $path === '/v1/print'
                            ? 'PRINT_BRIDGE_DELIVERY_UNCERTAIN'
                            : 'PRINT_BRIDGE_UNAVAILABLE',
                        $written === 0
                    );
                }
                $written += $chunk;
            }
            @fflush($socket);
            $raw = stream_get_contents($socket);
            $metadata = stream_get_meta_data($socket);
        } finally {
            fclose($socket);
        }
        if (!is_string($raw) || $raw === '' || !empty($metadata['timed_out'])) {
            throw new PrintTransportException(
                $path === '/v1/print' ? 'PRINT_BRIDGE_DELIVERY_UNCERTAIN' : 'PRINT_BRIDGE_RESPONSE_INVALID',
                $path !== '/v1/print'
            );
        }
        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $head, $match) !== 1) {
            throw new PrintTransportException('PRINT_BRIDGE_RESPONSE_INVALID', $path !== '/v1/print');
        }
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new PrintTransportException('PRINT_BRIDGE_RESPONSE_INVALID', $path !== '/v1/print');
        }
        $decoded['_http_status'] = (int) $match[1];
        return $this->normalizeResponse($decoded, $path);
    }

    private function normalizeResponse(array $response, string $path): array
    {
        $status = (int) ($response['_http_status'] ?? ($response['ok'] ?? false ? 200 : 422));
        if ($status >= 200 && $status < 300 && ($response['ok'] ?? true) === true) {
            unset($response['_http_status']);
            return $response;
        }
        $code = strtoupper(trim((string) ($response['code'] ?? 'PRINT_BRIDGE_RESPONSE_INVALID')));
        $retrySafe = in_array($code, [
            'PRINT_BRIDGE_UNAVAILABLE',
            'PRINT_BRIDGE_QUEUE_OFFLINE',
            'PRINT_BRIDGE_SUBMIT_FAILED',
            'PRINT_NETWORK_CONNECT_FAILED',
        ], true);
        if ($path === '/v1/print' && in_array($code, [
            'PRINT_BRIDGE_DELIVERY_UNCERTAIN',
            'PRINT_BRIDGE_IDEMPOTENCY_CONFLICT',
            'PRINT_NETWORK_DELIVERY_UNCERTAIN',
        ], true)) {
            $retrySafe = false;
        }
        throw new PrintTransportException($code, $retrySafe);
    }
}
