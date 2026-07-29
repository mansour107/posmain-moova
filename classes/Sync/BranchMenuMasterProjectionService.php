<?php

require_once __DIR__ . '/MasterDataRevisionService.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../Financial/UnitPrice.php';

class BranchMenuMasterProjectionService
{
    private const ALLOWED_FIELDS = [
        'item_name',
        'name2',
        'barcode',
        'category_id',
        'price',
        'price2',
        'price3',
        'is_active',
        'isdeleted',
        'preferred_unit_id',
    ];

    private MasterDataRevisionService $revisions;

    public function __construct(?MasterDataRevisionService $revisions = null)
    {
        $this->revisions = $revisions ?: new MasterDataRevisionService();
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $master = is_array($payload['master_data'] ?? null) ? $payload['master_data'] : [];
        $item = is_array($payload['menu_item'] ?? null) ? $payload['menu_item'] : [];
        $itemId = (int) ($payload['local_item_id'] ?? $item['local_item_id'] ?? $item['item_id'] ?? 0);
        if ($itemId < 1) {
            throw new InvalidArgumentException('MASTER_MENU_ITEM_ID_REQUIRED');
        }

        $aggregateUuid = strtolower(trim((string) ($master['aggregate_uuid'] ?? '')));
        $expectedUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'myitems:' . $itemId);
        if (!hash_equals($expectedUuid, $aggregateUuid)) {
            throw new InvalidArgumentException('MASTER_MENU_ITEM_UUID_MISMATCH');
        }

        $stmt = $conn->prepare('SELECT * FROM myitems WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$stored) {
            throw new RuntimeException('MASTER_MENU_ITEM_NOT_FOUND');
        }

        $current = $this->currentValues($stored);
        $legacyChangedAt = trim((string) ($stored['mdtime'] ?? $stored['crtime'] ?? ''));
        if ($legacyChangedAt === '') {
            $legacyChangedAt = gmdate('Y-m-d H:i:s');
        }
        $this->revisions->seedCurrentValues(
            $conn,
            $branchUuid,
            'menu_item',
            $aggregateUuid,
            $current,
            'branch:' . $branchUuid,
            $legacyChangedAt . 'Z'
        );

        $resolution = $this->revisions->resolve($conn, $branchUuid, $event, self::ALLOWED_FIELDS);
        $winning = $resolution['winning_fields'];
        if (!$winning) {
            return [
                'legacy_entity_id' => 'myitems:' . $itemId,
                'stale' => true,
                'reason' => 'all_master_fields_older_or_duplicate',
                'master_resolution' => $resolution,
            ];
        }

        $assignments = [];
        $params = [];
        foreach ($winning as $field => $value) {
            [$column, $normalized] = $this->validatedColumnValue($conn, $field, $value);
            $assignments[] = "`{$column}` = ?";
            $params[] = $normalized;
        }
        $assignments[] = 'mdtime = CURRENT_TIMESTAMP';
        $params[] = $itemId;

        $sql = 'UPDATE myitems SET ' . implode(', ', $assignments) . ' WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $stmt->close();

        return [
            'legacy_entity_id' => 'myitems:' . $itemId,
            'master_resolution' => $resolution,
        ];
    }

    private function currentValues(array $row): array
    {
        return [
            'item_name' => (string) ($row['iname'] ?? ''),
            'name2' => $row['name2'] === null ? null : (string) $row['name2'],
            'barcode' => $row['barcode'] === null ? null : (string) $row['barcode'],
            'category_id' => (int) ($row['group1'] ?? 0),
            'price' => UnitPrice::fromLegacy($row['price1'] ?? '0')->toString(),
            'price2' => UnitPrice::fromLegacy($row['price2'] ?? '0')->toString(),
            'price3' => UnitPrice::fromLegacy($row['price3'] ?? '0')->toString(),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            'preferred_unit_id' => isset($row['preferred_unit_id']) ? (int) $row['preferred_unit_id'] : null,
        ];
    }

    private function validatedColumnValue(mysqli $conn, string $field, $value): array
    {
        if ($field === 'item_name') {
            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException('MASTER_MENU_ITEM_NAME_INVALID');
            }
            return ['iname', $value];
        }
        if ($field === 'name2') {
            $value = $value === null ? null : trim((string) $value);
            if ($value !== null && strlen($value) > 200) {
                throw new InvalidArgumentException('MASTER_MENU_ITEM_SECOND_NAME_INVALID');
            }
            return ['name2', $value];
        }
        if ($field === 'barcode') {
            $value = $value === null ? null : trim((string) $value);
            if ($value !== null && strlen($value) > 25) {
                throw new InvalidArgumentException('MASTER_MENU_ITEM_BARCODE_INVALID');
            }
            return ['barcode', $value];
        }
        if (in_array($field, ['price', 'price2', 'price3'], true)) {
            if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,6})?$/', $value)) {
                throw new InvalidArgumentException('MASTER_MENU_PRICE_DECIMAL_STRING_REQUIRED');
            }
            $column = $field === 'price' ? 'price1' : $field;
            return [$column, UnitPrice::from($value)->toString()];
        }
        if ($field === 'category_id') {
            $categoryId = filter_var($value, FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 0) {
                throw new InvalidArgumentException('MASTER_MENU_CATEGORY_INVALID');
            }
            if ($categoryId > 0 && !$this->rowExists($conn, 'item_group', $categoryId)) {
                throw new RuntimeException('MASTER_MENU_CATEGORY_DEPENDENCY_MISSING');
            }
            return ['group1', $categoryId];
        }
        if ($field === 'preferred_unit_id') {
            if ($value === null || $value === 0 || $value === '0') {
                return ['preferred_unit_id', null];
            }
            $unitId = filter_var($value, FILTER_VALIDATE_INT);
            if ($unitId === false || $unitId < 1 || !$this->rowExists($conn, 'myunits', $unitId)) {
                throw new RuntimeException('MASTER_MENU_UNIT_DEPENDENCY_MISSING');
            }
            return ['preferred_unit_id', $unitId];
        }
        if ($field === 'is_active' || $field === 'isdeleted') {
            if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                throw new InvalidArgumentException('MASTER_MENU_BOOLEAN_INVALID:' . $field);
            }
            return [$field, (int) (bool) $value];
        }

        throw new InvalidArgumentException('MASTER_MENU_FIELD_NOT_ALLOWED:' . $field);
    }

    private function rowExists(mysqli $conn, string $table, int $id): bool
    {
        $stmt = $conn->prepare("SELECT id FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [$types];
        foreach ($params as $index => &$value) {
            $refs[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
