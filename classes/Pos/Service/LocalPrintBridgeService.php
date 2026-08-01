<?php

require_once __DIR__ . '/LocalPrinterQueueService.php';
require_once __DIR__ . '/LocalNetworkPrinterService.php';

class LocalPrintBridgeService
{
    private LocalPrinterQueueService $queues;
    private LocalNetworkPrinterService $network;
    private string $stateDirectory;

    public function __construct(?LocalPrinterQueueService $queues = null, ?string $stateDirectory = null, ?LocalNetworkPrinterService $network = null)
    {
        $this->queues = $queues ?: new LocalPrinterQueueService();
        $this->network = $network ?: new LocalNetworkPrinterService();
        $this->stateDirectory = rtrim(
            trim((string) ($stateDirectory ?: getenv('POSMAIN_PRINT_BRIDGE_STATE_DIR') ?: '')),
            DIRECTORY_SEPARATOR
        );
        if ($this->stateDirectory === '') {
            $this->stateDirectory = sys_get_temp_dir() . '/posmain-print-bridge';
        }
    }

    public function health(): array
    {
        $printers = $this->queues->printers();
        return [
            'ok' => true,
            'service' => 'POSMAIN local print service',
            'version' => 1,
            'printer_count' => count($printers),
            'connected_count' => count(array_filter(
                $printers,
                static fn(array $printer): bool => !empty($printer['connected'])
            )),
            'checked_at' => gmdate('c'),
        ];
    }

    public function printers(): array
    {
        return [
            'ok' => true,
            'printers' => $this->queues->printers(),
            'checked_at' => gmdate('c'),
        ];
    }

    public function checkNetwork(array $request): array
    {
        return ['ok' => true] + $this->network->check((string) ($request['host'] ?? ''), (int) ($request['port'] ?? 0));
    }

    public function print(array $request): array
    {
        $deliveryKey = trim((string) ($request['delivery_key'] ?? ''));
        $queue = trim((string) ($request['queue'] ?? ''));
        $transport = strtolower(trim((string) ($request['transport'] ?? '')));
        $expectedHash = strtolower(trim((string) ($request['payload_sha256'] ?? '')));
        $encoded = (string) ($request['payload_base64'] ?? '');
        if (
            $deliveryKey === ''
            || strlen($deliveryKey) > 191
            || preg_match('/^[a-zA-Z0-9:._-]+$/', $deliveryKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
        ) {
            throw new RuntimeException('PRINT_BRIDGE_PAYLOAD_INVALID');
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
            throw new RuntimeException('PRINT_BRIDGE_PAYLOAD_INVALID');
        }
        $actualHash = hash('sha256', $bytes);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('PRINT_BRIDGE_PAYLOAD_INVALID');
        }

        $this->ensureStateDirectory();
        $stem = hash('sha256', $deliveryKey);
        $statePath = $this->stateDirectory . DIRECTORY_SEPARATOR . $stem . '.json';
        $lockPath = $this->stateDirectory . DIRECTORY_SEPARATOR . $stem . '.lock';
        $lock = fopen($lockPath, 'c+');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('PRINT_BRIDGE_UNAVAILABLE');
        }
        @chmod($lockPath, 0600);

        try {
            $existing = $this->readState($statePath);
            if ($existing !== null) {
                if (!hash_equals((string) ($existing['payload_sha256'] ?? ''), $actualHash)) {
                    throw new RuntimeException('PRINT_BRIDGE_IDEMPOTENCY_CONFLICT');
                }
                if (($existing['status'] ?? '') === 'accepted') {
                    $receipt = is_array($existing['receipt'] ?? null) ? $existing['receipt'] : [];
                    $receipt['ok'] = true;
                    $receipt['replayed'] = true;
                    return $receipt;
                }
                // A prior process may have reached the OS spooler before it
                // stopped. Never resubmit automatically in this state.
                throw new RuntimeException('PRINT_BRIDGE_DELIVERY_UNCERTAIN');
            }

            $this->writeState($statePath, [
                'status' => 'submitting',
                'delivery_key' => $deliveryKey,
                'queue' => $queue,
                'payload_sha256' => $actualHash,
                'started_at' => gmdate('c'),
            ]);
            try {
                if ($transport === 'cable') {
                    $spool = $this->queues->submit($queue, $bytes);
                } elseif ($transport === 'network') {
                    $spool = $this->network->submit((string) ($request['host'] ?? ''), (int) ($request['port'] ?? 0), $bytes);
                } else {
                    throw new LocalPrinterQueueException('SILENT_PRINT_TRANSPORT_UNSUPPORTED', false);
                }
            } catch (Throwable $exception) {
                $retrySafe = ($exception instanceof LocalPrinterQueueException && $exception->isRetrySafe())
                    || ($exception instanceof LocalNetworkPrinterException && $exception->isRetrySafe());
                if ($retrySafe) {
                    @unlink($statePath);
                }
                throw $exception;
            }
            $receipt = [
                'ok' => true,
                'accepted' => true,
                'transport' => $transport,
                'queue' => $queue,
                'job_id' => (int) ($request['job_id'] ?? 0),
                'printer_id' => (int) ($request['printer_id'] ?? 0),
                'bytes' => strlen($bytes),
                'sha256' => $actualHash,
                'spool_job_id' => (string) ($spool['spool_job_id'] ?? ''),
                'spooler' => (string) ($spool['spooler'] ?? ''),
                'accepted_at' => gmdate('c'),
                'replayed' => false,
            ];
            $this->writeState($statePath, [
                'status' => 'accepted',
                'delivery_key' => $deliveryKey,
                'queue' => $queue,
                'payload_sha256' => $actualHash,
                'receipt' => $receipt,
            ]);
            return $receipt;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureStateDirectory(): void
    {
        if (
            !is_dir($this->stateDirectory)
            && !mkdir($this->stateDirectory, 0700, true)
            && !is_dir($this->stateDirectory)
        ) {
            throw new RuntimeException('PRINT_BRIDGE_UNAVAILABLE');
        }
        @chmod($this->stateDirectory, 0700);
    }

    private function readState(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PRINT_BRIDGE_DELIVERY_UNCERTAIN');
        }
        return $decoded;
    }

    private function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('PRINT_BRIDGE_UNAVAILABLE');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('PRINT_BRIDGE_UNAVAILABLE');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('PRINT_BRIDGE_UNAVAILABLE');
        }
    }
}
