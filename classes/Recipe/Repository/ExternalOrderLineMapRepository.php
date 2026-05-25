<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';

class ExternalOrderLineMapRepository extends RecipeRepositoryBase
{
    private const SOURCE_CHANNELS = ['moova', 'cofe', 'api', 'sync'];
    private const LINE_STATUSES = ['active', 'cancelled', 'changed', 'merged', 'split'];

    public function createMapping(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'external_event_uuid' => null,
            'order_id' => null,
            'fat_detail_id' => null,
            'order_line_uuid' => null,
            'variant_id' => null,
            'modifiers_hash' => null,
            'modifiers_json' => null,
            'line_status' => 'active',
        ];

        $row = $this->normalizeMappingRow(array_merge($defaults, $data));

        return $this->insertRow($conn, 'external_order_line_map', $row);
    }

    public function upsertMapping(mysqli $conn, array $data): int
    {
        $data = $this->normalizeMappingRow(array_merge([
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'external_event_uuid' => null,
            'order_id' => null,
            'fat_detail_id' => null,
            'order_line_uuid' => null,
            'variant_id' => null,
            'modifiers_hash' => null,
            'modifiers_json' => null,
            'line_status' => 'active',
        ], $data));

        $sql = "
INSERT INTO external_order_line_map (
  pos_tenant, pos_branch, branch_uuid, source_channel, external_order_id, external_line_id,
  external_event_uuid, order_id, fat_detail_id, order_line_uuid, item_id, variant_id,
  modifiers_hash, modifiers_json, line_status, idempotency_key
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  id = LAST_INSERT_ID(id),
  branch_uuid = COALESCE(VALUES(branch_uuid), branch_uuid),
  external_event_uuid = COALESCE(VALUES(external_event_uuid), external_event_uuid),
  order_id = COALESCE(VALUES(order_id), order_id),
  fat_detail_id = COALESCE(VALUES(fat_detail_id), fat_detail_id),
  order_line_uuid = COALESCE(VALUES(order_line_uuid), order_line_uuid),
  item_id = VALUES(item_id),
  variant_id = COALESCE(VALUES(variant_id), variant_id),
  modifiers_hash = VALUES(modifiers_hash),
  modifiers_json = VALUES(modifiers_json),
  line_status = VALUES(line_status)";

        $this->executeStatement($conn, $sql, [
            (int) $data['pos_tenant'],
            (int) $data['pos_branch'],
            $data['branch_uuid'],
            (string) $data['source_channel'],
            (string) $data['external_order_id'],
            (string) $data['external_line_id'],
            $data['external_event_uuid'],
            $data['order_id'],
            $data['fat_detail_id'],
            $data['order_line_uuid'],
            (int) $data['item_id'],
            $data['variant_id'],
            $data['modifiers_hash'],
            $data['modifiers_json'],
            (string) $data['line_status'],
            (string) $data['idempotency_key'],
        ]);

        return (int) $conn->insert_id;
    }

    public function findMapping(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        string $sourceChannel,
        string $externalOrderId,
        string $externalLineId
    ): ?array {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM external_order_line_map
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND source_channel = ?
  AND external_order_id = ?
  AND external_line_id = ?
LIMIT 1",
            [$posTenant, $posBranch, $sourceChannel, $externalOrderId, $externalLineId]
        );
    }

    private function normalizeMappingRow(array $row): array
    {
        $row['source_channel'] = trim((string) ($row['source_channel'] ?? ''));
        $row['external_order_id'] = trim((string) ($row['external_order_id'] ?? ''));
        $row['external_line_id'] = trim((string) ($row['external_line_id'] ?? ''));
        $row['idempotency_key'] = trim((string) ($row['idempotency_key'] ?? ''));
        $row['line_status'] = trim((string) ($row['line_status'] ?? 'active'));
        $row['branch_uuid'] = $this->nullableTrimmed($row['branch_uuid'] ?? null, 36, 'branch_uuid');
        $row['external_event_uuid'] = $this->nullableTrimmed($row['external_event_uuid'] ?? null, 128, 'external_event_uuid');
        $row['order_line_uuid'] = $this->nullableTrimmed($row['order_line_uuid'] ?? null, 36, 'order_line_uuid');
        $row['modifiers_hash'] = $this->nullableTrimmed($row['modifiers_hash'] ?? null, 64, 'modifiers_hash');
        $row['modifiers_json'] = $this->nullableJson($row['modifiers_json'] ?? null);
        $this->assertValidMappingRow($row);

        return $row;
    }

    private function assertValidMappingRow(array $row): void
    {
        foreach (['pos_tenant', 'pos_branch'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('External order line ' . $field . ' cannot be negative.');
            }
        }

        if (!in_array((string) ($row['source_channel'] ?? ''), self::SOURCE_CHANNELS, true)) {
            throw new InvalidArgumentException('External order line source_channel is invalid.');
        }
        $requiredMessages = [
            'external_order_id' => 'External order line external_order_id is required.',
            'external_line_id' => 'External order line external_line_id is required.',
        ];
        foreach (['external_order_id', 'external_line_id'] as $field) {
            if ((string) ($row[$field] ?? '') === '') {
                throw new InvalidArgumentException($requiredMessages[$field]);
            }
            if (strlen((string) $row[$field]) > 128) {
                throw new InvalidArgumentException('External order line ' . $field . ' is too long.');
            }
        }
        if ((int) ($row['item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('External order line item_id must be positive.');
        }
        if ((string) ($row['idempotency_key'] ?? '') === '') {
            throw new InvalidArgumentException('External order line idempotency key is required.');
        }
        if (strlen((string) $row['idempotency_key']) > 191) {
            throw new InvalidArgumentException('External order line idempotency key is too long.');
        }
        if (!in_array((string) ($row['line_status'] ?? ''), self::LINE_STATUSES, true)) {
            throw new InvalidArgumentException('External order line line_status is invalid.');
        }

        foreach (['order_id', 'fat_detail_id', 'variant_id'] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                continue;
            }
            if ((int) $row[$field] < 1) {
                throw new InvalidArgumentException('External order line ' . $field . ' must be positive when provided.');
            }
        }

        if ($row['modifiers_hash'] !== null && preg_match('/^[a-fA-F0-9]{64}$/', (string) $row['modifiers_hash']) !== 1) {
            throw new InvalidArgumentException('External order line modifiers_hash must be a sha256 hex digest when provided.');
        }
    }

    private function nullableTrimmed($value, int $maxLength, string $field): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxLength) {
            throw new InvalidArgumentException('External order line ' . $field . ' is too long.');
        }

        return $text;
    }

    private function nullableJson($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $json = trim((string) $value);
        if ($json === '') {
            return null;
        }

        json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('External order line modifiers_json must be valid JSON when provided.');
        }

        return $json;
    }
}
