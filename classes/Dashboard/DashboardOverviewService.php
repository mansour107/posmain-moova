<?php

require_once __DIR__ . '/../Pos/Service/OperationsReportService.php';

/**
 * Aggregates ERP dashboard KPIs, attention rows, sales strip, and quick actions.
 * Templates must not run ad-hoc SQL; call build() once from dashboard.php.
 */
class DashboardOverviewService
{
    public const SALES_TYPES_SQL = "pro_tybe = 9 AND COALESCE(isdeleted, 0) = 0 AND payment_status IN ('paid', 'refunded')";
    public const REPORTS_URL = 'cash_flow_report.php?tab=overview';
    public const UNAVAILABLE_LABEL = 'غير متاح';

    /**
     * @param array<string, bool> $flags Permission / module flags from the page.
     * @return array{
     *   context: array{business_date: string, generated_at: string, currency: string},
     *   kpis: list<array{key: string, label: string, value: float|int|null, formatted: string, available: bool, url: string}>,
     *   attention: list<array{type: string, count: int, label: string, url: string}>,
     *   sales_strip: array{last_invoice: float|null, last_7d: float|null, last_30d: float|null, last_invoice_formatted: string, last_7d_formatted: string, last_30d_formatted: string, available: bool, reports_url: string},
     *   quick_actions: list<array{label: string, icon: string, url: string, permission: string}>
     * }
     */
    public function build(mysqli $conn, array $flags): array
    {
        $sales = $this->loadSalesMetrics($conn, $flags);
        $kpis = $this->buildKpis($sales);
        $attention = $this->buildAttention($conn, $flags);
        $salesStrip = $this->buildSalesStrip($sales);
        $quickActions = $this->filterQuickActions($flags);

        return [
            'context' => [
                'business_date' => (string) ($flags['business_day'] ?? date('Y-m-d')),
                'generated_at' => date('Y-m-d H:i:s'),
                'currency' => 'ج.م.',
            ],
            'kpis' => $kpis,
            'attention' => $attention,
            'sales_strip' => $salesStrip,
            'quick_actions' => $quickActions,
        ];
    }

    public static function averageOrderValue(float $salesTotal, int $orderCount): float
    {
        if ($orderCount <= 0) {
            return 0.0;
        }

        return $salesTotal / $orderCount;
    }

