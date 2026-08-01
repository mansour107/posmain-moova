<?php

class LocalNetworkPrinterException extends RuntimeException
{
    private bool $retrySafe;
    public function __construct(string $code, bool $retrySafe) { parent::__construct($code); $this->retrySafe = $retrySafe; }
    public function isRetrySafe(): bool { return $this->retrySafe; }
}

class LocalNetworkPrinterService
{
    private $connector;
    public function __construct(?callable $connector = null) { $this->connector = $connector; }

    public function check(string $host, int $port): array
    {
        [$host, $port] = $this->endpoint($host, $port);
        $socket = $this->connect($host, $port, 1.5);
        $connected = is_resource($socket);
        if ($connected) fclose($socket);
        return ['connected' => $connected, 'host' => $host, 'port' => $port];
    }

    public function submit(string $host, int $port, string $bytes): array
    {
        [$host, $port] = $this->endpoint($host, $port);
        if ($bytes === '' || strlen($bytes) > 4 * 1024 * 1024) throw new LocalNetworkPrinterException('PRINT_BRIDGE_PAYLOAD_INVALID', false);
        $socket = $this->connect($host, $port, 3.0);
        if (!is_resource($socket)) throw new LocalNetworkPrinterException('PRINT_NETWORK_CONNECT_FAILED', true);
        stream_set_timeout($socket, 4);
        $written = 0;
        try {
            while ($written < strlen($bytes)) {
                $chunk = @fwrite($socket, substr($bytes, $written));
                if ($chunk === false || $chunk === 0) throw new LocalNetworkPrinterException('PRINT_NETWORK_DELIVERY_UNCERTAIN', false);
                $written += $chunk;
            }
            if (!@fflush($socket)) throw new LocalNetworkPrinterException('PRINT_NETWORK_DELIVERY_UNCERTAIN', false);
        } finally {
            fclose($socket);
        }
        return ['accepted' => true, 'host' => $host, 'port' => $port, 'bytes' => $written, 'spooler' => 'raw-network'];
    }

    private function connect(string $host, int $port, float $timeout)
    {
        if (is_callable($this->connector)) return call_user_func($this->connector, $host, $port, $timeout);
        $errno = 0; $error = '';
        return @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
    }

    private function endpoint(string $host, int $port): array
    {
        $host = trim($host);
        if ($host === '' || strlen($host) > 253 || preg_match('/[\\x00-\\x20\/\\\\]/', $host) || $port < 1 || $port > 65535) {
            throw new LocalNetworkPrinterException('PRINT_NETWORK_CONFIG_INVALID', false);
        }
        return [$host, $port];
    }
}
