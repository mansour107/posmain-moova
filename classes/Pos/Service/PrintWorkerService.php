<?php

require_once __DIR__ . '/PrintJobService.php';
require_once __DIR__ . '/EscPosPrintRenderer.php';
require_once __DIR__ . '/PrinterTransportService.php';
require_once __DIR__ . '/../../../config/app_config.php';

class PrintWorkerService
{
    private PrintJobService $jobs;
    private EscPosPrintRenderer $renderer;
    private PrinterTransportService $transport;

    public function __construct(
        ?PrintJobService $jobs = null,
        ?EscPosPrintRenderer $renderer = null,
        ?PrinterTransportService $transport = null
    ) {
        $this->jobs = $jobs ?: new PrintJobService();
        $this->renderer = $renderer ?: new EscPosPrintRenderer();
        $this->transport = $transport ?: new PrinterTransportService();
    }

    public function processJob(mysqli $conn, int $jobId, ?string $workerId = null): array
    {
        $config = posmain_app_config();
        $workerId = $workerId ?: $this->workerId();
        $lockSeconds = (int) ($config['printing']['worker_lock_seconds'] ?? 45);
        $retryDelay = (int) ($config['printing']['retry_delay_seconds'] ?? 15);
        $job = $this->jobs->claim($conn, $jobId, $workerId, $lockSeconds);

        try {
            if ($job['printer_id'] === null) {
                throw new PrintTransportException('PRINT_JOB_PRINTER_REQUIRED', false);
            }
            $printer = $this->jobs->getPrinter($conn, $job['printer_id'], true);
            $rendered = $this->renderer->render($job, $printer);
            $receipt = $this->transport->send($job, $printer, $rendered);
            return $this->jobs->completeClaim($conn, $job['id'], $workerId, $receipt);
        } catch (Throwable $exception) {
            $error = $exception->getMessage() !== '' ? $exception->getMessage() : 'PRINT_DELIVERY_FAILED';
            if ($exception instanceof PrintTransportException && $exception->isRetrySafe()) {
                return $this->jobs->failClaim($conn, $job['id'], $workerId, $error, $retryDelay);
            }
            return $this->jobs->failClaimWithoutRetry($conn, $job['id'], $workerId, $error);
        }
    }

    public function processNext(mysqli $conn, ?string $workerId = null): ?array
    {
        $config = posmain_app_config();
        $workerId = $workerId ?: $this->workerId();
        $job = $this->jobs->claimNext(
            $conn,
            $workerId,
            (int) ($config['printing']['worker_lock_seconds'] ?? 45)
        );
        if ($job === null) {
            return null;
        }

        try {
            if ($job['printer_id'] === null) {
                throw new PrintTransportException('PRINT_JOB_PRINTER_REQUIRED', false);
            }
            $printer = $this->jobs->getPrinter($conn, $job['printer_id'], true);
            $rendered = $this->renderer->render($job, $printer);
            $receipt = $this->transport->send($job, $printer, $rendered);
            return $this->jobs->completeClaim($conn, $job['id'], $workerId, $receipt);
        } catch (Throwable $exception) {
            $error = $exception->getMessage() !== '' ? $exception->getMessage() : 'PRINT_DELIVERY_FAILED';
            if ($exception instanceof PrintTransportException && $exception->isRetrySafe()) {
                return $this->jobs->failClaim(
                    $conn,
                    $job['id'],
                    $workerId,
                    $error,
                    (int) ($config['printing']['retry_delay_seconds'] ?? 15)
                );
            }
            return $this->jobs->failClaimWithoutRetry($conn, $job['id'], $workerId, $error);
        }
    }

    private function workerId(): string
    {
        $host = preg_replace('/[^a-zA-Z0-9_.-]/', '-', (string) gethostname());
        return substr(($host ?: 'posmain') . '-' . getmypid() . '-' . bin2hex(random_bytes(4)), 0, 64);
    }
}
