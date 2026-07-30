<?php

require_once __DIR__ . '/../../../config/app_config.php';

class PrintTransportException extends RuntimeException
{
    private bool $retrySafe;

    public function __construct(string $message, bool $retrySafe)
    {
        parent::__construct($message);
        $this->retrySafe = $retrySafe;
    }

    public function isRetrySafe(): bool
    {
        return $this->retrySafe;
    }
}

class PrinterTransportService
{
    public function send(array $job, array $printer, array $rendered): array
    {
        $connectionType = strtolower(trim((string) ($printer['connection_type'] ?? '')));
        if ($connectionType === 'file') {
            return $this->sendToSimulator($job, $printer, $rendered);
        }
        if ($connectionType === 'network') {
            return $this->sendToNetwork($job, $printer, $rendered);
        }
        if ($connectionType === 'browser') {
            throw new PrintTransportException('SILENT_PRINT_BROWSER_PRINTER_UNSUPPORTED', false);
        }

        throw new PrintTransportException('SILENT_PRINT_TRANSPORT_UNSUPPORTED', false);
    }

    private function sendToSimulator(array $job, array $printer, array $rendered): array
    {
        $appConfig = posmain_app_config();
        $base = trim((string) ($appConfig['printing']['simulator_directory'] ?? ''));
        if ($base === '') {
            throw new PrintTransportException('PRINT_SIMULATOR_DIRECTORY_REQUIRED', false);
        }
        $key = preg_replace(
            '/[^a-zA-Z0-9_-]+/',
            '-',
            trim((string) ($printer['config']['simulator_key'] ?? ''))
        );
        $key = trim((string) $key, '-');
        if ($key === '') {
            throw new PrintTransportException('PRINT_SIMULATOR_KEY_REQUIRED', false);
        }

        $directory = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key;
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new PrintTransportException('PRINT_SIMULATOR_DIRECTORY_CREATE_FAILED', true);
        }

        $jobId = (int) ($job['id'] ?? 0);
        $printerId = (int) ($printer['id'] ?? 0);
        if ($jobId < 1 || $printerId < 1) {
            throw new PrintTransportException('PRINT_DELIVERY_ID_REQUIRED', false);
        }
        $stem = sprintf('job-%010d-printer-%06d', $jobId, $printerId);
        $textPath = $directory . DIRECTORY_SEPARATOR . $stem . '.txt';
        $binaryPath = $directory . DIRECTORY_SEPARATOR . $stem . '.bin';
        $receiptPath = $directory . DIRECTORY_SEPARATOR . $stem . '.json';
        $bytes = (string) ($rendered['bytes'] ?? '');
        $text = (string) ($rendered['text'] ?? '');
        $hash = hash('sha256', $bytes);

        if (is_file($receiptPath)) {
            $existing = json_decode((string) file_get_contents($receiptPath), true);
            if (is_array($existing) && hash_equals((string) ($existing['sha256'] ?? ''), $hash)) {
                $existing['replayed'] = true;
                return $existing;
            }
            throw new PrintTransportException('PRINT_SIMULATOR_IDEMPOTENCY_CONFLICT', false);
        }

        $this->atomicWrite($binaryPath, $bytes);
        $this->atomicWrite($textPath, $text);
        $receipt = [
            'transport' => 'simulator',
            'accepted' => true,
            'job_id' => $jobId,
            'printer_id' => $printerId,
            'simulator_key' => $key,
            'bytes' => strlen($bytes),
            'sha256' => $hash,
            'text_path' => $textPath,
            'binary_path' => $binaryPath,
            'accepted_at' => gmdate('c'),
            'replayed' => false,
        ];
        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new PrintTransportException('PRINT_SIMULATOR_RECEIPT_ENCODE_FAILED', false);
        }
        $this->atomicWrite($receiptPath, $json . "\n");

        return $receipt;
    }

    private function sendToNetwork(array $job, array $printer, array $rendered): array
    {
        $config = is_array($printer['config'] ?? null) ? $printer['config'] : [];
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 9100);
        if (
            $host === ''
            || strlen($host) > 253
            || preg_match('/[\\x00-\\x20\\/\\\\]/', $host)
            || $port < 1
            || $port > 65535
        ) {
            throw new PrintTransportException('PRINT_NETWORK_CONFIG_INVALID', false);
        }

        $appConfig = posmain_app_config();
        $timeoutMs = (int) ($appConfig['printing']['network_timeout_ms'] ?? 3000);
        $timeout = max(0.5, min(15, $timeoutMs / 1000));
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new PrintTransportException('PRINT_NETWORK_CONNECT_FAILED', true);
        }

        stream_set_timeout($socket, (int) ceil($timeout));
        $bytes = (string) ($rendered['bytes'] ?? '');
        $length = strlen($bytes);
        $written = 0;
        try {
            while ($written < $length) {
                $chunk = @fwrite($socket, substr($bytes, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new PrintTransportException('PRINT_NETWORK_DELIVERY_UNCERTAIN', false);
                }
                $written += $chunk;
            }
            @fflush($socket);
        } finally {
            fclose($socket);
        }

        return [
            'transport' => 'network',
            'accepted' => true,
            'job_id' => (int) ($job['id'] ?? 0),
            'printer_id' => (int) ($printer['id'] ?? 0),
            'host' => $host,
            'port' => $port,
            'bytes' => $written,
            'sha256' => hash('sha256', $bytes),
            'accepted_at' => gmdate('c'),
        ];
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new PrintTransportException('PRINT_SIMULATOR_WRITE_FAILED', true);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new PrintTransportException('PRINT_SIMULATOR_WRITE_FAILED', true);
        }
    }
}
