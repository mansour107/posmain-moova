<?php

class RestaurantReportContractService
{
    public function all(): array
    {
        return [
            'daily_sales' => [
                'title' => 'Daily sales',
                'sources' => ['ot_head', 'fat_details', 'payment_methods', 'drawer_movements'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'store_id', 'cashier_id', 'order_type'],
                'totals' => ['gross_sales', 'discounts', 'net_sales', 'paid_amount', 'remaining_amount', 'order_count', 'item_count'],
                'invariants' => [
                    'net_sales = gross_sales - discounts + service_plus + tax',
                    'paid_amount + remaining_amount = net_sales for non-void active/completed orders',
                    'fat_details active line totals reconcile to ot_head.fat_total for included orders',
                ],
            ],
            'payment_method_breakdown' => [
                'title' => 'Payment method breakdown',
                'sources' => ['order_payments', 'payment_methods', 'ot_head', 'drawer_movements'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'cashier_id', 'payment_method_code', 'drawer_session_id'],
                'totals' => ['payment_total', 'cash_total', 'card_total', 'wallet_total', 'bank_total', 'reference_required_count'],
                'invariants' => [
                    'payment_total = sum(order_payments.amount) grouped by payment_methods.type',
                    'cash_total must reconcile with drawer_movements.sale_cash minus refund_cash for the same drawer scope',
                    'methods with requires_reference must expose missing_reference_count before endpoint enforcement',
                ],
            ],
            'order_channel_split' => [
                'title' => 'Table / takeaway / delivery / Moova split',
                'sources' => ['ot_head', 'moova_order_links', 'order_events'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'channel', 'order_type'],
                'totals' => ['table_net', 'takeaway_net', 'delivery_net', 'moova_net', 'order_count', 'average_ticket'],
                'invariants' => [
                    'channel net totals sum to daily_sales.net_sales for the same paid/completed order scope',
                    'Moova-linked orders are identified by moova_order_links and not by cashier text labels',
                    'table orders use ot_head.table_id > 0 unless order_type explicitly overrides channel',
                ],
            ],
            'open_tables' => [
                'title' => 'Open tables',
                'sources' => ['tables', 'table_areas', 'ot_head', 'fat_details'],
                'filters' => ['tenant', 'branch', 'area_id', 'waiter_id'],
                'totals' => ['open_table_count', 'active_order_count', 'open_net', 'oldest_open_minutes'],
                'invariants' => [
                    'open tables are derived from active unpaid/partial ot_head rows, not table_case alone',
                    'table_case is cache only and must be reconciled from active order truth',
                    'paid/completed/cancelled orders must not count as open tables',
                ],
            ],
            'shift_z' => [
                'title' => 'Shift Z report',
                'sources' => ['drawer_sessions', 'drawer_movements', 'order_payments', 'payment_methods', 'ot_head'],
                'filters' => ['drawer_session_id', 'date_from', 'date_to', 'tenant', 'branch', 'cashier_id'],
                'totals' => ['opening_cash', 'cash_sales', 'cash_refunds', 'paid_in', 'paid_out', 'safe_drop', 'expected_cash', 'counted_cash', 'difference'],
                'invariants' => [
                    'expected_cash = opening_cash + sale_cash - refund_cash + paid_in - paid_out - safe_drop + closing_adjustment',
                    'cash_sales reconcile to payment_methods.type = cash for orders in the drawer session',
                    'Z report status must match drawer_sessions.status and must not infer close from report rendering',
                ],
            ],
            'item_performance' => [
                'title' => 'Item performance',
                'sources' => ['fat_details', 'ot_head', 'myitems', 'modifier_options', 'order_line_modifiers'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'store_id', 'item_id', 'category_id', 'order_type'],
                'totals' => ['qty_sold', 'gross_sales', 'discounts', 'net_sales', 'modifier_delta_total', 'average_price'],
                'invariants' => [
                    'qty_sold uses decimal quantities from fat_details and must not cast to int',
                    'modifier_delta_total comes from order_line_modifiers and is reported separately until totals integration is wired',
                    'inactive/deleted details are excluded consistently with daily sales',
                ],
            ],
            'category_performance' => [
                'title' => 'Category performance',
                'sources' => ['fat_details', 'ot_head', 'myitems'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'group1', 'order_type'],
                'totals' => ['qty_sold', 'net_sales', 'order_count', 'item_count', 'average_ticket_share'],
                'invariants' => [
                    'category totals aggregate the same item rows used by item_performance',
                    'category net_sales sums to item_performance.net_sales for matching filters',
                    'deleted items remain reportable through historical fat_details rows when item ids still exist',
                ],
            ],
            'low_stock' => [
                'title' => 'Low stock',
                'sources' => ['myitems', 'fat_details', 'inventory_movements'],
                'filters' => ['tenant', 'branch', 'store_id', 'category_id', 'track_stock'],
                'totals' => ['on_hand_qty', 'reserved_qty', 'below_reorder_count', 'out_of_stock_count'],
                'invariants' => [
                    'on_hand_qty uses decimal stock movement sums and must not cast to int',
                    'non-stock tracked menu items are excluded when track_stock = 0',
                    'inventory_movements is preferred when available; fat_details remains the legacy reconciliation source',
                ],
            ],
            'void_cancel_audit' => [
                'title' => 'Void and cancel audit',
                'sources' => ['order_events', 'manager_approvals', 'ot_head', 'security_audit_log'],
                'filters' => ['date_from', 'date_to', 'tenant', 'branch', 'actor_user_id', 'event_type', 'approval_status'],
                'totals' => ['cancel_count', 'void_count', 'refund_count', 'approval_count', 'declined_count', 'net_reversed'],
                'invariants' => [
                    'paid void/refund events must link to manager_approvals when policy requires approval',
                    'cancelled unpaid orders must preserve reason and actor in order_events or security_audit_log',
                    'net_reversed must reconcile to payment/order reversal rows once refund flow is integrated',
                ],
            ],
        ];
    }

    public function get(string $key): array
    {
        $contracts = $this->all();
        if (!isset($contracts[$key])) {
            throw new InvalidArgumentException('REPORT_CONTRACT_NOT_FOUND');
        }

        return $contracts[$key];
    }

    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function sourceTables(): array
    {
        $sources = [];
        foreach ($this->all() as $contract) {
            foreach ($contract['sources'] as $source) {
                $sources[$source] = true;
            }
        }

        return array_keys($sources);
    }

    public function invariantsFor(string $key): array
    {
        return $this->get($key)['invariants'];
    }
}
