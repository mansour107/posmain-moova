<?php

class MasterDataRevisionService
{
    public function resolve(
        mysqli $conn,
        string $branchUuid,
        array $event,
        array $allowedFields
    ): array {
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
        $master = isset($payload['master_data']) && is_array($payload['master_data'])
            ? $payload['master_data']
            : [];
        $aggregateType = strtolower(trim((string) ($master['aggregate_type'] ?? '')));
        $aggregateUuid = strtolower(trim((string) ($master['aggregate_uuid'] ?? '')));
        $sourceNodeId = trim((string) ($master['source_node_id'] ?? $payload['source_node_id'] ?? ''));
        $sourceSystem = substr(trim((string) ($event['source_system'] ?? $payload['source_system'] ?? 'cloud_pos')), 0, 40);
        $actor = isset($master['actor']) && is_array($master['actor'])
            ? $master['actor']
            : (isset($payload['actor']) && is_array($payload['actor']) ? $payload['actor'] : []);
        $actorUserId = (int) ($actor['user_id'] ?? $actor['actor_user_id'] ?? 0);
        $fields = isset($master['fields']) && is_array($master['fields']) ? $master['fields'] : [];
        $eventUuid = $this->nullableUuid($event['event_uuid'] ?? null);

        $this->assertUuid($branchUuid, 'MASTER_BRANCH_UUID_INVALID');
        $this->assertUuid($aggregateUuid, 'MASTER_AGGREGATE_UUID_INVALID');
        if ($aggregateType === '' || $sourceNodeId === '' || strlen($sourceNodeId) > 100 || !$fields) {
            throw new InvalidArgumentException('MASTER_REVISION_ENVELOPE_INVALID');
        }

        $allowed = array_fill_keys(array_values(array_map('strval', $allowedFields)), true);
        ksort($fields, SORT_STRING);
        $winning = [];
        $ignored = [];
        $duplicates = [];

        foreach ($fields as $fieldName => $candidate) {
            $fieldName = (string) $fieldName;
            if (!isset($allowed[$fieldName]) || !is_array($candidate)) {
                throw new InvalidArgumentException('MASTER_FIELD_NOT_ALLOWED:' . $fieldName);
            }

            $revisionUuid = strtolower(trim((string) ($candidate['revision_uuid'] ?? '')));
            $this->assertUuid($revisionUuid, 'MASTER_FIELD_REVISION_UUID_INVALID:' . $fieldName);
            $changedAt = $this->normalizeTimestamp($candidate['changed_at_utc'] ?? null);
            $valueJson = $this->encodeJson($candidate['value'] ?? null);
            $existing = $this->findStateForUpdate(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName
            );

            $outcome = 'accepted';
            $reason = null;
            if ($existing !== null && !$this->candidateWins($changedAt, $sourceNodeId, $existing)) {
                $outcome = 'ignored';
                $reason = 'older_or_tie_break_loser';
            }

            $inserted = $this->insertHistory(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName,
                $valueJson,
                $changedAt,
                $sourceNodeId,
                $revisionUuid,
                $actorUserId > 0 ? $actorUserId : null,
                $sourceSystem,
                $eventUuid,
                $outcome,
                $reason
            );
            if (!$inserted) {
                $this->assertHistoryReplayMatches(
                    $conn,
                    $branchUuid,
                    $revisionUuid,
                    $fieldName,
                    $valueJson,
                    $changedAt,
                    $sourceNodeId
                );
                $duplicates[] = $fieldName;
                continue;
            }

            if ($outcome === 'accepted') {
                $this->putState(
                    $conn,
                    $branchUuid,
                    $aggregateType,
                    $aggregateUuid,
                    $fieldName,
                    $valueJson,
                    $changedAt,
                    $sourceNodeId,
                    $revisionUuid,
                    $actorUserId > 0 ? $actorUserId : null,
                    $sourceSystem
                );
                $winning[$fieldName] = $candidate['value'] ?? null;
            } else {
                $ignored[] = $fieldName;
            }
        }

        return [
            'aggregate_type' => $aggregateType,
            'aggregate_uuid' => $aggregateUuid,
            'winning_fields' => $winning,
            'ignored_fields' => $ignored,
            'duplicate_fields' => $duplicates,
        ];
    }

