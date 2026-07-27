<?php

require_once __DIR__ . '/CashFlowPeriodService.php';
require_once __DIR__ . '/ShiftCountService.php';

/**
 * One query context for the cash-and-shifts workspace.
 * The overview, shift list and movement ledger consume the same normalized
 * branch/business-day/cashier filters; only the explicitly-labelled backlog
 * scope is allowed to cross dates.
 */
class CashShiftWorkspaceService
{
    public const TAB_OVERVIEW = 'overview';
    public const TAB_SHIFTS = 'shifts';
    public const TAB_ORDERS = 'orders';
    public const TAB_PAYMENTS = 'payments';
    public const TAB_ITEMS = 'items';
    public const TAB_ATTENTION = 'attention';
    public const TAB_MOVEMENTS = 'movements';
    public const TAB_SETTINGS = 'settings';
    public const FOCUS_ORDER_CANCELLED = 'order_cancelled';
    public const FOCUS_ORDER_DISCOUNTED = 'order_discounted';
    public const FOCUS_PAYMENT_REFUNDS = 'payment_refunds';
    public const FOCUS_PAYMENT_PENDING_REFUNDS = 'payment_pending_refunds';
    public const FOCUS_PAYMENT_CASH_DIFFERENCE = 'payment_cash_difference';
    public const FOCUS_MOVEMENT_UNASSIGNED = 'movement_unassigned';
    public const TABS = [
        self::TAB_OVERVIEW,
        self::TAB_SHIFTS,
        self::TAB_ORDERS,
        self::TAB_PAYMENTS,
        self::TAB_ITEMS,
        self::TAB_ATTENTION,
        self::TAB_MOVEMENTS,
        self::TAB_SETTINGS,
    ];
    public const FOCUS_TABS = [
        self::FOCUS_ORDER_CANCELLED => self::TAB_ORDERS,
        self::FOCUS_ORDER_DISCOUNTED => self::TAB_ORDERS,
        self::FOCUS_PAYMENT_REFUNDS => self::TAB_PAYMENTS,
        self::FOCUS_PAYMENT_PENDING_REFUNDS => self::TAB_PAYMENTS,
        self::FOCUS_PAYMENT_CASH_DIFFERENCE => self::TAB_PAYMENTS,
        self::FOCUS_MOVEMENT_UNASSIGNED => self::TAB_MOVEMENTS,
    ];

    private CashFlowPeriodService $cashFlow;
    private ShiftCountService $shiftCounts;

    public function __construct(
        ?CashFlowPeriodService $cashFlow = null,
        ?ShiftCountService $shiftCounts = null
    ) {
        $this->cashFlow = $cashFlow ?: new CashFlowPeriodService();
        $this->shiftCounts = $shiftCounts ?: new ShiftCountService();
    }