    public static function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    public static function formatCount(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    /**
     * @param array{available: bool, today_count: int, today_sum: float, week_sum: float, last_invoice: ?float, month_sum: float} $sales
     * @return list<array{key: string, label: string, value: float|int|null, formatted: string, available: bool, url: string}>
     */
    public function buildKpis(array $sales): array
    {
        $url = self::REPORTS_URL;
        $available = (bool) ($sales['available'] ?? false);
        $todayCount = (int) ($sales['today_count'] ?? 0);
        $todaySum = (float) ($sales['today_sum'] ?? 0);
        $weekSum = (float) ($sales['week_sum'] ?? 0);
        $aov = self::averageOrderValue($todaySum, $todayCount);

        $na = self::UNAVAILABLE_LABEL;

        return [
            [
                'key' => 'today_sales',
                'label' => 'مبيعات اليوم',
                'value' => $available ? $todaySum : null,
                'formatted' => $available ? self::formatMoney($todaySum) : $na,
                'available' => $available,
                'url' => $url,
            ],
            [
                'key' => 'today_orders',
                'label' => 'طلبات اليوم',
                'value' => $available ? $todayCount : null,
                'formatted' => $available ? self::formatCount($todayCount) : $na,
                'available' => $available,
                'url' => $url,
            ],
            [
                'key' => 'today_aov',
                'label' => 'متوسط قيمة الطلب',
                'value' => $available ? $aov : null,
                'formatted' => $available ? self::formatMoney($aov) : $na,
                'available' => $available,
                'url' => $url,
            ],
            [
                'key' => 'week_sales',
                'label' => 'مبيعات آخر 7 أيام',
                'value' => $available ? $weekSum : null,
                'formatted' => $available ? self::formatMoney($weekSum) : $na,
                'available' => $available,
                'url' => $url,
            ],
        ];
    }

    /**
     * @param array{available: bool, last_invoice: ?float, week_sum: float, month_sum: float} $sales
     * @return array{last_invoice: float|null, last_7d: float|null, last_30d: float|null, last_invoice_formatted: string, last_7d_formatted: string, last_30d_formatted: string, available: bool, reports_url: string}
     */
    public function buildSalesStrip(array $sales): array
    {
        $available = (bool) ($sales['available'] ?? false);
        $last = $sales['last_invoice'] ?? null;
        $week = (float) ($sales['week_sum'] ?? 0);
        $month = (float) ($sales['month_sum'] ?? 0);
        $na = self::UNAVAILABLE_LABEL;

        return [
            'last_invoice' => $available && $last !== null ? (float) $last : null,
            'last_7d' => $available ? $week : null,
            'last_30d' => $available ? $month : null,
            'last_invoice_formatted' => $available && $last !== null ? self::formatMoney((float) $last) : $na,
            'last_7d_formatted' => $available ? self::formatMoney($week) : $na,
            'last_30d_formatted' => $available ? self::formatMoney($month) : $na,
            'available' => $available,
            'reports_url' => self::REPORTS_URL,
        ];
    }

    /**
     * @param array<string, bool> $flags
     * @return list<array{type: string, count: int, label: string, url: string}>
     */
    public function buildAttention(mysqli $conn, array $flags): array
    {
        $rows = [];
        $candidates = [];

        if (!empty($flags['sid_rents'])) {
            $candidates[] = [
                'type' => 'overdue_installments',
                'label' => 'أقساط مستحقة',
                'url' => 'myrentables.php',
                'table' => 'myinstallments',
                'sql' => 'SELECT COUNT(*) AS c FROM myinstallments WHERE ins_case = 2',
            ];
        }
        if (!empty($flags['sid_hr'])) {
            $candidates[] = [
                'type' => 'pending_tasks',
                'label' => 'مهمات معلقة',
                'url' => 'tasks.php',
                'table' => 'tasks',
                'sql' => 'SELECT COUNT(*) AS c FROM tasks WHERE isdeleted IS NULL',
            ];
        }
        if (!empty($flags['clinic.enabled']) && !empty($flags['sid_clinics'])) {
            $candidates[] = [
                'type' => 'pending_reservations',
                'label' => 'زيارات معلقة',
                'url' => 'reservations.php',
                'table' => 'reservations',
                'sql' => 'SELECT COUNT(*) AS c FROM reservations WHERE duration IS NULL',
            ];
        }
        if (!empty($flags['reports.cash_flow'])) {
            $candidates[] = [
                'type' => 'open_drawers',
                'label' => 'جلسات درج مفتوحة',
                'url' => 'cash_flow_report.php?session_filter=open',
                'table' => 'drawer_sessions',
                'sql' => "SELECT COUNT(*) AS c FROM drawer_sessions WHERE status = 'open'",
            ];
        }

        foreach ($candidates as $c) {
            $count = $this->safeCount($conn, $c['table'], $c['sql']);
            if ($count > 0) {
                $rows[] = [
                    'type' => $c['type'],
                    'count' => $count,
                    'label' => $c['label'],
                    'url' => $c['url'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, bool> $flags
     * @return list<array{label: string, icon: string, url: string, permission: string}>
     */
    public function filterQuickActions(array $flags): array
    {
        $catalog = [
            [
                'label' => 'إضافة صنف',
                'icon' => 'fas fa-box-open',
                'url' => 'add_item.php',
                'permission' => 'menu.edit',
            ],
            [
                'label' => 'فاتورة مبيعات',
                'icon' => 'fas fa-receipt',
                'url' => 'sales.php?q=buy',
                'permission' => 'reports.view',
            ],
            [
                'label' => 'سند قبض',
                'icon' => 'fas fa-hand-holding-usd',
                'url' => 'add_voucher.php?t=recive',
                'permission' => 'accounting.view',
            ],
            [
                'label' => 'فاتورة مشتريات',
                'icon' => 'fas fa-file-invoice-dollar',
                'url' => 'sales.php?q=sale',
                'permission' => 'reports.view',
            ],
            [
                'label' => 'سند دفع',
                'icon' => 'fas fa-money-check-alt',
                'url' => 'add_voucher.php?t=payment',
                'permission' => 'accounting.view',
            ],
        ];

        $out = [];
        foreach ($catalog as $action) {
            $perm = $action['permission'];
            if (!empty($flags[$perm]) || !empty($flags['is_admin'])) {
                $out[] = $action;
            }
        }

        return array_slice($out, 0, 6);
    }

    /**
     * @return array{available: bool, today_count: int, today_sum: float, week_sum: float, month_sum: float, last_invoice: ?float}
     */
    public function loadSalesMetrics(mysqli $conn, array $scope = []): array
    {
        $empty = [
            'available' => false,
            'today_count' => 0,
            'today_sum' => 0.0,
            'week_sum' => 0.0,
            'month_sum' => 0.0,
            'last_invoice' => null,
        ];

        if (!$this->tableExists($conn, 'ot_head')) {
            return $empty;
        }

        try {
            $businessDay = (string) ($scope['business_day'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDay)) {
                $businessDay = date('Y-m-d');
            }
            $base = [
                'tenant' => max(0, (int) ($scope['tenant'] ?? 0)),
                'branch' => max(0, (int) ($scope['branch'] ?? 0)),
                'cashier_id' => 0,
            ];
            $reports = new OperationsReportService();
            $today = $reports->salesSummary($conn, $base + [
                'date_from' => $businessDay,
                'date_to' => $businessDay,
            ]);
            $weekFrom = date('Y-m-d', strtotime($businessDay . ' -6 days'));
            $monthFrom = date('Y-m-d', strtotime($businessDay . ' -29 days'));
            $week = $reports->salesSummary($conn, $base + [
                'date_from' => $weekFrom,
                'date_to' => $businessDay,
            ]);
            $month = $reports->salesSummary($conn, $base + [
                'date_from' => $monthFrom,
                'date_to' => $businessDay,
            ]);
            $recentOrders = $reports->orders($conn, $base + [
                'date_from' => $monthFrom,
                'date_to' => $businessDay,
            ], 50);
            $lastInvoice = null;
            foreach ($recentOrders as $order) {
                if ((int) ($order['isdeleted'] ?? 0) === 0
                    && in_array((string) ($order['payment_status'] ?? ''), ['paid', 'refunded'], true)) {
                    $lastInvoice = (float) ($order['fat_net'] ?? 0);
                    break;
                }
            }

            return [
                'available' => true,
                'today_count' => (int) ($today['order_count'] ?? 0),
                'today_sum' => (float) ($today['net_sales'] ?? 0),
                'week_sum' => (float) ($week['net_sales'] ?? 0),
                'month_sum' => (float) ($month['net_sales'] ?? 0),
                'last_invoice' => $lastInvoice,
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }

    private function safeCount(mysqli $conn, string $table, string $sql): int
    {
        if (!$this->tableExists($conn, $table)) {
            return 0;
        }

        try {
            $row = $this->fetchAssoc($conn, $sql);

            return (int) ($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $result = @$conn->query("SHOW TABLES LIKE '{$safe}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAssoc(mysqli $conn, string $sql): ?array
    {
        $result = $conn->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('QUERY_FAILED');
        }
        $row = $result->fetch_assoc();

        return $row ?: null;
    }
}