    public function captureLocalPatch(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        array $values,
        string $sourceNodeId,
        int $actorUserId,
        string $sourceSystem
    ): array {
        $this->assertUuid($branchUuid, 'MASTER_BRANCH_UUID_INVALID');
        $this->assertUuid($aggregateUuid, 'MASTER_AGGREGATE_UUID_INVALID');
        if ($sourceNodeId === '' || strlen($sourceNodeId) > 100 || $actorUserId < 1) {
            throw new InvalidArgumentException('MASTER_LOCAL_ACTOR_OR_NODE_INVALID');
        }

        ksort($values, SORT_STRING);
        $fields = [];
        foreach ($values as $fieldName => $value) {
            $fieldName = (string) $fieldName;
            $valueJson = $this->encodeJson($value);
            $existing = $this->findStateForUpdate(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName
            );
            if ($existing !== null && hash_equals((string) $existing['value_json'], $valueJson)) {
                continue;
            }

            $changedAt = $this->timestampAfter($existing['changed_at_utc'] ?? null);
            $revisionUuid = $this->uuid();
            $this->insertHistory(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName,
                $valueJson,
                $changedAt,
                $sourceNodeId,
                $revisionUuid,
                $actorUserId,
                substr($sourceSystem, 0, 40),
                null,
                'accepted',
                'local_change'
            );
            $this->putState(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName,
                $valueJson,
                $changedAt,
                $sourceNodeId,
                $revisionUuid,
                $actorUserId,
                substr($sourceSystem, 0, 40)
            );
            $fields[$fieldName] = [
                'value' => $value,
                'changed_at_utc' => str_replace(' ', 'T', $changedAt) . 'Z',
                'revision_uuid' => $revisionUuid,
            ];
        }

        return [
            'schema_version' => 1,
            'aggregate_type' => $aggregateType,
            'aggregate_uuid' => strtolower($aggregateUuid),
            'source_node_id' => $sourceNodeId,
            'origin_clock_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'actor' => [
                'user_id' => $actorUserId,
            ],
            'fields' => $fields,
        ];
    }

    public function seedCurrentValues(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        array $values,
        string $sourceNodeId,
        string $changedAtUtc,
        string $sourceSystem = 'local_legacy'
    ): void {
        $changedAt = $this->normalizeTimestamp($changedAtUtc);
        ksort($values, SORT_STRING);
        foreach ($values as $fieldName => $value) {
            $fieldName = (string) $fieldName;
            $existing = $this->findStateForUpdate(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName
            );
            if ($existing !== null) {
                continue;
            }

            $valueJson = $this->encodeJson($value);
            $revisionUuid = $this->uuid();
            $this->insertHistory(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName,
                $valueJson,
                $changedAt,
                $sourceNodeId,
                $revisionUuid,
                null,
                $sourceSystem,
                null,
                'local_seed',
                'legacy_compatibility_seed'
            );
            $this->putState(
                $conn,
                $branchUuid,
                $aggregateType,
                $aggregateUuid,
                $fieldName,
                $valueJson,
                $changedAt,
                $sourceNodeId,
                $revisionUuid,
                null,
                $sourceSystem
            );
        }
    }

    /**
     * Returns the currently selected value for each requested master field.
     *
     * Callers use this after resolve() while their surrounding transaction is
     * still open so a partial field event can materialize a complete projection
     * without falling back to a stale whole-row payload.
     */
    public function currentValues(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        array $allowedFields
    ): array {
        $this->assertUuid($branchUuid, 'MASTER_BRANCH_UUID_INVALID');
        $this->assertUuid($aggregateUuid, 'MASTER_AGGREGATE_UUID_INVALID');
        $allowed = array_fill_keys(array_values(array_map('strval', $allowedFields)), true);

        $stmt = $conn->prepare("
            SELECT field_name, value_json
            FROM sync_master_field_state
            WHERE branch_uuid = ?
              AND aggregate_type = ?
              AND aggregate_uuid = ?
            ORDER BY field_name ASC
        ");
        $stmt->bind_param('sss', $branchUuid, $aggregateType, $aggregateUuid);
        $stmt->execute();
        $rows = $stmt->get_result();
        $values = [];
        while ($row = $rows->fetch_assoc()) {
            $fieldName = (string) ($row['field_name'] ?? '');
            if (!isset($allowed[$fieldName])) {
                continue;
            }
            $values[$fieldName] = $this->decodeJson((string) ($row['value_json'] ?? 'null'));
        }
        $stmt->close();

        return $values;
    }

    private function candidateWins(string $changedAt, string $sourceNodeId, array $existing): bool
    {
        $timeComparison = strcmp($changedAt, (string) $existing['changed_at_utc']);
        if ($timeComparison !== 0) {
            return $timeComparison > 0;
        }

        return strcmp($sourceNodeId, (string) $existing['source_node_id']) > 0;
    }

    private function findStateForUpdate(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        string $fieldName
    ): ?array {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_master_field_state
            WHERE branch_uuid = ?
              AND aggregate_type = ?
              AND aggregate_uuid = ?
              AND field_name = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ssss', $branchUuid, $aggregateType, $aggregateUuid, $fieldName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function insertHistory(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        string $fieldName,
        string $valueJson,
        string $changedAt,
        string $sourceNodeId,
        string $revisionUuid,
        ?int $actorUserId,
        string $sourceSystem,
        ?string $eventUuid,
        string $outcome,
        ?string $reason
    ): bool {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO sync_master_field_history (
                branch_uuid, aggregate_type, aggregate_uuid, field_name,
                value_json, changed_at_utc, source_node_id, revision_uuid,
                actor_user_id, source_system, event_uuid, outcome, reason
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssssssssissss',
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $fieldName,
            $valueJson,
            $changedAt,
            $sourceNodeId,
            $revisionUuid,
            $actorUserId,
            $sourceSystem,
            $eventUuid,
            $outcome,
            $reason
        );
        $stmt->execute();
        $inserted = $stmt->affected_rows === 1;
        $stmt->close();
        return $inserted;
    }

    private function putState(
        mysqli $conn,
        string $branchUuid,
        string $aggregateType,
        string $aggregateUuid,
        string $fieldName,
        string $valueJson,
        string $changedAt,
        string $sourceNodeId,
        string $revisionUuid,
        ?int $actorUserId,
        string $sourceSystem
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO sync_master_field_state (
                branch_uuid, aggregate_type, aggregate_uuid, field_name,
                value_json, changed_at_utc, source_node_id, revision_uuid,
                actor_user_id, source_system
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                value_json = VALUES(value_json),
                changed_at_utc = VALUES(changed_at_utc),
                source_node_id = VALUES(source_node_id),
                revision_uuid = VALUES(revision_uuid),
                actor_user_id = VALUES(actor_user_id),
                source_system = VALUES(source_system)
        ");
        $stmt->bind_param(
            'ssssssssss',
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $fieldName,
            $valueJson,
            $changedAt,
            $sourceNodeId,
            $revisionUuid,
            $actorUserId,
            $sourceSystem
        );
        $stmt->execute();
        $stmt->close();
    }

    private function assertHistoryReplayMatches(
        mysqli $conn,
        string $branchUuid,
        string $revisionUuid,
        string $fieldName,
        string $valueJson,
        string $changedAt,
        string $sourceNodeId
    ): void {
        $stmt = $conn->prepare("
            SELECT value_json, changed_at_utc, source_node_id
            FROM sync_master_field_history
            WHERE branch_uuid = ?
              AND revision_uuid = ?
              AND field_name = ?
            LIMIT 1
        ");
        $stmt->bind_param('sss', $branchUuid, $revisionUuid, $fieldName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (
            !$row
            || !hash_equals((string) $row['value_json'], $valueJson)
            || (string) $row['changed_at_utc'] !== $changedAt
            || (string) $row['source_node_id'] !== $sourceNodeId
        ) {
            throw new RuntimeException('MASTER_REVISION_UUID_COLLISION');
        }
    }

    private function normalizeTimestamp($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('MASTER_FIELD_TIMESTAMP_REQUIRED');
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new InvalidArgumentException('MASTER_FIELD_TIMESTAMP_INVALID');
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestampAfter($minimum): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($minimum !== null && $minimum !== '') {
            $min = new DateTimeImmutable((string) $minimum, new DateTimeZone('UTC'));
            if ($now <= $min) {
                $now = $min->modify('+1 microsecond');
            }
        }
        return $now->format('Y-m-d H:i:s.u');
    }

    private function assertUuid(string $value, string $error): void
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', strtolower($value))) {
            throw new InvalidArgumentException($error);
        }
    }

    private function nullableUuid($value): ?string
    {
        $value = strtolower(trim((string) $value));
        return preg_match('/^[a-f0-9-]{36}$/', $value) ? $value : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new InvalidArgumentException('MASTER_FIELD_JSON_INVALID');
        }
        return $json;
    }

    private function decodeJson(string $json)
    {
        $value = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('MASTER_STATE_JSON_INVALID');
        }
        return $value;
    }
}
