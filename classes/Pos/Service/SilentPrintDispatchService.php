<?php

require_once __DIR__ . '/OrderPrintPayloadService.php';
require_once __DIR__ . '/PrintJobService.php';
require_once __DIR__ . '/PrinterRoutingService.php';
require_once __DIR__ . '/PrintWorkerService.php';

class SilentPrintDispatchService
{
    private OrderPrintPayloadService $orderPayloads;
    private PrintJobService $jobs;
    private PrinterRoutingService $routing;
    private PrintWorkerService $worker;

    public function __construct(
        ?OrderPrintPayloadService $orderPayloads = null,
        ?PrintJobService $jobs = null,
        ?PrinterRoutingService $routing = null,
        ?PrintWorkerService $worker = null
    ) {
        $this->orderPayloads = $orderPayloads ?: new OrderPrintPayloadService();
        $this->jobs = $jobs ?: new PrintJobService();
        $this->routing = $routing ?: new PrinterRoutingService($this->jobs);
        $this->worker = $worker ?: new PrintWorkerService($this->jobs);
    }

    public function dispatchOrder(
        mysqli $conn,
        string $jobType,
        int $orderId,
        string $requestKey,
        array $scope,
        ?int $createdBy = null
    ): array {
        $jobType = strtolower(trim($jobType));
        if ($jobType === 'receipt') {
            $payload = $this->orderPayloads->buildReceiptPayload($conn, $orderId);
        } elseif (in_array($jobType, ['kot', 'kitchen'], true)) {
            $jobType = 'kot';
            $payload = $this->orderPayloads->buildKotPayloadByOrderId($conn, $orderId);
        } else {
            throw new InvalidArgumentException('PRINT_ORDER_JOB_TYPE_INVALID');
        }

        return $this->dispatchPayload(
            $conn,
            $jobType,
            $payload,
            $requestKey,
            $scope,
            $createdBy,
            $orderId
        );
    }

    public function dispatchDocument(
        mysqli $conn,
        string $jobType,
        string $title,
        string $contentText,
        string $requestKey,
        array $scope,
        ?int $createdBy = null
    ): array {
        $jobType = strtolower(trim($jobType));
        if (!in_array($jobType, ['z_report', 'x_report', 'report', 'label', 'document'], true)) {
            throw new InvalidArgumentException('PRINT_DOCUMENT_JOB_TYPE_INVALID');
        }
        $title = $this->boundedText($title, 160, 'PRINT_DOCUMENT_TITLE_REQUIRED');
        $contentText = $this->boundedText($contentText, 250000, 'PRINT_DOCUMENT_CONTENT_REQUIRED');

        return $this->dispatchPayload(
            $conn,
            $jobType,
            ['title' => $title, 'content_text' => $contentText],
            $requestKey,
            $scope,
            $createdBy,
            null
        );
    }

    public function testPrinter(
        mysqli $conn,
        int $printerId,
        string $requestKey,
        array $scope,
        ?int $createdBy = null
    ): array {
        $printer = $this->jobs->getPrinterInScope($conn, $printerId, $scope, true);
        $payload = [
            'title' => 'POSMAIN printer test',
            'content_text' => implode("\n", [
                'Printer: ' . $printer['name'],
                'Transport: ' . $printer['connection_type'],
                'Arabic sample: اختبار الطابعة',
                'Timestamp: ' . gmdate('c'),
            ]),
        ];
        $job = $this->jobs->enqueue($conn, [
            'job_type' => 'document',
            'printer_id' => $printer['id'],
            'payload' => $payload,
            'idempotency_key' => $this->deliveryKey($requestKey, $printer['id'], 'test'),
            'created_by' => $createdBy,
        ]);
        $job = $this->processIfEligible($conn, $job);

        return $this->summarize([$job]);
    }

