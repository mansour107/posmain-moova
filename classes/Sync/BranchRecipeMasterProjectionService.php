<?php

require_once __DIR__ . '/MasterDataRevisionService.php';
require_once __DIR__ . '/../Recipe/RecipeDecimal.php';

class BranchRecipeMasterProjectionService
{
    private const ALLOWED_FIELDS = [
        'recipe_name',
        'recipe_type',
        'yield_qty',
        'yield_unit_id',
        'default_wastage_percent',
        'costing_method',
        'lines',
        'variant_lines',
    ];

    private const RECIPE_TYPES = [
        'make_to_order',
        'batch_prepared',
        'hybrid',
        'packaging_bundle',
        'modifier_only',
        'sub_recipe',
    ];

    private const COSTING_METHODS = [
        'item_cost_price',
        'moving_average',
        'last_purchase',
        'manual_snapshot',
    ];

    private MasterDataRevisionService $revisions;

    public function __construct(?MasterDataRevisionService $revisions = null)
    {
        $this->revisions = $revisions ?: new MasterDataRevisionService();
    }

    public static function allowedFields(): array
    {
        return self::ALLOWED_FIELDS;
    }

    public function validateIncomingFields(mysqli $conn, array $fields): void
    {
        foreach ($fields as $fieldName => $definition) {
            if (!is_array($definition)) {
                throw new InvalidArgumentException('MASTER_RECIPE_FIELD_INVALID:' . $fieldName);
            }
            $this->validateField($conn, 0, (string) $fieldName, $definition['value'] ?? null);
        }
    }

    public function apply(mysqli $conn, string $branchUuid, array $event): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $master = is_array($payload['master_data'] ?? null) ? $payload['master_data'] : [];
        $recipeUuid = strtolower(trim((string) ($master['aggregate_uuid'] ?? $payload['recipe_uuid'] ?? '')));
        $stmt = $conn->prepare('SELECT * FROM recipe_headers WHERE recipe_uuid = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $recipeUuid);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$header) {
            throw new RuntimeException('MASTER_RECIPE_NOT_FOUND_BRANCH_CREATION_REQUIRES_APPROVAL');
        }
        if ((string) ($header['status'] ?? '') !== 'draft') {
            throw new RuntimeException('MASTER_RECIPE_ACTIVE_OR_ARCHIVED_REQUIRES_BRANCH_VERSION_APPROVAL');
        }

        $recipeId = (int) $header['id'];
        $current = $this->currentValuesForRecipe($conn, $recipeId, $header);
        $this->revisions->seedCurrentValues(
            $conn,
            $branchUuid,
            'recipe',
            $recipeUuid,
            $current,
            'branch:' . $branchUuid,
            (string) ($header['updated_at'] ?? $header['created_at'] ?? gmdate('Y-m-d H:i:s')) . 'Z'
        );

        $fields = is_array($master['fields'] ?? null) ? $master['fields'] : [];
        $this->validateIncomingFields($conn, $fields);

        $resolution = $this->revisions->resolve($conn, $branchUuid, $event, self::ALLOWED_FIELDS);
        $winning = $resolution['winning_fields'];
        if (!$winning) {
            return [
                'entity_id' => 'recipe_headers:' . $recipeId,
                'stale' => true,
                'reason' => 'all_master_fields_older_or_duplicate',
                'master_resolution' => $resolution,
            ];
        }

