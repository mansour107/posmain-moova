<?php

class NutritionProfileService
{
    private const DECIMAL_FIELDS = [
        'calories_kcal',
        'protein_g',
        'carbs_g',
        'fat_g',
        'sugar_g',
        'fiber_g',
        'sodium_mg',
    ];

    public function saveProfile(mysqli $conn, int $itemId, array $data, array $context = []): array
    {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        if (!$this->nutritionEnabled($context)) {
            return [
                'success' => false,
                'code' => 'NUTRITION_DISABLED',
                'enabled' => false,
                'profile' => null,
            ];
        }

        $servingQty = $this->positiveDecimal($data['serving_qty'] ?? 1, 'SERVING_QTY_INVALID');
        $servingUnitId = $this->optionalPositiveInt($data['serving_unit_id'] ?? null);
        $values = [];
        foreach (self::DECIMAL_FIELDS as $field) {
            $values[$field] = $this->nullableDecimal($data[$field] ?? null, strtoupper($field) . '_INVALID');
        }
        $allergensJson = $this->jsonArrayOrNull($data['allergens'] ?? $data['allergens_json'] ?? null, 'ALLERGENS_INVALID');
        $dietaryFlagsJson = $this->jsonArrayOrNull($data['dietary_flags'] ?? $data['dietary_flags_json'] ?? null, 'DIETARY_FLAGS_INVALID');
        $dataSource = $this->nullableText($data['data_source'] ?? null, 120);
        $verifiedBy = $this->optionalPositiveInt($data['verified_by'] ?? $context['user_id'] ?? null);
        $verifiedAt = $verifiedBy !== null ? $this->dateTime($data['verified_at'] ?? null) : null;

        $stmt = $conn->prepare("
            INSERT INTO item_nutrition_profiles (
                item_id, serving_qty, serving_unit_id, calories_kcal,
                protein_g, carbs_g, fat_g, sugar_g, fiber_g, sodium_mg,
                allergens_json, dietary_flags_json, data_source,
                verified_by, verified_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                serving_qty = VALUES(serving_qty),
                serving_unit_id = VALUES(serving_unit_id),
                calories_kcal = VALUES(calories_kcal),
                protein_g = VALUES(protein_g),
                carbs_g = VALUES(carbs_g),
                fat_g = VALUES(fat_g),
                sugar_g = VALUES(sugar_g),
                fiber_g = VALUES(fiber_g),
                sodium_mg = VALUES(sodium_mg),
                allergens_json = VALUES(allergens_json),
                dietary_flags_json = VALUES(dietary_flags_json),
                data_source = VALUES(data_source),
                verified_by = VALUES(verified_by),
                verified_at = VALUES(verified_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param(
            'isissssssssssis',
            $itemId,
            $servingQty,
            $servingUnitId,
            $values['calories_kcal'],
            $values['protein_g'],
            $values['carbs_g'],
            $values['fat_g'],
            $values['sugar_g'],
            $values['fiber_g'],
            $values['sodium_mg'],
            $allergensJson,
            $dietaryFlagsJson,
            $dataSource,
            $verifiedBy,
            $verifiedAt
        );
        $stmt->execute();
        $stmt->close();

        return [
            'success' => true,
            'code' => 'OK',
            'enabled' => true,
            'profile' => $this->profileForItem($conn, $itemId),
        ];
    }

    public function profileForItem(mysqli $conn, int $itemId): ?array
    {
        $itemId = $this->positiveInt($itemId, 'ITEM_ID_REQUIRED');
        $stmt = $conn->prepare("SELECT * FROM item_nutrition_profiles WHERE item_id = ? LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->formatProfile($row) : null;
    }

    public function nutritionForQty(array $profile, $qty): array
    {
        $qty = $this->positiveDecimal($qty, 'QTY_INVALID');
        $servingQty = (float) ($profile['serving_qty'] ?? 0);
        if ($servingQty <= 0) {
            throw new InvalidArgumentException('SERVING_QTY_INVALID');
        }

        $factor = $qty / $servingQty;
        $totals = [
            'qty' => $this->formatDecimal($qty),
            'serving_qty' => $this->formatDecimal($servingQty),
            'factor' => $this->formatDecimal($factor),
        ];
        foreach (self::DECIMAL_FIELDS as $field) {
            $totals[$field] = array_key_exists($field, $profile) && $profile[$field] !== null
                ? $this->formatDecimal((float) $profile[$field] * $factor)
                : null;
        }

        return $totals;
    }

    private function nutritionEnabled(array $context): bool
    {
        if (array_key_exists('nutrition_enabled', $context)) {
            return (bool) $context['nutrition_enabled'];
        }

        if (function_exists('posmain_app_config')) {
            $config = posmain_app_config();
            return !empty($config['features']['nutrition']);
        }

        $value = getenv('POSMAIN_ENABLE_NUTRITION');
        if ($value === false || $value === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function formatProfile(array $row): array
    {
        $profile = [
            'id' => (int) $row['id'],
            'item_id' => (int) $row['item_id'],
            'serving_qty' => $this->formatDecimal($row['serving_qty']),
            'serving_unit_id' => $row['serving_unit_id'] !== null ? (int) $row['serving_unit_id'] : null,
        ];
        foreach (self::DECIMAL_FIELDS as $field) {
            $profile[$field] = $row[$field] !== null ? $this->formatDecimal($row[$field]) : null;
        }
        $profile['allergens'] = $row['allergens_json'] !== null ? json_decode((string) $row['allergens_json'], true) : [];
        $profile['dietary_flags'] = $row['dietary_flags_json'] !== null ? json_decode((string) $row['dietary_flags_json'], true) : [];
        $profile['data_source'] = $row['data_source'] !== null ? (string) $row['data_source'] : null;
        $profile['verified_by'] = $row['verified_by'] !== null ? (int) $row['verified_by'] : null;
        $profile['verified_at'] = $row['verified_at'] !== null ? (string) $row['verified_at'] : null;
        $profile['created_at'] = (string) $row['created_at'];
        $profile['updated_at'] = (string) $row['updated_at'];

        return $profile;
    }

    private function jsonArrayOrNull($value, string $code): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException($code);
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException($code);
        }

        $json = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new InvalidArgumentException($code);
        }

        return $json;
    }

    private function dateTime($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('DATETIME_INVALID');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function positiveDecimal($value, string $code): string
    {
        $value = (float) $value;
        if ($value <= 0) {
            throw new InvalidArgumentException($code);
        }

        return $this->formatDecimal($value);
    }

    private function nullableDecimal($value, string $code): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $this->formatDecimal($value);
    }

    private function nullableText($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function formatDecimal($value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
