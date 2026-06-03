<?php

require_once __DIR__ . '/../Pos/Service/ItemVariantService.php';

/**
 * Builds Moova-compatible menu option groups from POS modifier groups and item variants.
 */
class ItemCustomerMenuOptions
{
    public static function tableExists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $cache[$table] = false;
            return false;
        }
        $safeTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
        $cache[$table] = $result && $result->num_rows > 0;

        return $cache[$table];
    }

    public static function columnExists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            $cache[$key] = false;
            return false;
        }
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
        $cache[$key] = $result && $result->num_rows > 0;

        return $cache[$key];
    }

    public static function modifierGroupsForItem(mysqli $conn, int $itemId): array
    {
        if (
            $itemId <= 0
            || !self::tableExists($conn, 'modifier_groups')
            || !self::tableExists($conn, 'modifier_options')
            || !self::tableExists($conn, 'item_modifier_groups')
        ) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT
                mg.id,
                mg.name_ar,
                mg.name_en,
                mg.selection_min,
                mg.selection_max,
                mg.is_required,
                mg.is_active,
                COALESCE(img.sort_order, mg.sort_order, 0) AS group_sort_order
            FROM item_modifier_groups img
            JOIN modifier_groups mg ON mg.id = img.group_id
            WHERE img.item_id = ?
              AND mg.is_active = 1
            ORDER BY img.sort_order, mg.sort_order, mg.id
        ");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['id'];
            $providerGroupId = 'pos-mod-group-' . $groupId;
            $groups[$groupId] = [
                'id' => $providerGroupId,
                'providerOptionId' => $providerGroupId,
                'label' => (string) ($row['name_ar'] ?? ''),
                'name2' => trim((string) ($row['name_en'] ?? '')) ?: null,
                'type' => 'choice',
                'isRequired' => (int) ($row['is_required'] ?? 0) === 1 || (int) ($row['selection_min'] ?? 0) > 0,
                'min' => max(0, (int) ($row['selection_min'] ?? 0)),
                'max' => max(0, (int) ($row['selection_max'] ?? 0)),
                'choices' => [],
            ];
        }
        $stmt->close();

        if (!$groups) {
            return [];
        }

        $groupIds = array_keys($groups);
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $conn->prepare("
            SELECT id, group_id, name_ar, name_en, price_delta, is_active, sort_order
            FROM modifier_options
            WHERE group_id IN ({$placeholders})
              AND is_active = 1
            ORDER BY group_id, sort_order, id
        ");
        $stmt->bind_param(str_repeat('i', count($groupIds)), ...$groupIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['group_id'];
            if (!isset($groups[$groupId])) {
                continue;
            }
            $optionId = (int) $row['id'];
            $providerOptionId = 'pos-mod-option-' . $optionId;
            $modifierDeltaMajor = self::decimal($row['price_delta'] ?? 0);
            $modifierDeltaCents = self::majorToCents($modifierDeltaMajor);
            $groups[$groupId]['choices'][] = [
                'id' => $providerOptionId,
                'providerChoiceId' => $providerOptionId,
                'label' => (string) ($row['name_ar'] ?? ''),
                'name2' => trim((string) ($row['name_en'] ?? '')) ?: null,
                'priceDelta' => $modifierDeltaCents,
                'priceDeltaCents' => $modifierDeltaCents,
                'priceDeltaMajor' => $modifierDeltaMajor,
            ];
        }
        $stmt->close();

        return array_values(array_filter($groups, static function (array $group): bool {
            return !empty($group['choices']);
        }));
    }

    public static function variantsOptionGroup(int $parentItemId, array $variants, float $basePrice): ?array
    {
        if (!$variants) {
            return null;
        }

        $choices = [];
        foreach ($variants as $variant) {
            $variantItemId = (int) ($variant['variant_item_id'] ?? $variant['item_id'] ?? 0);
            if ($variantItemId < 1) {
                continue;
            }
            $label = trim((string) ($variant['variant_label'] ?? $variant['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($variant['iname'] ?? $variant['name'] ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $variantPrice = self::decimal($variant['price1'] ?? $variant['price'] ?? 0);
            $deltaMajor = self::decimal($variantPrice - $basePrice);
            $deltaCents = self::majorToCents($deltaMajor);
            $variantPriceCents = self::majorToCents($variantPrice);
            $choices[] = [
                'id' => 'pos-variant-' . $variantItemId,
                'providerChoiceId' => 'pos-variant-' . $variantItemId,
                'label' => $label,
                'name2' => trim((string) ($variant['variant_name_en'] ?? $variant['name2'] ?? '')) ?: null,
                'priceDelta' => $deltaCents,
                'priceDeltaCents' => $deltaCents,
                'priceDeltaMajor' => $deltaMajor,
                'absolutePriceCents' => $variantPriceCents,
                'absolutePriceMajor' => $variantPrice,
                'variantItemId' => $variantItemId,
                'posVariantItemId' => $variantItemId,
            ];
        }
        if (!$choices) {
            return null;
        }

        return [
            'id' => 'pos-variant-group-' . $parentItemId,
            'providerOptionId' => 'pos-variant-group-' . $parentItemId,
            'label' => 'الحجم',
            'name2' => 'Size',
            'type' => 'choice',
            'isRequired' => true,
            'choices' => $choices,
        ];
    }

    public static function resolveVariantBasePrice(array $variants, float $fallbackPrice): float
    {
        foreach ($variants as $variant) {
            if (!empty($variant['is_default'])) {
                return self::decimal($variant['price1'] ?? $variant['price'] ?? $fallbackPrice);
            }
        }
        if ($variants) {
            return self::decimal($variants[0]['price1'] ?? $variants[0]['price'] ?? $fallbackPrice);
        }

        return self::decimal($fallbackPrice);
    }

    public static function buildForItem(mysqli $conn, int $itemId, float $basePrice, ?array $variants = null): array
    {
        $options = self::modifierGroupsForItem($conn, $itemId);
        if ($variants === null && self::tableExists($conn, 'item_variants')) {
            $variants = (new ItemVariantService())->variantsForParent($conn, $itemId, true);
        }
        $variantGroup = is_array($variants) && $variants
            ? self::variantsOptionGroup($itemId, $variants, $basePrice)
            : null;
        if ($variantGroup !== null) {
            $options[] = $variantGroup;
        }

        return $options;
    }

    public static function activeVariantsForParents(mysqli $conn, array $parentItemIds): array
    {
        if (!self::tableExists($conn, 'item_variants') || !$parentItemIds) {
            return [];
        }

        return (new ItemVariantService())->activeVariantsForParents($conn, $parentItemIds);
    }

    public static function majorToCents($value): int
    {
        return (int) round(self::decimal($value) * 100);
    }

    private static function decimal($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
