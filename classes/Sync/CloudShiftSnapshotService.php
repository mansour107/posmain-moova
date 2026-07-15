<?php

require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class CloudShiftSnapshotService
{
    public function upsertFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        if (!$this->isShiftEvent($event)) {
            return null;
        }

        $payload = $this->payload($event);
        $shift = $this->shiftPayload($payload);
        $closeUuid = $this->closeUuid($branchUuid, $event, $payload, $shift);
        if ($closeUuid === null) {
            throw new InvalidArgumentException('Shift sync event is missing close_uuid or aggregate_uuid.');
        }

        $payloadJson = $this->encodeJson($event);
        $payloadHash = $this->payloadHash($event, $payload);
        // Payload version, not the transport event version, defines the close
        // identifier contract. Older v1 events may still have event_version=2.
        $schemaVersion = (int) ($payload['schema_version'] ?? 1);
        $legacyCloseId = $schemaVersion >= 2
            ? null
            : $this->intOrNull($this->firstExistingValue(
                [$shift, $payload, $event],
                ['local_closed_order_id', 'closed_order_id', 'id', 'aggregate_local_id', 'entity_local_id']
            ));

        $params = [
            $branchUuid,
            $closeUuid,
            $legacyCloseId,
            $this->intOrNull($this->firstExistingValue([$shift, $payload, $event], ['local_drawer_session_id', 'drawer_session_id', 'aggregate_local_id'])),
            $this->intOrNull($this->firstExistingValue([$shift, $payload], ['cashier_user_id', 'user', 'user_id'])),
            $this->nullableString($this->firstExistingValue([$shift, $payload], ['shift_number', 'shift']), 100),
            $this->datetimeOrNull($this->firstExistingValue([$shift, $payload], ['opened_at', 'strttime', 'start_time'])),
            $this->datetimeOrNull($this->firstExistingValue([$shift, $payload], ['closed_at', 'endtime', 'end_time', 'date'])),
            $this->branchTimezone($shift, $payload, $event),
            $this->decimal($this->firstExistingValue([$shift, $payload], ['total_sales', 'sales_total'])),
            $this->decimal($this->firstExistingValue([$shift, $payload], ['total_cash', 'cash_total'])),
            $this->decimal($this->firstExistingValue([$shift, $payload], ['total_card', 'card_total'])),
            $this->decimalOrNull($this->firstExistingValue([$shift, $payload], ['actual_cash', 'cash'])),
            $this->decimalOrNull($this->firstExistingValue([$shift, $payload], ['actual_card', 'card'])),
            $this->decimalOrNull($this->firstExistingValue([$shift, $payload], ['cash_deficit', 'cash_difference'])),
            $this->decimalOrNull($this->firstExistingValue([$shift, $payload], ['card_deficit', 'card_difference'])),
            $payloadHash,
            $payloadJson,
        ];

        $stmt = $conn->prepare("
            INSERT INTO cloud_shifts (
                branch_uuid,
                close_uuid,
                local_closed_order_id,
                local_drawer_session_id,
                cashier_user_id,
                shift_number,
                opened_at,
                closed_at,
                branch_timezone,
                total_sales,
                total_cash,
                total_card,
                actual_cash,
                actual_card,
                cash_deficit,
                card_deficit,
                payload_hash,
                payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                local_closed_order_id = VALUES(local_closed_order_id),
                local_drawer_session_id = VALUES(local_drawer_session_id),
                cashier_user_id = VALUES(cashier_user_id),
                shift_number = VALUES(shift_number),
                opened_at = VALUES(opened_at),
                closed_at = VALUES(closed_at),
                branch_timezone = VALUES(branch_timezone),
                total_sales = VALUES(total_sales),
                total_cash = VALUES(total_cash),
                total_card = VALUES(total_card),
                actual_cash = VALUES(actual_cash),
                actual_card = VALUES(actual_card),
                cash_deficit = VALUES(cash_deficit),
                card_deficit = VALUES(card_deficit),
                payload_hash = VALUES(payload_hash),
                payload_json = VALUES(payload_json),
                last_received_at = NOW(6)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $cloudShiftId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'cloud_shift_id' => $cloudShiftId,
            'close_uuid' => $closeUuid,
        ];
    }

    private function isShiftEvent(array $event): bool
    {
        foreach (['aggregate_type', 'entity_type'] as $key) {
            $type = strtolower(trim((string) ($event[$key] ?? '')));
            if ($type === 'shift' || $type === 'shift_close') {
                return true;
            }
        }

        $eventType = strtolower(trim((string) ($event['event_type'] ?? '')));
        if (strpos($eventType, 'shift.') === 0 || strpos($eventType, 'shift_close.') === 0) {
            return true;
        }

        $payload = $this->payload($event);
        return array_key_exists('shift', $payload)
            || array_key_exists('close_uuid', $payload)
            || array_key_exists('local_drawer_session_id', $payload)
            || array_key_exists('local_closed_order_id', $payload);
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function shiftPayload(array $payload): array
    {
        if (isset($payload['shift']) && is_array($payload['shift'])) {
            return $payload['shift'];
        }

        if (isset($payload['snapshot']) && is_array($payload['snapshot'])) {
            return $payload['snapshot'];
        }

        return $payload;
    }

    private function closeUuid(string $branchUuid, array $event, array $payload, array $shift): ?string
    {
        $value = $this->firstExistingValue(
            [$shift, $payload, $event],
            ['close_uuid', 'shift_close_uuid', 'shift_uuid', 'local_uuid', 'uuid', 'entity_uuid']
        );
        $uuid = $this->nullableUuid($value);
        if ($uuid !== null) {
            return $uuid;
        }

        $aggregateType = strtolower(trim((string) ($event['aggregate_type'] ?? '')));
        if ($aggregateType === 'shift' || $aggregateType === 'shift_close') {
            $aggregateUuid = $this->nullableUuid($event['aggregate_uuid'] ?? null);
            if ($aggregateUuid !== null) {
                return $aggregateUuid;
            }
        }

        // Compatibility for already-queued v1 close events written before
        // close_uuid became mandatory. The seed mirrors the historical writer,
        // so retries and separate events for the same legacy close converge.
        $legacyCloseId = $this->intOrNull($this->firstExistingValue(
            [$shift, $payload, $event],
            ['local_closed_order_id', 'closed_order_id', 'entity_local_id', 'aggregate_local_id', 'id']
        ));
        if ($legacyCloseId !== null && $legacyCloseId > 0) {
            return PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'closed_orders:' . $legacyCloseId);
        }

        $drawerIdentity = trim((string) $this->firstExistingValue(
            [$shift, $payload, $event],
            ['drawer_session_uuid', 'local_drawer_session_id', 'drawer_session_id']
        ));
        if ($drawerIdentity !== '') {
            return PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'drawer_sessions:' . $drawerIdentity . ':close');
        }

        $eventIdentity = trim((string) ($event['event_uuid'] ?? $event['idempotency_key'] ?? ''));
        return $eventIdentity === ''
            ? null
            : PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'shift_close_event:' . $eventIdentity);
    }

    private function branchTimezone(array $shift, array $payload, array $event): string
    {
        $timezone = $this->nullableString(
            $this->firstExistingValue([$shift, $payload, $event], ['branch_timezone', 'timezone']),
            100
        );

        return $timezone === null ? 'Africa/Cairo' : $timezone;
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

    private function decimal($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function decimalOrNull($value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function datetimeOrNull($value): ?string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud shift payload JSON.');
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
