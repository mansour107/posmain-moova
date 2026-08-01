<?php

require_once __DIR__ . '/LocalPrintBridgeService.php';

class PrintBridgeHttpHandler
{
    private LocalPrintBridgeService $bridge;
    private string $secret;
    private array $nonces = [];

    public function __construct(LocalPrintBridgeService $bridge, string $secret)
    {
        $secret = trim($secret);
        if (strlen($secret) < 32) {
            throw new RuntimeException('PRINT_BRIDGE_SECRET_REQUIRED');
        }
        $this->bridge = $bridge;
        $this->secret = $secret;
    }

    /** @return array{status:int,body:array} */
    public function handle(string $method, string $path, array $headers, string $body): array
    {
        try {
            $method = strtoupper(trim($method));
            $path = '/' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
            $this->authenticate($method, $path, $headers, $body);

            if ($method === 'GET' && $path === '/v1/health') {
                return ['status' => 200, 'body' => $this->bridge->health()];
            }
            if ($method === 'GET' && $path === '/v1/printers') {
                return ['status' => 200, 'body' => $this->bridge->printers()];
            }
            if ($method === 'POST' && $path === '/v1/network-health') {
                $request = json_decode($body, true);
                if (!is_array($request)) {
                    throw new InvalidArgumentException('PRINT_BRIDGE_PAYLOAD_INVALID');
                }
                return ['status' => 200, 'body' => $this->bridge->checkNetwork($request)];
            }
            if ($method === 'POST' && $path === '/v1/print') {
                $request = json_decode($body, true);
                if (!is_array($request)) {
                    throw new InvalidArgumentException('PRINT_BRIDGE_PAYLOAD_INVALID');
                }
                return ['status' => 200, 'body' => $this->bridge->print($request)];
            }
            return [
                'status' => 404,
                'body' => ['ok' => false, 'code' => 'PRINT_BRIDGE_ROUTE_NOT_FOUND'],
            ];
        } catch (Throwable $exception) {
            $code = $this->code((string) $exception->getMessage());
            $status = $code === 'PRINT_BRIDGE_AUTH_FAILED'
                ? 401
                : (in_array($code, ['PRINT_BRIDGE_UNAVAILABLE'], true) ? 503 : 422);
            error_log('POSMAIN_PRINT_BRIDGE_ERROR ' . json_encode([
                'code' => $code,
                'exception' => get_class($exception),
            ], JSON_UNESCAPED_SLASHES));
            return ['status' => $status, 'body' => ['ok' => false, 'code' => $code]];
        }
    }

    private function authenticate(string $method, string $path, array $headers, string $body): void
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = trim((string) $value);
        }
        $timestamp = $normalized['x-posmain-timestamp'] ?? '';
        $nonce = $normalized['x-posmain-nonce'] ?? '';
        $signature = strtolower($normalized['x-posmain-signature'] ?? '');
        if (
            !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 30
            || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
        ) {
            throw new RuntimeException('PRINT_BRIDGE_AUTH_FAILED');
        }
        $this->expireNonces();
        if (isset($this->nonces[$nonce])) {
            throw new RuntimeException('PRINT_BRIDGE_AUTH_FAILED');
        }
        $expected = hash_hmac(
            'sha256',
            $timestamp . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $body),
            $this->secret
        );
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('PRINT_BRIDGE_AUTH_FAILED');
        }
        $this->nonces[$nonce] = time();
    }

    private function expireNonces(): void
    {
        $cutoff = time() - 60;
        foreach ($this->nonces as $nonce => $seenAt) {
            if ($seenAt < $cutoff) {
                unset($this->nonces[$nonce]);
            }
        }
    }

    private function code(string $diagnostic): string
    {
        $code = strtoupper(trim(strtok($diagnostic, ':') ?: ''));
        return preg_match('/^[A-Z][A-Z0-9_]{2,120}$/', $code) === 1
            ? $code
            : 'PRINT_BRIDGE_UNAVAILABLE';
    }
}
