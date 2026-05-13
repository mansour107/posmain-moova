<?php

class CloudTableSnapshotService
{
    public function upsertFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        if (!$this->isTableEvent($event)) {
            return null;
        }

        $payload = $this->payload($event);
        $table = $this->tablePayload($payload);
        $tableUuid = $this->tableUuid($event, $payload, $table);
        if ($tableUuid === null) {
            throw new InvalidArgumentException('Table sync event is missing table_uuid or aggregate_uuid.');
        }

        $payloadJson = $this->encodeJson($event);
        $payloadHash = $this->payloadHash($event, $payload);

        $params = [
            $branchUuid,
            $tableUuid,
            $this->intOrNull($this->firstExistingValue([$table, $payload, $event], ['local_table_id', 'table_id', 'id', 'aggregate_local_id', 'entity_local_id'])),
            $this->nullableString($this->firstExistingValue([$table, $payload], ['tname', 'table_name', 'name']), 255),
            $this->intOrNull($this->firstExistingValue([$table, $payload], ['table_case', 'case'])),
            $this->boolInt($this->firstExistingValue([$table, $payload], ['isdeleted', 'deleted'])),
            $this->nullableUuid($this->firstExistingValue([$table, $payload], ['active_order_uuid', 'current_order_uuid', 'order_uuid'])),
            $this->intOrZero($this->firstExistingValue([$table, $payload, $event], ['sync_revision', 'revision', 'event_version'])),
            $payloadHash,
            $payloadJson,
        ];

        $stmt = $conn->prepare("
            INSERT INTO cloud_tables (
                branch_uuid,
                table_uuid,
                local_table_id,
                tname,
                table_case,
                isdeleted,
                active_order_uuid,
                sync_revision,
                payload_hash,
                payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                local_table_id = VALUES(local_table_id),
                tname = VALUES(tname),
                table_case = VALUES(table_case),
                isdeleted = VALUES(isdeleted),
                active_order_uuid = VALUES(active_order_uuid),
                sync_revision = VALUES(sync_revision),
                payload_hash = VALUES(payload_hash),
                payload_json = VALUES(payload_json),
                last_received_at = NOW(6)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $cloudTableId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'cloud_table_id' => $cloudTableId,
            'table_uuid' => $tableUuid,
        ];
    }

    private function isTableEvent(array $event): bool
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

        $payload = $this->payload($event);
        return array_key_exists('table', $payload)
            || array_key_exists('table_uuid', $payload)
            || array_key_exists('local_table_id', $payload);
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function tablePayload(array $payload): array
    {
        if (isset($payload['table']) && is_array($payload['table'])) {
            return $payload['table'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function tableUuid(array $event, array $payload, array $table): ?string
    {
        $value = $this->firstExistingValue(
            [$table, $payload, $event],
            ['table_uuid', 'local_uuid', 'uuid', 'entity_uuid']
        );
        $uuid = $this->nullableUuid($value);
        if ($uuid !== null) {
            return $uuid;
        }

        if (strtolower(trim((string) ($event['aggregate_type'] ?? ''))) === 'table') {
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

    private function boolInt($value): int
    {
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0) {
            return 0;
        }

        return 1;
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud table payload JSON.');
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
