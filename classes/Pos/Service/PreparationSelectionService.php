<?php

/**
 * Preparation fields are operational instructions attached to an order line.
 * They deliberately do not create a sellable item, modifier, price delta, or
 * recipe line.  Sugar spoons is the first field; the table shape is generic
 * enough for future zero-price preparation counters.
 */
class PreparationSelectionService
{
    public const SUGAR_SPOONS = 'sugar_spoons';
    /** Invisible abuse/corruption guard; this is not an operational cashier limit. */
    public const SUGAR_SPOONS_SAFETY_LIMIT = 999;

    private array $tableCache = [];

    public function isEnabled(array $context = []): bool
    {
        if (array_key_exists('preparation_fields_enabled', $context)) {
            return (bool) $context['preparation_fields_enabled'];
        }
        $config = is_array($context['config'] ?? null)
            ? $context['config']
            : (function_exists('posmain_app_config') ? posmain_app_config() : []);

        return !empty($config['features']['preparation_fields']);
    }

    /** @return array<int, array<string, mixed>> */
    public function fieldsForItem(mysqli $conn, int $itemId, array $context = []): array
    {
        if ($itemId < 1 || !$this->isEnabled($context) || !$this->tableExists($conn, 'item_preparation_configs')) {
            return [];
        }

        $configItemId = $this->configOwnerItemId($conn, $itemId);
        $stmt = $conn->prepare("\n            SELECT item_id, field_code, label_ar, label_en, max_value, requires_explicit_value,\n                   inventory_item_id, inventory_qty_per_value\n            FROM item_preparation_configs\n            WHERE item_id = ? AND is_active = 1\n            ORDER BY sort_order, id\n        ");
        $stmt->bind_param('i', $configItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[] = $this->publicField($row);
        }
        $stmt->close();

        if (!$fields && $this->tableExists($conn, 'item_group_preparation_configs')) {
            $groupId = $this->itemGroupId($conn, $configItemId);
            if ($groupId > 0) {
                $stmt = $conn->prepare("\n                    SELECT item_group_id AS item_id, field_code, label_ar, label_en, max_value,
                           requires_explicit_value, NULL AS inventory_item_id,
                           '0.000000' AS inventory_qty_per_value
                    FROM item_group_preparation_configs
                    WHERE item_group_id = ? AND is_active = 1
                    ORDER BY sort_order, id
                ");
                $stmt->bind_param('i', $groupId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $fields[] = $this->publicField($row);
                }
                $stmt->close();
            }
        }

        return $fields;
    }

    public function itemAllowsSugarSpoons(mysqli $conn, int $itemId, array $context = []): bool
    {
        foreach ($this->fieldsForItem($conn, $itemId, $context) as $field) {
            if (($field['code'] ?? '') === self::SUGAR_SPOONS) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,array<string,mixed>> $items */
    public function decorateItems(mysqli $conn, array $items, array $context = []): array
    {
        if (!$this->isEnabled($context) || !$items) {
            foreach ($items as &$item) {
                $item['allows_sugar_spoons'] = false;
            }
            unset($item);
            return $items;
        }

        $itemIds = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int) ($item['id'] ?? $item['item_id'] ?? 0),
            $items
        ))));
        $ownerIds = array_fill_keys($itemIds, 0);
        foreach ($itemIds as $itemId) {
            $ownerIds[$itemId] = $itemId;
        }
        if ($itemIds && $this->tableExists($conn, 'item_variants')) {
            $sql = 'SELECT variant_item_id, parent_item_id FROM item_variants WHERE is_active = 1 AND variant_item_id IN (' . implode(',', $itemIds) . ')';
            $result = $conn->query($sql);
            while ($result && ($row = $result->fetch_assoc())) {
                $ownerIds[(int) $row['variant_item_id']] = (int) $row['parent_item_id'];
            }
        }

        $configOwnerIds = array_values(array_unique(array_values($ownerIds)));
        $directStates = $this->itemSugarDirectStates($conn, $configOwnerIds);
        $groupByItem = [];
        if ($configOwnerIds) {
            $result = $conn->query('SELECT id, group1 FROM myitems WHERE id IN (' . implode(',', $configOwnerIds) . ')');
            while ($result && ($row = $result->fetch_assoc())) {
                $groupByItem[(int) $row['id']] = (int) ($row['group1'] ?? 0);
            }
        }
        $categoryStates = $this->categorySugarStates($conn, array_values($groupByItem));

        foreach ($items as &$item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $ownerId = (int) ($ownerIds[$itemId] ?? $itemId);
            $groupId = (int) ($groupByItem[$ownerId] ?? 0);
            $item['allows_sugar_spoons'] = !empty($directStates[$ownerId]) || !empty($categoryStates[$groupId]);
        }
        unset($item);
        return $items;
    }

    /**
     * Accepts either [{code,value}] or {sugar_spoons: value}; a configured
     * field is rejected if it was omitted, so zero remains an explicit choice.
     */
    public function validateForItem(mysqli $conn, int $itemId, $submitted, array $context = []): array
    {
        $fields = $this->fieldsForItem($conn, $itemId, $context);
        if (!$fields) {
            if ($this->hasSubmittedValues($submitted)) {
                throw new InvalidArgumentException('PREPARATION_FIELD_NOT_ALLOWED');
            }
            return [];
        }

        $values = $this->normalizeSubmitted($submitted);
        $allowed = [];
        foreach ($fields as $field) {
            $allowed[$field['code']] = $field;
        }
        foreach (array_keys($values) as $code) {
            if (!isset($allowed[$code])) {
                throw new InvalidArgumentException('PREPARATION_FIELD_NOT_ALLOWED');
            }
        }

        $validated = [];
        foreach ($fields as $field) {
            $code = $field['code'];
            if (!array_key_exists($code, $values)) {
                if (!empty($field['requires_explicit_value'])) {
                    throw new InvalidArgumentException('PREPARATION_VALUE_REQUIRED');
                }
                continue;
            }
            $value = $this->integerValue($values[$code]);
            if ($value < 0 || $value > (int) $field['max_value']) {
                throw new InvalidArgumentException('PREPARATION_VALUE_OUT_OF_RANGE');
            }
            $snapshot = $field;
            $snapshot['value'] = $value;
            $validated[] = $snapshot;
        }

        return $validated;
    }

    public function persistLineValues(mysqli $conn, int $orderId, int $detailId, int $itemId, array $values): void
    {
        if ($orderId < 1 || $detailId < 1 || !$this->tableExists($conn, 'order_line_preparation_values')) {
            return;
        }
        $delete = $conn->prepare('DELETE FROM order_line_preparation_values WHERE order_id = ? AND fat_detail_id = ?');
        $delete->bind_param('ii', $orderId, $detailId);
        $delete->execute();
        $delete->close();
        if (!$values) {
            return;
        }

        $insert = $conn->prepare("\n            INSERT INTO order_line_preparation_values\n                (order_id, fat_detail_id, item_id, field_code, label_ar, value_int, max_value,\n                 inventory_item_id, inventory_qty_per_value)\n            VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?)\n        ");
        foreach ($values as $value) {
            $code = (string) ($value['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $label = (string) ($value['label_ar'] ?? '');
            $selected = (int) ($value['value'] ?? 0);
            $max = (int) ($value['max_value'] ?? 0);
            $inventoryItemId = (int) ($value['inventory_item_id'] ?? 0);
            $inventoryQty = (string) ($value['inventory_qty_per_value'] ?? '0');
            $insert->bind_param('iiissiids', $orderId, $detailId, $itemId, $code, $label, $selected, $max, $inventoryItemId, $inventoryQty);
            $insert->execute();
        }
        $insert->close();
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchLineValues(mysqli $conn, int $orderId, int $detailId): array
    {
        if ($orderId < 1 || $detailId < 1 || !$this->tableExists($conn, 'order_line_preparation_values')) {
            return [];
        }
        $stmt = $conn->prepare("\n            SELECT field_code, label_ar, value_int, max_value, inventory_item_id, inventory_qty_per_value\n            FROM order_line_preparation_values\n            WHERE order_id = ? AND fat_detail_id = ?\n            ORDER BY id\n        ");
        $stmt->bind_param('ii', $orderId, $detailId);
        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_assoc()) {
            $values[] = [
                'code' => (string) $row['field_code'],
                'label_ar' => (string) $row['label_ar'],
                'value' => (int) $row['value_int'],
                'max_value' => (int) $row['max_value'],
                'inventory_item_id' => (int) ($row['inventory_item_id'] ?? 0),
                'inventory_qty_per_value' => (string) ($row['inventory_qty_per_value'] ?? '0'),
            ];
        }
        $stmt->close();

        return $values;
    }

    public function saveSugarSpoonsConfig(mysqli $conn, int $itemId, array $input, int $actorId = 0): void
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'item_preparation_configs')) {
            return;
        }
        $active = !empty($input['sugar_spoons_enabled']) ? 1 : 0;
        $max = self::SUGAR_SPOONS_SAFETY_LIMIT;
        $inventoryItemId = max(0, (int) ($input['sugar_spoons_inventory_item_id'] ?? 0));
        $inventoryQty = trim((string) ($input['sugar_spoons_inventory_qty_per_spoon'] ?? '0'));
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $inventoryQty) || (float) $inventoryQty < 0) {
            throw new InvalidArgumentException('PREPARATION_INVENTORY_QTY_INVALID');
        }
        $labelAr = 'ملاعق سكر';
        $labelEn = 'Sugar spoons';
        $code = self::SUGAR_SPOONS;
        $stmt = $conn->prepare("\n            INSERT INTO item_preparation_configs\n                (item_id, field_code, label_ar, label_en, max_value, requires_explicit_value, is_active,\n                 inventory_item_id, inventory_qty_per_value, sort_order, created_by, updated_by)\n            VALUES (?, ?, ?, ?, ?, 1, ?, NULLIF(?, 0), ?, 0, NULLIF(?, 0), NULLIF(?, 0))\n            ON DUPLICATE KEY UPDATE\n                max_value = VALUES(max_value), requires_explicit_value = 1, is_active = VALUES(is_active),\n                inventory_item_id = VALUES(inventory_item_id), inventory_qty_per_value = VALUES(inventory_qty_per_value),\n                updated_by = VALUES(updated_by)\n        ");
        $stmt->bind_param('isssiiisii', $itemId, $code, $labelAr, $labelEn, $max, $active, $inventoryItemId, $inventoryQty, $actorId, $actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function setItemSugarAllowed(mysqli $conn, int $itemId, bool $allowed, int $actorId = 0): int
    {
        if ($itemId < 1 || !$this->tableExists($conn, 'item_preparation_configs')) {
            throw new RuntimeException('PREPARATION_SCHEMA_NOT_READY');
        }
        $code = self::SUGAR_SPOONS;
        $labelAr = 'ملاعق سكر';
        $labelEn = 'Sugar spoons';
        $max = self::SUGAR_SPOONS_SAFETY_LIMIT;
        $active = $allowed ? 1 : 0;
        $stmt = $conn->prepare("\n            INSERT INTO item_preparation_configs
                (item_id, field_code, label_ar, label_en, max_value, requires_explicit_value,
                 is_active, inventory_item_id, inventory_qty_per_value, sort_order, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, 1, ?, NULL, 0.000000, 0, NULLIF(?, 0), NULLIF(?, 0))
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                label_ar = VALUES(label_ar), label_en = VALUES(label_en),
                max_value = VALUES(max_value), requires_explicit_value = 1,
                is_active = VALUES(is_active), updated_by = VALUES(updated_by)
        ");
        $stmt->bind_param('isssiiii', $itemId, $code, $labelAr, $labelEn, $max, $active, $actorId, $actorId);
        $stmt->execute();
        $configId = (int) ($stmt->insert_id ?: $conn->insert_id);
        $stmt->close();
        return $configId;
    }

    public function setCategorySugarAllowed(mysqli $conn, int $groupId, bool $allowed, int $actorId = 0): int
    {
        if ($groupId < 1 || !$this->tableExists($conn, 'item_group_preparation_configs')) {
            throw new RuntimeException('PREPARATION_SCHEMA_NOT_READY');
        }
        $code = self::SUGAR_SPOONS;
        $labelAr = 'ملاعق سكر';
        $labelEn = 'Sugar spoons';
        $max = self::SUGAR_SPOONS_SAFETY_LIMIT;
        $active = $allowed ? 1 : 0;
        $stmt = $conn->prepare("\n            INSERT INTO item_group_preparation_configs
                (item_group_id, field_code, label_ar, label_en, max_value, requires_explicit_value,
                 is_active, sort_order, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, 1, ?, 0, NULLIF(?, 0), NULLIF(?, 0))
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id), label_ar = VALUES(label_ar), label_en = VALUES(label_en),
                max_value = VALUES(max_value), requires_explicit_value = 1,
                is_active = VALUES(is_active), updated_by = VALUES(updated_by)
        ");
        $stmt->bind_param('isssiiii', $groupId, $code, $labelAr, $labelEn, $max, $active, $actorId, $actorId);
        $stmt->execute();
        $configId = (int) ($stmt->insert_id ?: $conn->insert_id);
        $stmt->close();
        return $configId;
    }

    public function itemSugarDirectStates(mysqli $conn, array $itemIds): array
    {
        return $this->activeStateMap($conn, 'item_preparation_configs', 'item_id', $itemIds);
    }

    public function categorySugarStates(mysqli $conn, array $groupIds): array
    {
        return $this->activeStateMap($conn, 'item_group_preparation_configs', 'item_group_id', $groupIds);
    }

    /**
     * Replaces the admin-managed sugar eligibility sets in one operation.
     *
     * Category and item selections intentionally remain independent: selecting a
     * category does not destroy an existing item-level selection, so removing the
     * category later cannot silently change a previously explicit item decision.
     * The caller owns the transaction so sync-outbox writes can commit atomically
     * with these configuration changes.
     *
     * @return array{selected_category_ids:array<int,int>,selected_item_ids:array<int,int>,category_config_ids:array<int,int>,item_config_ids:array<int,int>,changed_category_ids:array<int,int>,changed_item_ids:array<int,int>}
     */
    public function replaceSugarAssignments(
        mysqli $conn,
        array $selectedCategoryIds,
        array $selectedItemIds,
        int $actorId = 0
    ): array {
        if (!$this->tableExists($conn, 'item_preparation_configs')
            || !$this->tableExists($conn, 'item_group_preparation_configs')) {
            throw new RuntimeException('PREPARATION_SCHEMA_NOT_READY');
        }

        $selectedCategoryIds = $this->normalizeOwnerIds($selectedCategoryIds);
        $selectedItemIds = $this->normalizeOwnerIds($selectedItemIds);
        $this->assertOwnersExist($conn, 'item_group', $selectedCategoryIds);
        $this->assertOwnersExist($conn, 'myitems', $selectedItemIds);

        $currentCategoryIds = $this->activeSugarOwnerIds(
            $conn,
            'item_group_preparation_configs',
            'item_group_id'
        );
        $currentItemIds = $this->activeSugarOwnerIds(
            $conn,
            'item_preparation_configs',
            'item_id'
        );

        $changedCategoryIds = $this->changedOwnerIds($currentCategoryIds, $selectedCategoryIds);
        $changedItemIds = $this->changedOwnerIds($currentItemIds, $selectedItemIds);
        $categoryConfigIds = [];
        $itemConfigIds = [];

        foreach ($changedCategoryIds as $groupId) {
            $categoryConfigIds[] = $this->setCategorySugarAllowed(
                $conn,
                $groupId,
                in_array($groupId, $selectedCategoryIds, true),
                $actorId
            );
        }
        foreach ($changedItemIds as $itemId) {
            $itemConfigIds[] = $this->setItemSugarAllowed(
                $conn,
                $itemId,
                in_array($itemId, $selectedItemIds, true),
                $actorId
            );
        }

        return [
            'selected_category_ids' => $selectedCategoryIds,
            'selected_item_ids' => $selectedItemIds,
            'category_config_ids' => array_values(array_filter($categoryConfigIds)),
            'item_config_ids' => array_values(array_filter($itemConfigIds)),
            'changed_category_ids' => $changedCategoryIds,
            'changed_item_ids' => $changedItemIds,
        ];
    }

    private function publicField(array $row): array
    {
        $code = (string) $row['field_code'];
        return [
            'code' => $code,
            'label_ar' => (string) $row['label_ar'],
            'label_en' => $row['label_en'] !== null ? (string) $row['label_en'] : null,
            'max_value' => $code === self::SUGAR_SPOONS
                ? self::SUGAR_SPOONS_SAFETY_LIMIT
                : max(1, min(20, (int) ($row['max_value'] ?? 5))),
            'requires_explicit_value' => (int) ($row['requires_explicit_value'] ?? 1) === 1,
            'inventory_item_id' => (int) ($row['inventory_item_id'] ?? 0),
            'inventory_qty_per_value' => (string) ($row['inventory_qty_per_value'] ?? '0'),
        ];
    }

    private function normalizeSubmitted($submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }
        $values = [];
        $isList = array_keys($submitted) === range(0, count($submitted) - 1);
        if (!$isList) {
            foreach ($submitted as $code => $value) {
                if (is_array($value)) {
                    $code = $value['code'] ?? $value['field_code'] ?? $code;
                    $value = $value['value'] ?? $value['value_int'] ?? null;
                }
                $values[(string) $code] = $value;
            }
            return $values;
        }
        foreach ($submitted as $value) {
            if (!is_array($value)) {
                continue;
            }
            $code = (string) ($value['code'] ?? $value['field_code'] ?? $value['id'] ?? $value['option_id'] ?? '');
            $code = preg_replace('/^pos-preparation-/', '', $code) ?: '';
            if ($code !== '') {
                $values[$code] = $value['value'] ?? $value['value_int'] ?? null;
            }
        }
        return $values;
    }

    private function integerValue($value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }
        throw new InvalidArgumentException('PREPARATION_VALUE_INVALID');
    }

    private function hasSubmittedValues($submitted): bool
    {
        return is_array($submitted) && $submitted !== [];
    }

    private function configOwnerItemId(mysqli $conn, int $itemId): int
    {
        if (!$this->tableExists($conn, 'item_variants')) {
            return $itemId;
        }
        $stmt = $conn->prepare('SELECT parent_item_id FROM item_variants WHERE variant_item_id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['parent_item_id'] ?? 0) > 0 ? (int) $row['parent_item_id'] : $itemId;
    }

    private function itemGroupId(mysqli $conn, int $itemId): int
    {
        // Some supported upgrade schemas have myitems before the legacy
        // grouping column exists. Preparation fields are optional; absence of
        // that column means "no group configuration", not a checkout failure.
        if (!$this->columnExists($conn, 'myitems', 'group1')) {
            return 0;
        }
        $stmt = $conn->prepare('SELECT group1 FROM myitems WHERE id = ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['group1'] ?? 0);
    }

    private function activeStateMap(mysqli $conn, string $table, string $ownerColumn, array $ownerIds): array
    {
        if (!$this->tableExists($conn, $table)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ownerIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        $sql = "SELECT {$ownerColumn} AS owner_id, is_active FROM {$table} WHERE field_code = ? AND {$ownerColumn} IN (" . implode(',', $ids) . ')';
        $stmt = $conn->prepare($sql);
        $code = self::SUGAR_SPOONS;
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $states = [];
        while ($row = $result->fetch_assoc()) {
            $states[(int) $row['owner_id']] = (int) $row['is_active'] === 1;
        }
        $stmt->close();
        return $states;
    }

    private function normalizeOwnerIds(array $ownerIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ownerIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function assertOwnersExist(mysqli $conn, string $table, array $ownerIds): void
    {
        if (!$ownerIds) {
            return;
        }
        $sql = "SELECT id FROM {$table} WHERE COALESCE(isdeleted, 0) = 0 AND id IN (" . implode(',', $ownerIds) . ')';
        $result = $conn->query($sql);
        $found = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $found[] = (int) $row['id'];
        }
        sort($found, SORT_NUMERIC);
        if ($found !== $ownerIds) {
            throw new InvalidArgumentException('PREPARATION_ASSIGNMENT_OWNER_INVALID');
        }
    }

    private function activeSugarOwnerIds(mysqli $conn, string $table, string $ownerColumn): array
    {
        $stmt = $conn->prepare("SELECT {$ownerColumn} AS owner_id FROM {$table} WHERE field_code = ? AND is_active = 1");
        $code = self::SUGAR_SPOONS;
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['owner_id'];
        }
        $stmt->close();
        return $this->normalizeOwnerIds($ids);
    }

    private function changedOwnerIds(array $currentIds, array $selectedIds): array
    {
        return $this->normalizeOwnerIds(array_merge(
            array_diff($currentIds, $selectedIds),
            array_diff($selectedIds, $currentIds)
        ));
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $this->tableCache[$table] = (int) ($row['c'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (isset($this->tableCache[$cacheKey])) {
            return $this->tableCache[$cacheKey];
        }
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $this->tableCache[$cacheKey] = (int) ($row['c'] ?? 0) > 0;
    }
}
