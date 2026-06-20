<?php

class RestoreEventPhase
{
    public const MENU = 'menu';
    public const TABLES = 'tables';
    public const ORDERS = 'orders';

    public static function all(): array
    {
        return [
            self::MENU,
            self::TABLES,
            self::ORDERS,
        ];
    }

    public static function normalize(string $phase): string
    {
        $phase = strtolower(trim($phase));
        if (!in_array($phase, self::all(), true)) {
            throw new InvalidArgumentException('phase must be one of: menu, tables, orders.');
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

        return null;
    }

    public static function isOrderEvent(array $event): bool
    {
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
