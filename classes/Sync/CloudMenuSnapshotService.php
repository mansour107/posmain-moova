<?php

class CloudMenuSnapshotService
{
    public function upsertFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        if (!$this->isMenuEvent($event)) {
            return null;
        }

        $payload = $this->payload($event);
        $item = $this->itemPayload($payload);
        $itemUuid = $this->itemUuid($event, $payload, $item);
        if ($itemUuid === null) {
            throw new InvalidArgumentException('Menu sync event is missing item_uuid or aggregate_uuid.');
        }

        $payloadJson = $this->encodeJson($event);
        $payloadHash = $this->payloadHash($event, $payload);

        $params = [
            $branchUuid,
            $itemUuid,
            $this->intOrNull($this->firstExistingValue([$item, $payload, $event], ['local_item_id', 'item_id', 'id', 'aggregate_local_id', 'entity_local_id'])),
            $this->nullableString($this->firstExistingValue([$item, $payload], ['external_item_id', 'moova_item_id', 'source_external_id']), 191),
            $this->nullableString($this->firstExistingValue([$item, $payload], ['barcode', 'bar_code']), 191),
            $this->nullableString($this->firstExistingValue([$item, $payload], ['item_name', 'name', 'title']), 255),
            $this->intOrNull($this->firstExistingValue([$item, $payload], ['category_id', 'cat_id', 'group_id'])),
            $this->decimalOrNull($this->firstExistingValue([$item, $payload], ['price', 'sale_price'])),
            $this->decimalOrNull($this->firstExistingValue([$item, $payload], ['cost', 'cost_price'])),
            $this->boolInt($this->firstExistingValue([$item, $payload], ['available_online', 'available', 'is_available']), 1),
            $this->boolInt($this->firstExistingValue([$item, $payload], ['isdeleted', 'deleted']), 0),
            $this->intOrZero($this->firstExistingValue([$item, $payload, $event], ['menu_version', 'sync_revision', 'revision', 'event_version'])),
            $payloadHash,
            $payloadJson,
        ];

        $stmt = $conn->prepare("
            INSERT INTO cloud_menu_items (
                branch_uuid,
                item_uuid,
                local_item_id,
                external_item_id,
                barcode,
                item_name,
                category_id,
                price,
                cost,
                available_online,
                isdeleted,
                menu_version,
                payload_hash,
                payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                local_item_id = VALUES(local_item_id),
                external_item_id = VALUES(external_item_id),
                barcode = VALUES(barcode),
                item_name = VALUES(item_name),
                category_id = VALUES(category_id),
                price = VALUES(price),
                cost = VALUES(cost),
                available_online = VALUES(available_online),
                isdeleted = VALUES(isdeleted),
                menu_version = VALUES(menu_version),
                payload_hash = VALUES(payload_hash),
                payload_json = VALUES(payload_json),
                last_received_at = NOW(6)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $cloudMenuItemId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'cloud_menu_item_id' => $cloudMenuItemId,
            'item_uuid' => $itemUuid,
        ];
    }

    private function isMenuEvent(array $event): bool
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

        $payload = $this->payload($event);
        return array_key_exists('menu_item', $payload)
            || array_key_exists('item', $payload)
            || array_key_exists('item_uuid', $payload);
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function itemPayload(array $payload): array
    {
        if (isset($payload['menu_item']) && is_array($payload['menu_item'])) {
            return $payload['menu_item'];
        }

        if (isset($payload['item']) && is_array($payload['item'])) {
            return $payload['item'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function itemUuid(array $event, array $payload, array $item): ?string
    {
        $value = $this->firstExistingValue(
            [$item, $payload, $event],
            ['item_uuid', 'menu_item_uuid', 'local_uuid', 'uuid', 'entity_uuid']
        );
        $uuid = $this->nullableUuid($value);
        if ($uuid !== null) {
            return $uuid;
        }

        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        if ($aggregateType === 'menu_item' || $aggregateType === 'item') {
            return $this->nullableUuid($event['aggregate_uuid'] ?? null);
        }

        return null;
    }

    private function payloadHash(array $event, array $payload): string
    {
        $hash = trim((string) ($event['payload_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        return hash('sha256', $this->encodeJson($payload));
    }

    private function firstExistingValue(array $sources, array $keys)
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $source)) {
                    return $source[$key];
                }
            }
        }

        return null;
    }

    private function nullableString($value, int $maxLength): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function nullableUuid($value): ?string
    {
        $value = $this->nullableString($value, 36);
        if ($value === null) {
            return null;
        }

        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value)
            ? strtolower($value)
            : null;
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function intOrZero($value): int
    {
        $int = $this->intOrNull($value);
        return $int === null ? 0 : $int;
    }

    private function boolInt($value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if ($value === false || $value === '0' || $value === 0) {
            return 0;
        }

        return 1;
    }

    private function decimalOrNull($value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud menu payload JSON.');
        }

        return $json;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }
}
