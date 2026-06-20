<?php

class OperationalSyncDomains
{
    public static function all(): array
    {
        return [
            'item_category' => [
                'table' => 'item_group',
                'aggregate_type' => 'item_category',
                'entity_type' => 'item_category',
                'event_type' => 'category.saved',
                'exclude_columns' => [],
            ],
            'inventory_balance' => [
                'table' => 'inventory_item_balances',
                'aggregate_type' => 'inventory_balance',
                'entity_type' => 'inventory_balance',
                'event_type' => 'inventory.balance_saved',
                'exclude_columns' => [],
            ],
            'inventory_stock_level' => [
                'table' => 'inventory_item_stock_levels',
                'aggregate_type' => 'inventory_stock_level',
                'entity_type' => 'inventory_stock_level',
                'event_type' => 'inventory.stock_level_saved',
                'exclude_columns' => [],
            ],
            'inventory_movement' => [
                'table' => 'inventory_movements',
                'aggregate_type' => 'inventory_movement',
                'entity_type' => 'inventory_movement',
                'event_type' => 'inventory.movement_saved',
                'exclude_columns' => [],
            ],
            'recipe' => [
                'composite' => true,
                'aggregate_type' => 'recipe',
                'entity_type' => 'recipe',
                'event_type' => 'recipe.saved',
            ],
            'employee' => [
                'table' => 'employees',
                'aggregate_type' => 'employee',
                'entity_type' => 'employee',
                'event_type' => 'employee.saved',
                'exclude_columns' => ['password'],
            ],
            'pulse_log' => [
                'table' => 'pulse_logs',
                'aggregate_type' => 'pulse_log',
                'entity_type' => 'pulse_log',
                'event_type' => 'pulse.log_saved',
                'exclude_columns' => [],
            ],
            'pulse_type' => [
                'table' => 'pulse_types',
                'aggregate_type' => 'pulse_type',
                'entity_type' => 'pulse_type',
                'event_type' => 'pulse.type_saved',
                'exclude_columns' => [],
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
}
