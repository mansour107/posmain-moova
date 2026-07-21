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

        return $fields;
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
        $max = max(1, min(20, (int) ($input['sugar_spoons_max'] ?? 5)));
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

    private function publicField(array $row): array
    {
        return [
            'code' => (string) $row['field_code'],
            'label_ar' => (string) $row['label_ar'],
            'label_en' => $row['label_en'] !== null ? (string) $row['label_en'] : null,
            'max_value' => max(1, min(20, (int) ($row['max_value'] ?? 5))),
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
}
