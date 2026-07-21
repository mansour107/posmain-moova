<?php

class OperationalSyncDomains
{
    public static function all(): array
    {
        return array_merge(self::coreDomains(), self::extendedDomains());
    }

    public static function coreDomains(): array
    {
        return [
            'item_category' => self::rowDomain('item_group', 'item_category', 'category.saved', 'item_categories'),
            'inventory_balance' => self::rowDomain('inventory_item_balances', 'inventory_balance', 'inventory.balance_saved', 'inventory_balances'),
            'inventory_stock_level' => self::rowDomain('inventory_item_stock_levels', 'inventory_stock_level', 'inventory.stock_level_saved', 'inventory_stock_levels'),
            'inventory_movement' => self::rowDomain('inventory_movements', 'inventory_movement', 'inventory.movement_saved', 'inventory_movements'),
            'recipe' => [
                'composite' => true,
                'aggregate_type' => 'recipe',
                'entity_type' => 'recipe',
                'event_type' => 'recipe.saved',
                'push_counter' => 'recipes',
            ],
            'employee' => self::rowDomain('employees', 'employee', 'employee.saved', 'employees', ['password']),
            'pulse_log' => self::rowDomain('pulse_logs', 'pulse_log', 'pulse.log_saved', 'pulse_logs'),
            'pulse_type' => self::rowDomain('pulse_types', 'pulse_type', 'pulse.type_saved', 'pulse_types'),
        ];
    }

