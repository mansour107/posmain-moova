<?php

require_once __DIR__ . '/MasterDataRevisionService.php';
require_once __DIR__ . '/../Financial/UnitPrice.php';

class CloudMenuMasterProjectionService
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

    public function supports(array $event): bool
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $master = is_array($payload['master_data'] ?? null) ? $payload['master_data'] : [];
        return strtolower(trim((string) ($master['aggregate_type'] ?? ''))) === 'menu_item'
            && !empty($master['aggregate_uuid'])
            && is_array($master['fields'] ?? null);
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): array
    {
        if (!$this->supports($event)) {
            throw new InvalidArgumentException('CLOUD_MASTER_MENU_ENVELOPE_REQUIRED');
        }
        $payload = $event['payload'];
        $master = $payload['master_data'];
        $itemUuid = strtolower(trim((string) $master['aggregate_uuid']));
        $actor = is_array($master['actor'] ?? null) ? $master['actor'] : [];
        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
        if ((int) ($actor['user_id'] ?? 0) < 1 || !in_array('menu.edit', $permissions, true)) {
            throw new RuntimeException('CLOUD_MASTER_MENU_ADMIN_AUTH_REQUIRED');
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM cloud_menu_items
            WHERE branch_uuid = ?
              AND item_uuid = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ss', $branchUuid, $itemUuid);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (empty($master['fields'])) {
            return [
                'cloud_menu_item_id' => (int) ($stored['id'] ?? 0),
                'item_uuid' => $itemUuid,
                'stale' => true,
                'reason' => 'no_master_field_change',
            ];
        }

        if ($stored) {
            $this->revisions->seedCurrentValues(
                $conn,
                $branchUuid,
                'menu_item',
                $itemUuid,
                $this->currentValues($stored),
                'cloud:legacy',
                (string) ($stored['last_received_at'] ?? $stored['updated_at'] ?? gmdate('Y-m-d H:i:s')) . 'Z'
            );
        }

        $resolution = $this->revisions->resolve($conn, $branchUuid, $event, self::ALLOWED_FIELDS);
        $winning = $resolution['winning_fields'];
        if (!$winning) {
            return [
                'cloud_menu_item_id' => (int) ($stored['id'] ?? 0),
                'item_uuid' => $itemUuid,
                'stale' => true,
                'master_resolution' => $resolution,
            ];
        }

        foreach ($winning as $field => $value) {
            $this->validateField($conn, (string) $field, $value);
        }
        $values = [
            'item_name' => null,
            'name2' => null,
            'barcode' => null,
            'category_id' => 0,
            'price' => '0.000000',
            'price2' => '0.000000',
            'price3' => '0.000000',
            'is_active' => 1,
            'isdeleted' => 0,
            'preferred_unit_id' => null,
        ];
        $currentMasterValues = $this->revisions->currentValues(
            $conn,
            $branchUuid,
            'menu_item',
            $itemUuid,
            self::ALLOWED_FIELDS
        );
        foreach ($currentMasterValues as $field => $value) {
            $values[$field] = $value;
        }
        if (trim((string) $values['item_name']) === '') {
            throw new RuntimeException('CLOUD_MASTER_MENU_INITIAL_NAME_REQUIRED');
        }

        $menuItem = is_array($payload['menu_item'] ?? null) ? $payload['menu_item'] : [];
        $localItemId = (int) ($payload['local_item_id'] ?? $menuItem['local_item_id'] ?? $menuItem['item_id'] ?? 0);
        $eventVersion = max(1, (int) ($event['event_version'] ?? $menuItem['menu_version'] ?? 1));
        $payloadJson = $this->encodeJson($event);
        $payloadHash = hash('sha256', $payloadJson);
        $availableOnline = !empty($values['is_active']) && empty($values['isdeleted']) ? 1 : 0;
        $params = [
            $branchUuid,
            $itemUuid,
            $localItemId > 0 ? $localItemId : null,
            $values['barcode'],
            $values['item_name'],
            (int) $values['category_id'],
            UnitPrice::from((string) $values['price'])->toString(),
            $availableOnline,
            (int) $values['isdeleted'],
            $eventVersion,
            $payloadHash,
            $payloadJson,
        ];
        $stmt = $conn->prepare("
            INSERT INTO cloud_menu_items (
                branch_uuid, item_uuid, local_item_id, barcode, item_name,
                category_id, price, available_online, isdeleted, menu_version,
                payload_hash, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                local_item_id = COALESCE(VALUES(local_item_id), local_item_id),
                barcode = VALUES(barcode),
                item_name = VALUES(item_name),
                category_id = VALUES(category_id),
                price = VALUES(price),
                available_online = VALUES(available_online),
                isdeleted = VALUES(isdeleted),
                menu_version = GREATEST(menu_version, VALUES(menu_version)),
                payload_hash = VALUES(payload_hash),
                payload_json = VALUES(payload_json),
                last_received_at = NOW(6)
        ");
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return [
            'cloud_menu_item_id' => $id,
            'item_uuid' => $itemUuid,
            'master_resolution' => $resolution,
        ];
    }

    private function currentValues(array $row): array
    {
        return [
            'item_name' => $row['item_name'] === null ? null : (string) $row['item_name'],
            'name2' => null,
            'barcode' => $row['barcode'] === null ? null : (string) $row['barcode'],
            'category_id' => (int) ($row['category_id'] ?? 0),
            'price' => UnitPrice::fromLegacy($row['price'] ?? '0')->toString(),
            'price2' => '0.000000',
            'price3' => '0.000000',
            'is_active' => !empty($row['available_online']) ? 1 : 0,
            'isdeleted' => (int) ($row['isdeleted'] ?? 0),
            'preferred_unit_id' => null,
        ];
    }

    private function validateField(mysqli $conn, string $field, $value): void
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException('CLOUD_MASTER_MENU_FIELD_NOT_ALLOWED:' . $field);
        }
        if ($field === 'item_name') {
            $name = trim((string) $value);
            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException('CLOUD_MASTER_MENU_NAME_INVALID');
            }
        }
        if (in_array($field, ['price', 'price2', 'price3'], true)) {
            if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,6})?$/', $value)) {
                throw new InvalidArgumentException('CLOUD_MASTER_MENU_PRICE_DECIMAL_STRING_REQUIRED');
            }
        }
        if ($field === 'category_id') {
            $categoryId = filter_var($value, FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 0) {
                throw new InvalidArgumentException('CLOUD_MASTER_MENU_CATEGORY_INVALID');
            }
            if ($categoryId > 0 && !$this->rowExists($conn, 'item_group', $categoryId)) {
                throw new RuntimeException('CLOUD_MASTER_MENU_CATEGORY_DEPENDENCY_MISSING');
            }
        }
        if ($field === 'preferred_unit_id' && $value !== null) {
            $unitId = filter_var($value, FILTER_VALIDATE_INT);
            if ($unitId === false || $unitId < 1 || !$this->rowExists($conn, 'myunits', $unitId)) {
                throw new RuntimeException('CLOUD_MASTER_MENU_UNIT_DEPENDENCY_MISSING');
            }
        }
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

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('CLOUD_MASTER_MENU_JSON_INVALID');
        }
        return $json;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [$types];
        foreach ($params as &$value) {
            $refs[] = &$value;
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