    public function normalizeContext(array $input, array $defaults): array
    {
        $tab = (string) ($input['tab'] ?? self::TAB_OVERVIEW);
        if (!in_array($tab, self::TABS, true)) {
            $tab = self::TAB_OVERVIEW;
        }
        $focus = trim((string) ($input['focus'] ?? ''));
        if (!isset(self::FOCUS_TABS[$focus]) || self::FOCUS_TABS[$focus] !== $tab) {
            $focus = '';
        }
        $status = (string) ($input['status'] ?? 'all');
        if (!in_array($status, ['all', 'open', 'closed', 'needs_review', 'forced_closed'], true)) {
            $status = 'all';
        }
        $scope = (string) ($input['scope'] ?? 'period');
        if (!in_array($scope, ['period', 'backlog'], true) || $status !== 'needs_review') {
            $scope = 'period';
        }

        $dateFrom = $this->date((string) ($input['date_from'] ?? $defaults['date_from'] ?? date('Y-m-d')));
        $dateTo = $this->date((string) ($input['date_to'] ?? $input['date_from'] ?? $defaults['date_to'] ?? date('Y-m-d')));
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'tab' => $tab,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'cashier_id' => max(0, (int) ($input['cashier_id'] ?? 0)),
            'override_operator_id' => max(0, (int) ($input['override_operator_id'] ?? 0)),
            'movement_type' => trim((string) ($input['movement_type'] ?? '')),
            'focus' => $focus,
            'status' => $status,
            'scope' => $scope,
            'has_override' => !empty($input['has_override']),
            'page' => max(1, (int) ($input['page'] ?? $input['session_page'] ?? 1)),
            'tenant' => (int) ($defaults['tenant'] ?? 0),
            'branch' => (int) ($defaults['branch'] ?? 0),
        ];
    }

    public function periodFilters(array $context): array
    {
        return [
            'date_from' => $context['date_from'],
            'date_to' => $context['date_to'],
            'cashier_id' => $context['cashier_id'],
            'movement_type' => $context['movement_type'],
            'focus' => (string) ($context['focus'] ?? ''),
            'only_unassigned' => ($context['focus'] ?? '') === self::FOCUS_MOVEMENT_UNASSIGNED,
            'drawer_session_id' => 0,
            'include_unassigned' => true,
            'tenant' => $context['tenant'],
            'branch' => $context['branch'],
        ];
    }

    /**
     * Relative to the current business day, not the browser's wall-clock day.
     * This keeps one-click ranges aligned with the report's cutoff semantics.
     *
     * @return array<string, array{label:string,date_from:string,date_to:string}>
     */
    public function datePresets(string $currentBusinessDay): array
    {
        $current = DateTimeImmutable::createFromFormat('!Y-m-d', $currentBusinessDay);
        if (!$current) {
            $current = new DateTimeImmutable('today');
        }

        return [
            'today' => [
                'label' => 'اليوم',
                'date_from' => $current->format('Y-m-d'),
                'date_to' => $current->format('Y-m-d'),
            ],
            'yesterday' => [
                'label' => 'أمس',
                'date_from' => $current->modify('-1 day')->format('Y-m-d'),
                'date_to' => $current->modify('-1 day')->format('Y-m-d'),
            ],
            'last_7_days' => [
                'label' => 'آخر 7 أيام',
                'date_from' => $current->modify('-6 days')->format('Y-m-d'),
                'date_to' => $current->format('Y-m-d'),
            ],
            'last_30_days' => [
                'label' => 'آخر 30 يومًا',
                'date_from' => $current->modify('-29 days')->format('Y-m-d'),
                'date_to' => $current->format('Y-m-d'),
            ],
            'month_to_date' => [
                'label' => 'من بداية الشهر',
                'date_from' => $current->modify('first day of this month')->format('Y-m-d'),
                'date_to' => $current->format('Y-m-d'),
            ],
        ];
    }

    public function sessionsPage(mysqli $conn, array $context, int $perPage = 12): array
    {
        $perPage = max(1, min(50, $perPage));
        if ($context['scope'] === 'backlog' && $context['status'] === 'needs_review') {
            $backlogOptions = $this->backlogOptions($context);
            $total = $this->shiftCounts->countUnresolvedSessions(
                $conn,
                (int) $context['tenant'],
                (int) $context['branch'],
                $backlogOptions
            );
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min((int) $context['page'], $pages);
            $rows = $this->shiftCounts->unresolvedSessions(
                $conn,
                (int) $context['tenant'],
                (int) $context['branch'],
                $perPage,
                $backlogOptions + ['offset' => ($page - 1) * $perPage]
            );
            $overrideIds = $this->overrideSessionIdsForRows($conn, $rows);
            foreach ($rows as &$row) {
                // The unresolved-session query is intentionally lean. Enrich
                // each visible backlog row from the same ledger-backed read
                // model used by the period list so monetary columns never
                // degrade to placeholder zeroes in review mode.
                $financial = $this->financialSessionForBacklogRow($conn, $row, $context);
                $row = array_merge($financial, $row);
                if (empty($row['business_day'])) {
                    $row['business_day'] = substr((string) ($row['opened_at'] ?? ''), 0, 10);
                }
                $row['close_variance'] = $this->varianceAmount($row);
                $row['count_pending'] = false;
                $row['variance_status'] = (string) ($row['variance_status'] ?? 'unresolved');
                $row['has_override'] = isset($overrideIds[(int) $row['id']]);
                if ($financial === []) {
                    $row = array_merge(
                        $row,
                        $this->cashFlow->accountabilityForSession($conn, (int) ($row['id'] ?? 0))
                    );
                }
            }
            unset($row);

            return compact('rows', 'total', 'pages', 'page') + ['scope' => 'backlog'];
        }

        $rows = $this->cashFlow->sessions($conn, $this->periodFilters($context));
        $overrideIds = $this->overrideSessionIds($conn, $context);
        $rows = array_values(array_filter($rows, function (array $row) use ($context, $overrideIds): bool {
            $status = (string) ($row['status'] ?? '');
            $varianceStatus = (string) ($row['variance_status'] ?? 'none');
            if ($context['status'] === 'open' && $status !== 'open') {
                return false;
            }
            if ($context['status'] === 'closed' && $status !== 'closed') {
                return false;
            }
            if ($context['status'] === 'forced_closed' && $status !== 'forced_closed') {
                return false;
            }
            if ($context['status'] === 'needs_review'
                && !in_array($varianceStatus, ['counted_pending_review', 'unresolved'], true)) {
                return false;
            }
            if ($context['has_override'] && !isset($overrideIds[(int) $row['id']])) {
                return false;
            }

            return true;
        }));
        foreach ($rows as &$row) {
            $row['has_override'] = isset($overrideIds[(int) $row['id']]);
        }
        unset($row);

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min((int) $context['page'], $pages);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return compact('rows', 'total', 'pages', 'page') + ['scope' => 'period'];
    }

    public function backlogCount(mysqli $conn, array $context): int
    {
        return $this->shiftCounts->countUnresolvedSessions(
            $conn,
            (int) $context['tenant'],
            (int) $context['branch'],
            $this->backlogOptions($context)
        );
    }

    /**
     * Count and list must consume exactly the same backlog predicates. Keeping
     * this mapping in one place prevents the review badge from drifting from
     * the rows after a cashier or temporary-operator filter is applied.
     */
    private function backlogOptions(array $context): array
    {
        return [
            'user_id' => (int) ($context['cashier_id'] ?? 0),
            'override_operator_id' => (int) ($context['override_operator_id'] ?? 0),
            'has_override' => !empty($context['has_override']),
        ];
    }

    private function overrideSessionIds(mysqli $conn, array $context): array
    {
        $filters = [
            'date_from' => $context['date_from'],
            'date_to' => $context['date_to'],
            'tenant' => $context['tenant'],
            'branch' => $context['branch'],
        ];
        if ($context['override_operator_id'] > 0) {
            $filters['operator_user_id'] = $context['override_operator_id'];
        }
        if ($context['cashier_id'] > 0) {
            $filters['original_owner_user_id'] = $context['cashier_id'];
        }
        $ids = [];
        foreach ($this->cashFlow->overridePeriods($conn, $filters) as $period) {
            $ids[(int) ($period['drawer_session_id'] ?? 0)] = true;
        }

        return $ids;
    }

    /** @return array<int, true> */
    private function overrideSessionIdsForRows(mysqli $conn, array $rows): array
    {
        $table = $conn->query("SHOW TABLES LIKE 'drawer_override_periods'");
        if (!$table || $table->num_rows < 1) {
            return [];
        }
        $sessionIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ))));
        if ($sessionIds === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $sessionIds));
        $result = $conn->query(
            "SELECT DISTINCT drawer_session_id FROM drawer_override_periods WHERE drawer_session_id IN ({$ids})"
        );
        $found = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $found[(int) $row['drawer_session_id']] = true;
            }
            $result->free();
        }

        return $found;
    }

    private function varianceAmount(array $row): float
    {
        $type = (string) ($row['variance_type'] ?? 'closing');
        if ($type === 'opening') {
            return (float) ($row['opening_variance'] ?? 0);
        }
        if ($type === 'both') {
            return (float) ($row['opening_variance'] ?? 0) + (float) ($row['difference'] ?? 0);
        }

        return (float) ($row['difference'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function financialSessionForBacklogRow(mysqli $conn, array $row, array $context): array
    {
        $openedDate = substr((string) ($row['opened_at'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $openedDate)) {
            return [];
        }

        // A session opened before the branch cutoff belongs to the preceding
        // business day, so the lookup window deliberately includes both days.
        $from = (new DateTimeImmutable($openedDate))->modify('-1 day')->format('Y-m-d');
        $sessions = $this->cashFlow->sessions($conn, [
            'date_from' => $from,
            'date_to' => $openedDate,
            'tenant' => (int) ($context['tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? 0),
            'drawer_session_id' => (int) ($row['id'] ?? 0),
        ]);

        return $sessions[0] ?? [];
    }

    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }
}