    public static function extendedDomains(): array
    {
        return [
            'customer' => self::rowDomain('customers', 'customer', 'customer.saved', 'customers'),
            'delivery_client' => self::rowDomain('delivery_clients', 'delivery_client', 'delivery_client.saved', 'delivery_clients'),
            'delivery_zone' => self::rowDomain('delivery_zones', 'delivery_zone', 'delivery_zone.saved', 'delivery_zones'),
            'order_fulfillment' => self::rowDomain('order_fulfillment', 'order_fulfillment', 'order_fulfillment.saved', 'order_fulfillments'),
            'payment_method' => self::rowDomain('payment_methods', 'payment_method', 'payment_method.saved', 'payment_methods'),
            'table_area' => self::rowDomain('table_areas', 'table_area', 'table_area.saved', 'table_areas'),
            'drawer_session' => self::rowDomain('drawer_sessions', 'drawer_session', 'drawer_session.saved', 'drawer_sessions'),
            'drawer_movement' => self::rowDomain('drawer_movements', 'drawer_movement', 'drawer_movement.saved', 'drawer_movements'),
            'order_event' => self::rowDomain('order_events', 'order_event', 'order_event.saved', 'order_events'),
            'external_order_line_map' => self::rowDomain('external_order_line_map', 'external_order_line_map', 'external_order_line_map.saved', 'external_order_line_maps'),
            'manager_approval' => self::rowDomain('manager_approvals', 'manager_approval', 'manager_approval.saved', 'manager_approvals'),
            'item_unit' => self::rowDomain('item_units', 'item_unit', 'item_unit.saved', 'item_units'),
            'item_availability' => self::rowDomain('item_availability', 'item_availability', 'item_availability.saved', 'item_availabilities'),
            'item_variant' => self::rowDomain('item_variants', 'item_variant', 'item_variant.saved', 'item_variants'),
            'item_preparation_config' => self::rowDomain('item_preparation_configs', 'item_preparation_config', 'item_preparation_config.saved', 'item_preparation_configs'),
            'item_group_preparation_config' => self::rowDomain('item_group_preparation_configs', 'item_group_preparation_config', 'item_group_preparation_config.saved', 'item_group_preparation_configs'),
            'inventory_count' => self::rowDomain('inventory_counts', 'inventory_count', 'inventory.count_saved', 'inventory_counts'),
            'inventory_count_line' => self::rowDomain('inventory_count_lines', 'inventory_count_line', 'inventory.count_line_saved', 'inventory_count_lines'),
            'inventory_transfer' => self::rowDomain('inventory_transfers', 'inventory_transfer', 'inventory.transfer_saved', 'inventory_transfers'),
            'inventory_transfer_line' => self::rowDomain('inventory_transfer_lines', 'inventory_transfer_line', 'inventory.transfer_line_saved', 'inventory_transfer_lines'),
            'inventory_purchase_order' => self::rowDomain('inventory_purchase_orders', 'inventory_purchase_order', 'inventory.purchase_order_saved', 'inventory_purchase_orders'),
            'inventory_purchase_order_line' => self::rowDomain('inventory_purchase_order_lines', 'inventory_purchase_order_line', 'inventory.purchase_order_line_saved', 'inventory_purchase_order_lines'),
            'inventory_purchase_receipt' => self::rowDomain('inventory_purchase_receipts', 'inventory_purchase_receipt', 'inventory.purchase_receipt_saved', 'inventory_purchase_receipts'),
            'inventory_purchase_receipt_line' => self::rowDomain('inventory_purchase_receipt_lines', 'inventory_purchase_receipt_line', 'inventory.purchase_receipt_line_saved', 'inventory_purchase_receipt_lines'),
            'inventory_reason_code' => self::rowDomain('inventory_reason_codes', 'inventory_reason_code', 'inventory.reason_code_saved', 'inventory_reason_codes'),
            'production_batch' => self::rowDomain('production_batches', 'production_batch', 'production.batch_saved', 'production_batches'),
            'production_batch_line' => self::rowDomain('production_batch_lines', 'production_batch_line', 'production.batch_line_saved', 'production_batch_lines'),
            'recipe_order_line_usage' => self::rowDomain('recipe_order_line_usage', 'recipe_order_line_usage', 'recipe.order_line_usage_saved', 'recipe_order_line_usages'),
            'store' => self::rowDomain('stores', 'store', 'store.saved', 'stores'),
            'town' => self::rowDomain('towns', 'town', 'town.saved', 'towns'),
            'moova_table_link' => self::rowDomain('moova_pos_table_links', 'moova_table_link', 'moova.table_link_saved', 'moova_table_links'),
            'moova_order_link' => self::rowDomain(
                'moova_pos_order_links',
                'moova_order_link',
                'moova.order_link_saved',
                'moova_order_links',
                ['request_payload', 'response_payload', 'last_pos_state_payload']
            ),
            'printer' => self::rowDomain('printers', 'printer', 'printer.saved', 'printers'),
            'print_job' => self::rowDomain('print_jobs', 'print_job', 'print_job.saved', 'print_jobs'),
            'item_nutrition_profile' => self::rowDomain('item_nutrition_profiles', 'item_nutrition_profile', 'item_nutrition.saved', 'item_nutrition_profiles'),
            'item_image' => self::rowDomain('imgs', 'item_image', 'item_image.saved', 'item_images'),
            'item_modifier_group' => self::rowDomain('item_modifier_groups', 'item_modifier_group', 'item_modifier_group.saved', 'item_modifier_groups'),
            'shop_settings' => [
                'composite' => true,
                'aggregate_type' => 'shop_settings',
                'entity_type' => 'shop_settings',
                'event_type' => 'shop_settings.saved',
                'push_counter' => 'shop_settings',
            ],
            'modifier_group' => [
                'composite' => true,
                'aggregate_type' => 'modifier_group',
                'entity_type' => 'modifier_group',
                'event_type' => 'modifier_group.saved',
                'push_counter' => 'modifier_groups',
            ],
            'moova_shop_link' => [
                'composite' => true,
                'aggregate_type' => 'moova_shop_link',
                'entity_type' => 'moova_shop_link',
                'event_type' => 'moova.shop_link_saved',
                'push_counter' => 'moova_shop_links',
            ],
        ];
    }

    public static function get(string $domain): ?array
    {
        $domains = self::all();

        return $domains[$domain] ?? null;
    }

    public static function bulkRowDomains(): array
    {
        $domains = [];
        foreach (self::all() as $name => $definition) {
            if (!empty($definition['composite'])) {
                continue;
            }
            $domains[$name] = $definition;
        }

        return $domains;
    }

    public static function pushCounterMap(): array
    {
        $map = [];
        foreach (self::all() as $name => $definition) {
            if (empty($definition['push_counter'])) {
                continue;
            }
            $map[$name] = (string) $definition['push_counter'];
        }

        return $map;
    }

    private static function rowDomain(
        string $table,
        string $type,
        string $eventType,
        string $pushCounter,
        array $excludeColumns = []
    ): array {
        return [
            'table' => $table,
            'aggregate_type' => $type,
            'entity_type' => $type,
            'event_type' => $eventType,
            'exclude_columns' => $excludeColumns,
            'push_counter' => $pushCounter,
        ];
    }
}
