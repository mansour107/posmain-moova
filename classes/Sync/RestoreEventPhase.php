<?php

require_once __DIR__ . '/OperationalSyncDomains.php';

class RestoreEventPhase
{
    public const MENU = 'menu';
    public const TABLES = 'tables';
    public const ORDERS = 'orders';
    public const OPERATIONAL = 'operational';

    public static function all(): array
    {
        return [
            self::MENU,
            self::TABLES,
            self::ORDERS,
            self::OPERATIONAL,
        ];
    }

    public static function normalize(string $phase): string
    {
        $phase = strtolower(trim($phase));
        if (!in_array($phase, self::all(), true)) {
            throw new InvalidArgumentException('phase must be one of: menu, tables, orders, operational.');
        }

        return $phase;
    }

    public static function classify(array $event): ?string
    {
        if (self::isOrderEvent($event)) {
            return self::ORDERS;
        }

        if (self::isTableEvent($event)) {
            return self::TABLES;
        }

        if (self::isMenuEvent($event)) {
            return self::MENU;
        }

        if (self::isOperationalEvent($event)) {
            return self::OPERATIONAL;
        }

        return null;
    }

    public static function isOperationalEvent(array $event): bool
    {
        $payload = self::payload($event);
        $snapshotType = (string) ($payload['snapshot_type'] ?? '');
        if (in_array($snapshotType, self::operationalSnapshotTypes(), true)) {
            return true;
        }

        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        if ($aggregateType !== '') {
            foreach (OperationalSyncDomains::all() as $definition) {
                $domainAggregate = strtolower(trim((string) ($definition['aggregate_type'] ?? '')));
                if ($domainAggregate !== '' && $domainAggregate === $aggregateType) {
                    return true;
                }
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        $operationalPrefixes = [
            'inventory.',
            'customer.',
            'delivery_',
            'employee.',
            'pulse.',
            'shift_close.',
            'shop_settings.',
            'modifier_group.',
            'moova.',
            'recipe.',
            'production.',
            'order_fulfillment.',
            'payment_method.',
            'table_area.',
            'drawer_',
            'order_event.',
            'manager_approval.',
            'item_unit.',
            'item_availability.',
            'item_variant.',
            'item_modifier_group.',
            'item_nutrition.',
            'printer.',
            'print_job.',
            'store.',
            'town.',
            'category.',
        ];
        foreach ($operationalPrefixes as $prefix) {
            if (strpos($eventType, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function operationalSnapshotTypes(): array
    {
        return [
            'operational_row',
            'operational_delete',
            'recipe_bundle',
            'shop_settings',
            'modifier_group_bundle',
            'moova_shop_link',
            'shift_close',
            'drawer_session_snapshot',
            'drawer_movement_bundle',
            'financial_refund_bundle',
            'customer_bundle',
            'inventory_journal_bundle',
            'inventory_count_bundle',
            'production_batch_bundle',
            'purchase_receipt_bundle',
            'purchase_order_bundle',
        ];
    }

    public static function isOrderEvent(array $event): bool
    {
        if (self::isOperationalPayload(self::payload($event))) {
            return false;
        }

        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        $entityType = strtolower(trim((string) ($event['entity_type'] ?? '')));
        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if ($aggregateType === 'order' || $entityType === 'order' || strpos($eventType, 'order.') === 0) {
            return true;
        }

        $payload = self::payload($event);
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : $payload;

        return self::firstExistingValue([$order, $payload], ['order_uuid', 'pos_order_uuid']) !== null;
    }

    public static function isTableEvent(array $event): bool
    {
        if (self::isOperationalPayload(self::payload($event))) {
            return false;
        }

        foreach (['aggregate_type', 'entity_type'] as $key) {
            if (strtolower(trim((string) ($event[$key] ?? ''))) === 'table') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'table.') === 0 || strpos($eventType, 'tables.') === 0) {
            return true;
        }

        $payload = self::payload($event);

        return array_key_exists('table', $payload)
            || array_key_exists('table_uuid', $payload)
            || array_key_exists('local_table_id', $payload);
    }

    public static function isMenuEvent(array $event): bool
    {
        if (self::isOperationalPayload(self::payload($event))) {
            return false;
        }

        foreach (['aggregate_type', 'entity_type'] as $key) {
            $type = strtolower(trim((string) ($event[$key] ?? '')));
            if ($type === 'menu_item' || $type === 'item') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'menu.') === 0 || strpos($eventType, 'item.') === 0) {
            return true;
        }

        $payload = self::payload($event);

        return array_key_exists('item', $payload)
            || array_key_exists('menu_item', $payload)
            || array_key_exists('local_item_id', $payload)
            || array_key_exists('item_uuid', $payload);
    }

    private static function isOperationalPayload(array $payload): bool
    {
        $snapshotType = (string) ($payload['snapshot_type'] ?? '');

        return in_array($snapshotType, self::operationalSnapshotTypes(), true);
    }

    private static function payload(array $event): array
    {
        $payload = $event['payload'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    private static function firstExistingValue(array $sources, array $keys): ?string
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }

                $value = trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
