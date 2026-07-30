<?php

require_once __DIR__ . '/PrintJobService.php';

class PrinterRoutingService
{
    public const ROUTABLE_FUNCTIONS = ['receipt', 'kot', 'report', 'label', 'document'];

    private PrintJobService $jobs;

    public function __construct(?PrintJobService $jobs = null)
    {
        $this->jobs = $jobs ?: new PrintJobService();
    }

    /**
     * @return array<int, array{printer:array,payload:array,matched_category_ids:array<int,int>}>
     */
    public function route(mysqli $conn, string $jobType, array $payload, array $scope = []): array
    {
        $function = $this->normalizeFunction($jobType);
        $printers = $this->jobs->listActivePrinters($conn, $scope);
        $matching = [];

        foreach ($printers as $printer) {
            // Browser records belong to the legacy dialog path. They remain in
            // the database for rollback compatibility but can never become a
            // silent-delivery target.
            if (!in_array((string) ($printer['connection_type'] ?? ''), ['file', 'network'], true)) {
                continue;
            }
            $routing = $this->normalizeRouting($printer['config']['routing'] ?? []);
            if (!in_array($function, $routing['functions'], true)) {
                continue;
            }

            if ($function !== 'kot') {
                $matching[] = [
                    'printer' => $printer,
                    'payload' => $payload,
                    'matched_category_ids' => [],
                ];
                continue;
            }

            $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
            if ($lines === []) {
                throw new RuntimeException('PRINT_KOT_LINES_REQUIRED');
            }

            if ($routing['all_categories']) {
                $matching[] = [
                    'printer' => $printer,
                    'payload' => $payload,
                    'matched_category_ids' => $this->lineCategoryIds($lines),
                ];
                continue;
            }

            $routedLines = array_values(array_filter(
                $lines,
                static function ($line) use ($routing): bool {
                    $categoryId = is_array($line) ? (int) ($line['item_group_id'] ?? 0) : 0;
                    return $categoryId > 0 && in_array($categoryId, $routing['category_ids'], true);
                }
            ));
            if ($routedLines === []) {
                continue;
            }

            $routedPayload = $payload;
            $routedPayload['lines'] = $routedLines;
            $matching[] = [
                'printer' => $printer,
                'payload' => $routedPayload,
                'matched_category_ids' => $this->lineCategoryIds($routedLines),
            ];
        }

        if ($matching === []) {
            throw new RuntimeException('PRINT_ROUTE_NOT_CONFIGURED');
        }

        if ($function === 'kot') {
            $this->assertEveryKitchenLineIsRouted($payload['lines'], $matching);
        }

        return $matching;
    }

    public function normalizeRouting($value): array
    {
        $value = is_array($value) ? $value : [];
        $functions = [];
        foreach ((array) ($value['functions'] ?? []) as $function) {
            try {
                $normalized = $this->normalizeFunction((string) $function);
            } catch (Throwable $exception) {
                continue;
            }
            if (!in_array($normalized, $functions, true)) {
                $functions[] = $normalized;
            }
        }

        $categoryIds = [];
        foreach ((array) ($value['category_ids'] ?? []) as $categoryId) {
            $categoryId = (int) $categoryId;
            if ($categoryId > 0 && !in_array($categoryId, $categoryIds, true)) {
                $categoryIds[] = $categoryId;
            }
        }
        sort($categoryIds, SORT_NUMERIC);

        return [
            'functions' => $functions,
            'all_categories' => filter_var(
                $value['all_categories'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'category_ids' => $categoryIds,
        ];
    }

    public function buildPrinterConfig(array $input): array
    {
        $connectionType = strtolower(trim((string) ($input['connection_type'] ?? 'file')));
        $paperWidth = (int) ($input['paper_width'] ?? 80);
        $config = [
            'paper_width' => in_array($paperWidth, [58, 80], true) ? $paperWidth : 80,
            'routing' => $this->normalizeRouting([
                'functions' => $input['functions'] ?? [],
                'all_categories' => $input['all_categories'] ?? false,
                'category_ids' => $input['category_ids'] ?? [],
            ]),
        ];

        if ($config['routing']['functions'] === []) {
            throw new InvalidArgumentException('PRINT_ROUTE_FUNCTION_REQUIRED');
        }
        if (
            in_array('kot', $config['routing']['functions'], true)
            && !$config['routing']['all_categories']
            && $config['routing']['category_ids'] === []
        ) {
            throw new InvalidArgumentException('PRINT_ROUTE_CATEGORY_REQUIRED');
        }

        if ($connectionType === 'file') {
            $simulatorKey = preg_replace(
                '/[^a-zA-Z0-9_-]+/',
                '-',
                trim((string) ($input['simulator_key'] ?? ''))
            );
            $simulatorKey = trim((string) $simulatorKey, '-');
            if ($simulatorKey === '') {
                throw new InvalidArgumentException('PRINT_SIMULATOR_KEY_REQUIRED');
            }
            $config['simulator_key'] = substr($simulatorKey, 0, 64);
        } elseif ($connectionType === 'network') {
            $host = trim((string) ($input['host'] ?? ''));
            if (
                $host === ''
                || strlen($host) > 253
                || preg_match('/[\\x00-\\x20\\/\\\\]/', $host)
            ) {
                throw new InvalidArgumentException('PRINT_NETWORK_HOST_INVALID');
            }
            $port = (int) ($input['port'] ?? 9100);
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('PRINT_NETWORK_PORT_INVALID');
            }
            $config['host'] = $host;
            $config['port'] = $port;
        }

        return $config;
    }

    public function normalizeFunction(string $jobType): string
    {
        $jobType = strtolower(trim($jobType));
        $map = [
            'receipt' => 'receipt',
            'kot' => 'kot',
            'kitchen' => 'kot',
            'z_report' => 'report',
            'x_report' => 'report',
            'report' => 'report',
            'label' => 'label',
            'document' => 'document',
        ];
        if (!isset($map[$jobType])) {
            throw new InvalidArgumentException('PRINT_ROUTE_FUNCTION_INVALID');
        }

        return $map[$jobType];
    }

    private function assertEveryKitchenLineIsRouted(array $lines, array $routes): void
    {
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new RuntimeException('PRINT_KOT_LINE_INVALID');
            }
            $detailId = (int) ($line['detail_id'] ?? 0);
            $itemId = (int) ($line['item_id'] ?? 0);
            $routed = false;
            foreach ($routes as $route) {
                foreach ($route['payload']['lines'] as $routedLine) {
                    if (
                        ($detailId > 0 && (int) ($routedLine['detail_id'] ?? 0) === $detailId)
                        || ($detailId < 1 && $itemId > 0 && (int) ($routedLine['item_id'] ?? 0) === $itemId)
                        || ($detailId < 1 && $itemId < 1 && $routedLine === $line)
                    ) {
                        $routed = true;
                        break 2;
                    }
                }
            }
            if (!$routed) {
                $categoryId = (int) ($line['item_group_id'] ?? 0);
                throw new RuntimeException(
                    'PRINT_KOT_LINE_UNROUTED:' . ($detailId > 0 ? $detailId : $index) . ':' . $categoryId
                );
            }
        }
    }

    private function lineCategoryIds(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $id = is_array($line) ? (int) ($line['item_group_id'] ?? 0) : 0;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