    public function retryFailedJob(
        mysqli $conn,
        int $jobId,
        array $scope,
        bool $uncertainOutputChecked = false
    ): array
    {
        $existing = $this->jobs->jobById($conn, $jobId);
        if ($existing['printer_id'] === null) {
            throw new RuntimeException('PRINT_JOB_PRINTER_REQUIRED');
        }
        $this->jobs->getPrinterInScope($conn, $existing['printer_id'], $scope, false);
        $lastError = (string) ($existing['last_error'] ?? '');
        $uncertainDelivery = str_starts_with($lastError, 'PRINT_NETWORK_DELIVERY_UNCERTAIN')
            || str_starts_with($lastError, 'PRINT_BRIDGE_DELIVERY_UNCERTAIN');
        if ($uncertainDelivery && !$uncertainOutputChecked) {
            throw new RuntimeException('PRINT_UNCERTAIN_RETRY_CONFIRMATION_REQUIRED');
        }
        $job = $this->jobs->releaseForRetry($conn, $jobId);
        return $this->processIfEligible($conn, $job);
    }

    public function dispatchPayload(
        mysqli $conn,
        string $jobType,
        array $payload,
        string $requestKey,
        array $scope,
        ?int $createdBy = null,
        ?int $orderId = null
    ): array {
        if (!$this->jobs->supportsReliableDelivery($conn)) {
            throw new RuntimeException('PRINT_RELIABLE_SCHEMA_REQUIRED');
        }
        $requestKey = $this->requestKey($requestKey);
        $routes = $this->routing->route($conn, $jobType, $payload, $scope);
        $jobs = [];

        foreach ($routes as $route) {
            $printer = $route['printer'];
            $job = $this->jobs->enqueue($conn, [
                'job_type' => $jobType,
                'order_id' => $orderId,
                'printer_id' => $printer['id'],
                'payload' => $route['payload'],
                'idempotency_key' => $this->deliveryKey(
                    $requestKey,
                    (int) $printer['id'],
                    $this->routing->normalizeFunction($jobType)
                ),
                'created_by' => $createdBy,
            ]);
            $jobs[] = $this->processIfEligible($conn, $job);
        }

        return $this->summarize($jobs);
    }

    private function processIfEligible(mysqli $conn, array $job): array
    {
        if ($job['status'] !== 'queued') {
            return $job;
        }
        if (
            trim((string) ($job['locked_by'] ?? '')) !== ''
            && $job['locked_until'] !== null
            && strtotime((string) $job['locked_until']) > time()
        ) {
            return $job;
        }
        if (
            $job['next_retry_at'] !== null
            && strtotime((string) $job['next_retry_at']) > time()
        ) {
            return $job;
        }

        try {
            return $this->worker->processJob($conn, (int) $job['id']);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                'PRINT_JOB_ALREADY_CLAIMED',
                'PRINT_JOB_NOT_QUEUED',
                'PRINT_JOB_RETRY_NOT_DUE',
            ], true)) {
                return $this->jobs->jobById($conn, (int) $job['id']);
            }
            throw $exception;
        }
    }

    private function summarize(array $jobs): array
    {
        $counts = ['printed' => 0, 'queued' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($jobs as $job) {
            $status = (string) ($job['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }
        $status = $counts['failed'] > 0
            ? 'attention_required'
            : ($counts['queued'] > 0 ? 'queued' : 'printed');

        return [
            'status' => $status,
            'counts' => $counts,
            'jobs' => $jobs,
        ];
    }

    private function deliveryKey(string $requestKey, int $printerId, string $function): string
    {
        return 'print:' . hash('sha256', $requestKey . '|' . $printerId . '|' . $function);
    }

    private function requestKey(string $value): string
    {
        $value = trim($value);
        if (
            strlen($value) < 8
            || strlen($value) > 191
            || !preg_match('/^[a-zA-Z0-9._:-]+$/', $value)
        ) {
            throw new InvalidArgumentException('PRINT_REQUEST_KEY_INVALID');
        }
        return $value;
    }

    private function boundedText(string $value, int $maxLength, string $code): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($code);
        }
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException($code);
        }
        return $value;
    }
}
