<?php

require_once __DIR__ . '/PrintJobService.php';

class BrowserPrintAuditService
{
    private PrintJobService $printJobService;
    private array $tableExistsCache = [];

    public function __construct(?PrintJobService $printJobService = null)
    {
        $this->printJobService = $printJobService ?: new PrintJobService();
    }

    public function recordRenderedPrint(
        mysqli $conn,
        string $jobType,
        int $orderId,
        array $payload,
        ?int $createdBy = null,
        array $context = []
    ): ?array {
        if (!$this->tableExists($conn, 'print_jobs')) {
            return null;
        }

        $payload['browser_print_audit'] = [
            'source' => $context['source'] ?? 'browser_print',
            'rendered_at' => date('Y-m-d H:i:s'),
            'reprint_reason' => $this->nullableText($context['reprint_reason'] ?? null),
        ];

        $job = $this->printJobService->enqueue($conn, [
            'job_type' => $jobType,
            'order_id' => $orderId,
            'payload' => $payload,
            'created_by' => $createdBy,
        ]);

        return $this->printJobService->markPrinted($conn, (int) $job['id']);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->tableExistsCache[$table] = (int) ($row['c'] ?? 0) > 0;
        return $this->tableExistsCache[$table];
    }

    private function nullableText($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : substr($text, 0, 255);
    }
}