        $headerMap = [
            'recipe_name' => 'recipe_name',
            'recipe_type' => 'recipe_type',
            'yield_qty' => 'yield_qty',
            'yield_unit_id' => 'yield_unit_id',
            'default_wastage_percent' => 'default_wastage_percent',
            'costing_method' => 'costing_method',
        ];
        $assignments = [];
        $params = [];
        foreach ($headerMap as $field => $column) {
            if (array_key_exists($field, $winning)) {
                $assignments[] = "`{$column}` = ?";
                $params[] = $this->normalizeHeaderValue($field, $winning[$field]);
            }
        }
        if ($assignments) {
            $assignments[] = 'updated_at = CURRENT_TIMESTAMP';
            $params[] = $recipeId;
            $stmt = $conn->prepare(
                'UPDATE recipe_headers SET ' . implode(', ', $assignments) . ' WHERE id = ? AND status = \'draft\''
            );
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('MASTER_RECIPE_DRAFT_STATE_CHANGED');
            }
            $stmt->close();
        }

        if (array_key_exists('lines', $winning)) {
            $this->replaceLines($conn, $recipeId, $winning['lines']);
        }
        if (array_key_exists('variant_lines', $winning)) {
            $this->replaceVariantLines($conn, $recipeId, $winning['variant_lines']);
        }

        return [
            'entity_id' => 'recipe_headers:' . $recipeId,
            'master_resolution' => $resolution,
        ];
    }

    public function currentValuesForRecipe(mysqli $conn, int $recipeId, array $header): array
    {
        return [
            'recipe_name' => (string) $header['recipe_name'],
            'recipe_type' => (string) $header['recipe_type'],
            'yield_qty' => RecipeDecimal::normalize((string) $header['yield_qty'], 6),
            'yield_unit_id' => $header['yield_unit_id'] === null ? null : (int) $header['yield_unit_id'],
            'default_wastage_percent' => RecipeDecimal::normalize((string) $header['default_wastage_percent'], 4),
            'costing_method' => (string) $header['costing_method'],
            'lines' => $this->normalizedStoredLines($conn, 'recipe_lines', $recipeId),
            'variant_lines' => $this->normalizedStoredLines($conn, 'recipe_variant_lines', $recipeId),
        ];
    }

    private function normalizedStoredLines(mysqli $conn, string $table, int $recipeId): array
    {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE recipe_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->normalizeLine($table, $row);
        }
        $stmt->close();
        return $rows;
    }

    private function validateField(mysqli $conn, int $recipeId, string $field, $value): void
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException('MASTER_RECIPE_FIELD_NOT_ALLOWED:' . $field);
        }
        if ($field === 'recipe_name') {
            $name = trim((string) $value);
            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException('MASTER_RECIPE_NAME_INVALID');
            }
            return;
        }
        if ($field === 'recipe_type' && !in_array((string) $value, self::RECIPE_TYPES, true)) {
            throw new InvalidArgumentException('MASTER_RECIPE_TYPE_INVALID');
        }
        if ($field === 'costing_method' && !in_array((string) $value, self::COSTING_METHODS, true)) {
            throw new InvalidArgumentException('MASTER_RECIPE_COSTING_METHOD_INVALID');
        }
        if ($field === 'yield_qty' && RecipeDecimal::compare($this->decimalString($value, 6), '0', 6) <= 0) {
            throw new InvalidArgumentException('MASTER_RECIPE_YIELD_INVALID');
        }
        if ($field === 'default_wastage_percent') {
            $waste = $this->decimalString($value, 4);
            if (RecipeDecimal::compare($waste, '0', 4) < 0 || RecipeDecimal::compare($waste, '100', 4) > 0) {
                throw new InvalidArgumentException('MASTER_RECIPE_WASTAGE_INVALID');
            }
        }
        if ($field === 'yield_unit_id' && $value !== null) {
            $unitId = filter_var($value, FILTER_VALIDATE_INT);
            if ($unitId === false || $unitId < 1 || !$this->rowExists($conn, 'myunits', $unitId)) {
                throw new RuntimeException('MASTER_RECIPE_YIELD_UNIT_DEPENDENCY_MISSING');
            }
        }
        if ($field === 'lines' || $field === 'variant_lines') {
            if (!is_array($value)) {
                throw new InvalidArgumentException('MASTER_RECIPE_LINES_INVALID');
            }
            $seen = [];
            foreach ($value as $line) {
                if (!is_array($line)) {
                    throw new InvalidArgumentException('MASTER_RECIPE_LINE_INVALID');
                }
                $normalized = $this->normalizeLine($field === 'lines' ? 'recipe_lines' : 'recipe_variant_lines', $line);
                $uuid = $normalized['line_uuid'];
                if (isset($seen[$uuid])) {
                    throw new InvalidArgumentException('MASTER_RECIPE_LINE_UUID_DUPLICATE');
                }
                $seen[$uuid] = true;
                if ($normalized['ingredient_item_id'] !== null && !$this->rowExists($conn, 'myitems', $normalized['ingredient_item_id'])) {
                    throw new RuntimeException('MASTER_RECIPE_INGREDIENT_DEPENDENCY_MISSING');
                }
                if ($normalized['unit_id'] !== null && !$this->rowExists($conn, 'myunits', $normalized['unit_id'])) {
                    throw new RuntimeException('MASTER_RECIPE_UNIT_DEPENDENCY_MISSING');
                }
                if ($normalized['sub_recipe_id'] !== null && !$this->rowExists($conn, 'recipe_headers', $normalized['sub_recipe_id'])) {
                    throw new RuntimeException('MASTER_RECIPE_SUB_RECIPE_DEPENDENCY_MISSING');
                }
                if (
                    $field === 'variant_lines'
                    && (!$normalized['variant_item_id'] || !$this->rowExists($conn, 'myitems', $normalized['variant_item_id']))
                ) {
                    throw new RuntimeException('MASTER_RECIPE_VARIANT_DEPENDENCY_MISSING');
                }
            }
        }
    }

    private function replaceLines(mysqli $conn, int $recipeId, array $lines): void
    {
        $stmt = $conn->prepare('DELETE FROM recipe_lines WHERE recipe_id = ?');
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $stmt->close();
        foreach ($lines as $line) {
            $row = $this->normalizeLine('recipe_lines', $line);
            $stmt = $conn->prepare("
                INSERT INTO recipe_lines (
                    recipe_id, line_uuid, ingredient_item_id, sub_recipe_id, line_type,
                    ingredient_item_type_snapshot, qty_per_yield, unit_id,
                    unit_conversion_to_base, wastage_percent, is_required,
                    modifier_group_id, modifier_option_id, modifier_behavior,
                    substitution_group, order_type, channel, sort_order, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $params = [
                $recipeId, $row['line_uuid'], $row['ingredient_item_id'], $row['sub_recipe_id'],
                $row['line_type'], $row['ingredient_item_type_snapshot'], $row['qty_per_yield'],
                $row['unit_id'], $row['unit_conversion_to_base'], $row['wastage_percent'],
                $row['is_required'], $row['modifier_group_id'], $row['modifier_option_id'],
                $row['modifier_behavior'], $row['substitution_group'], $row['order_type'],
                $row['channel'], $row['sort_order'], $row['notes'],
            ];
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function replaceVariantLines(mysqli $conn, int $recipeId, array $lines): void
    {
        $stmt = $conn->prepare('DELETE FROM recipe_variant_lines WHERE recipe_id = ?');
        $stmt->bind_param('i', $recipeId);
        $stmt->execute();
        $stmt->close();
        foreach ($lines as $line) {
            $row = $this->normalizeLine('recipe_variant_lines', $line);
            $stmt = $conn->prepare("
                INSERT INTO recipe_variant_lines (
                    recipe_id, variant_item_id, base_line_id, line_uuid,
                    ingredient_item_id, sub_recipe_id, line_type,
                    ingredient_item_type_snapshot, qty_per_yield, unit_id,
                    unit_conversion_to_base, wastage_percent, is_required,
                    order_type, channel, sort_order, notes
                ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $params = [
                $recipeId, $row['variant_item_id'], $row['line_uuid'],
                $row['ingredient_item_id'], $row['sub_recipe_id'], $row['line_type'],
                $row['ingredient_item_type_snapshot'], $row['qty_per_yield'], $row['unit_id'],
                $row['unit_conversion_to_base'], $row['wastage_percent'], $row['is_required'],
                $row['order_type'], $row['channel'], $row['sort_order'], $row['notes'],
            ];
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function normalizeLine(string $table, array $line): array
    {
        $uuid = strtolower(trim((string) ($line['line_uuid'] ?? '')));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
            throw new InvalidArgumentException('MASTER_RECIPE_LINE_UUID_INVALID');
        }
        $lineType = (string) ($line['line_type'] ?? 'ingredient');
        $allowedLineTypes = $table === 'recipe_lines'
            ? ['ingredient', 'packaging', 'sub_recipe', 'modifier_ingredient', 'labor_placeholder']
            : ['ingredient', 'packaging', 'sub_recipe', 'labor_placeholder'];
        if (!in_array($lineType, $allowedLineTypes, true)) {
            throw new InvalidArgumentException('MASTER_RECIPE_LINE_TYPE_INVALID');
        }
        $qty = $this->decimalString($line['qty_per_yield'] ?? null, 6);
        if (RecipeDecimal::compare($qty, '0', 6) <= 0) {
            throw new InvalidArgumentException('MASTER_RECIPE_LINE_QUANTITY_INVALID');
        }
        $conversion = $this->decimalString($line['unit_conversion_to_base'] ?? '1', 8);
        if (RecipeDecimal::compare($conversion, '0', 8) <= 0) {
            throw new InvalidArgumentException('MASTER_RECIPE_LINE_CONVERSION_INVALID');
        }
        $waste = $this->decimalString($line['wastage_percent'] ?? '0', 4);
        if (RecipeDecimal::compare($waste, '0', 4) < 0 || RecipeDecimal::compare($waste, '100', 4) > 0) {
            throw new InvalidArgumentException('MASTER_RECIPE_LINE_WASTAGE_INVALID');
        }
        return [
            'line_uuid' => $uuid,
            'variant_item_id' => $this->nullablePositiveInt($line['variant_item_id'] ?? null),
            'ingredient_item_id' => $this->nullablePositiveInt($line['ingredient_item_id'] ?? null),
            'sub_recipe_id' => $this->nullablePositiveInt($line['sub_recipe_id'] ?? null),
            'line_type' => $lineType,
            'ingredient_item_type_snapshot' => $this->nullableString($line['ingredient_item_type_snapshot'] ?? null, 64),
            'qty_per_yield' => $qty,
            'unit_id' => $this->nullablePositiveInt($line['unit_id'] ?? null),
            'unit_conversion_to_base' => $conversion,
            'wastage_percent' => $waste,
            'is_required' => !empty($line['is_required']) ? 1 : 0,
            'modifier_group_id' => $this->nullablePositiveInt($line['modifier_group_id'] ?? null),
            'modifier_option_id' => $this->nullablePositiveInt($line['modifier_option_id'] ?? null),
            'modifier_behavior' => in_array(
                (string) ($line['modifier_behavior'] ?? 'additive'),
                ['additive', 'substitution_remove', 'substitution_add'],
                true
            ) ? (string) ($line['modifier_behavior'] ?? 'additive') : 'additive',
            'substitution_group' => $this->nullableString($line['substitution_group'] ?? null, 64),
            'order_type' => in_array(
                (string) ($line['order_type'] ?? 'any'),
                ['any', 'dine_in', 'takeaway', 'delivery'],
                true
            ) ? (string) ($line['order_type'] ?? 'any') : 'any',
            'channel' => in_array(
                (string) ($line['channel'] ?? 'any'),
                ['any', 'pos', 'table', 'moova', 'cofe', 'api'],
                true
            ) ? (string) ($line['channel'] ?? 'any') : 'any',
            'sort_order' => (int) ($line['sort_order'] ?? 0),
            'notes' => $this->nullableString($line['notes'] ?? null, 2000),
        ];
    }

    private function normalizeHeaderValue(string $field, $value)
    {
        if ($field === 'yield_qty') {
            return $this->decimalString($value, 6);
        }
        if ($field === 'default_wastage_percent') {
            return $this->decimalString($value, 4);
        }
        if ($field === 'yield_unit_id') {
            return $value === null ? null : (int) $value;
        }
        return trim((string) $value);
    }

    private function decimalString($value, int $scale): string
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,' . $scale . '})?$/', $value)) {
            throw new InvalidArgumentException('MASTER_RECIPE_DECIMAL_STRING_REQUIRED');
        }
        return RecipeDecimal::normalize($value, $scale);
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

    private function nullablePositiveInt($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1) {
            throw new InvalidArgumentException('MASTER_RECIPE_REFERENCE_INVALID');
        }
        return $parsed;
    }

    private function nullableString($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max) {
            throw new InvalidArgumentException('MASTER_RECIPE_TEXT_TOO_LONG');
        }
        return $value;
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
