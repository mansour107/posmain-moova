<?php

require_once __DIR__ . '/../../Items/ItemUnitColumnSupport.php';
require_once __DIR__ . '/../../Items/ItemUnitResolver.php';

class ItemVariantService
{
    public function ensureSchema(mysqli $conn): void
    {
        if ($this->tableExists($conn, 'item_variants')) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS item_variants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_item_id BIGINT UNSIGNED NOT NULL,
                variant_item_id BIGINT UNSIGNED NOT NULL,
                variant_label VARCHAR(120) NOT NULL,
                variant_name_en VARCHAR(120) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_item_variant_child (variant_item_id),
                UNIQUE KEY uq_item_variant_parent_child (parent_item_id, variant_item_id),
                KEY idx_item_variants_parent (parent_item_id, is_active, sort_order),
                KEY idx_item_variants_variant (variant_item_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public function variantsForParent(mysqli $conn, int $parentItemId, bool $activeOnly = true): array
    {
        if ($parentItemId <= 0) {
            return [];
        }

        $this->ensureSchema($conn);
        $imageSelect = $this->tableExists($conn, 'imgs') ? 'img.iname AS img_filename' : "'' AS img_filename";
        $imageJoin = $this->tableExists($conn, 'imgs') ? "
            LEFT JOIN (
                SELECT itemid, MIN(id) AS image_id
                FROM imgs
                WHERE COALESCE(isdeleted, 0) = 0
                GROUP BY itemid
            ) image_pick ON image_pick.itemid = child.id
            LEFT JOIN imgs img ON img.id = image_pick.image_id
        " : '';
        $activeSql = $activeOnly ? 'AND iv.is_active = 1 AND COALESCE(child.isdeleted, 0) = 0' : '';
        $stmt = $conn->prepare("
            SELECT
                iv.id AS relation_id,
                iv.parent_item_id,
                iv.variant_item_id,
                iv.variant_label,
                iv.variant_name_en,
                iv.sort_order,
                iv.is_default,
                iv.is_active,
                child.iname,
                child.name2,
                child.code,
                child.barcode,
                child.info,
                child.market_price,
                child.cost_price,
                child.price1,
                child.price2,
                child.price3,
                child.group1,
                child.group2,
                child.itmqty,
                {$imageSelect}
            FROM item_variants iv
            JOIN myitems child ON child.id = iv.variant_item_id
            {$imageJoin}
            WHERE iv.parent_item_id = ?
              {$activeSql}
            ORDER BY iv.sort_order ASC, iv.id ASC
        ");
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $variants = [];
        while ($row = $result->fetch_assoc()) {
            $variants[] = $this->normalizeVariantRow($row);
        }
        $stmt->close();

        return $variants;
    }

    public function activeVariantsForParents(mysqli $conn, array $parentItemIds): array
    {
        $parentItemIds = array_values(array_unique(array_filter(array_map('intval', $parentItemIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$parentItemIds) {
            return [];
        }

        $this->ensureSchema($conn);
        $imageSelect = $this->tableExists($conn, 'imgs') ? 'img.iname AS img_filename' : "'' AS img_filename";
        $imageJoin = $this->tableExists($conn, 'imgs') ? "
            LEFT JOIN (
                SELECT itemid, MIN(id) AS image_id
                FROM imgs
                WHERE COALESCE(isdeleted, 0) = 0
                GROUP BY itemid
            ) image_pick ON image_pick.itemid = child.id
            LEFT JOIN imgs img ON img.id = image_pick.image_id
        " : '';
        $placeholders = implode(',', array_fill(0, count($parentItemIds), '?'));
        $stmt = $conn->prepare("
            SELECT
                iv.id AS relation_id,
                iv.parent_item_id,
                iv.variant_item_id,
                iv.variant_label,
                iv.variant_name_en,
                iv.sort_order,
                iv.is_default,
                iv.is_active,
                child.iname,
                child.name2,
                child.code,
                child.barcode,
                child.info,
                child.market_price,
                child.cost_price,
                child.price1,
                child.price2,
                child.price3,
                child.group1,
                child.group2,
                child.itmqty,
                {$imageSelect}
            FROM item_variants iv
            JOIN myitems child ON child.id = iv.variant_item_id
            {$imageJoin}
            WHERE iv.parent_item_id IN ({$placeholders})
              AND iv.is_active = 1
              AND COALESCE(child.isdeleted, 0) = 0
            ORDER BY iv.parent_item_id ASC, iv.sort_order ASC, iv.id ASC
        ");
        $stmt->bind_param(str_repeat('i', count($parentItemIds)), ...$parentItemIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $grouped = [];
        while ($row = $result->fetch_assoc()) {
            $parentId = (int) $row['parent_item_id'];
            if (!isset($grouped[$parentId])) {
                $grouped[$parentId] = [];
            }
            $grouped[$parentId][] = $this->normalizeVariantRow($row);
        }
        $stmt->close();

        return $grouped;
    }

    public function variantParentForChild(mysqli $conn, int $variantItemId): ?array
    {
        if ($variantItemId <= 0) {
            return null;
        }

        $this->ensureSchema($conn);
        $stmt = $conn->prepare("
            SELECT
                iv.id AS relation_id,
                iv.parent_item_id,
                iv.variant_item_id,
                iv.variant_label,
                iv.variant_name_en,
                iv.sort_order,
                iv.is_default,
                iv.is_active,
                parent.iname AS parent_name
            FROM item_variants iv
            LEFT JOIN myitems parent ON parent.id = iv.parent_item_id
            WHERE iv.variant_item_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $variantItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'relation_id' => (int) $row['relation_id'],
            'parent_item_id' => (int) $row['parent_item_id'],
            'variant_item_id' => (int) $row['variant_item_id'],
            'variant_label' => (string) ($row['variant_label'] ?? ''),
            'variant_name_en' => (string) ($row['variant_name_en'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
            'parent_name' => (string) ($row['parent_name'] ?? ''),
        ];
    }

    public function hasActiveVariants(mysqli $conn, int $parentItemId): bool
    {
        if ($parentItemId <= 0) {
            return false;
        }

        $this->ensureSchema($conn);
        $stmt = $conn->prepare("
            SELECT 1
            FROM item_variants iv
            JOIN myitems child ON child.id = iv.variant_item_id
            WHERE iv.parent_item_id = ?
              AND iv.is_active = 1
              AND COALESCE(child.isdeleted, 0) = 0
            LIMIT 1
        ");
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $hasVariants = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $hasVariants;
    }

    public function variantsForEdit(mysqli $conn, int $parentItemId): array
    {
        $this->repairUnlinkedChildrenForParent($conn, $parentItemId);

        $variants = $this->variantsForParent($conn, $parentItemId, false);
        $linkedChildIds = [];
        foreach ($variants as $variant) {
            $childId = (int) ($variant['variant_item_id'] ?? 0);
            if ($childId > 0) {
                $linkedChildIds[$childId] = true;
            }
        }

        foreach ($this->unlinkedVariantChildrenForParent($conn, $parentItemId) as $unlinked) {
            $childId = (int) ($unlinked['variant_item_id'] ?? 0);
            if ($childId > 0 && !isset($linkedChildIds[$childId])) {
                $variants[] = $unlinked;
                $linkedChildIds[$childId] = true;
            }
        }

        usort($variants, static function (array $left, array $right): int {
            $leftSort = (int) ($left['sort_order'] ?? 0);
            $rightSort = (int) ($right['sort_order'] ?? 0);
            if ($leftSort !== $rightSort) {
                return $leftSort <=> $rightSort;
            }

            return (int) ($left['relation_id'] ?? 0) <=> (int) ($right['relation_id'] ?? 0);
        });

        return $variants;
    }

    public function unlinkedVariantChildrenForParent(mysqli $conn, int $parentItemId): array
    {
        if ($parentItemId <= 0) {
            return [];
        }

        $parent = $this->loadItem($conn, $parentItemId);
        if (!$parent) {
            return [];
        }

        $parentName = trim((string) ($parent['iname'] ?? ''));
        if ($parentName === '') {
            return [];
        }

        $this->ensureSchema($conn);
        $namePrefix = $parentName . ' - ';
        $nameLike = $namePrefix . '%';
        $stmt = $conn->prepare("
            SELECT
                child.id,
                child.iname,
                child.name2,
                child.code,
                child.barcode,
                child.info,
                child.market_price,
                child.cost_price,
                child.price1,
                child.price2,
                child.price3,
                child.group1,
                child.group2,
                child.itmqty
            FROM myitems child
            WHERE COALESCE(child.isdeleted, 0) = 0
              AND child.id != ?
              AND child.iname LIKE ?
              AND NOT EXISTS (
                  SELECT 1
                  FROM item_variants iv
                  WHERE iv.parent_item_id = ?
                    AND iv.variant_item_id = child.id
              )
            ORDER BY child.iname ASC, child.id ASC
        ");
        $stmt->bind_param('isi', $parentItemId, $nameLike, $parentItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $variants = [];
        $sortOrder = 1000;
        while ($row = $result->fetch_assoc()) {
            $childName = trim((string) ($row['iname'] ?? ''));
            $label = $childName;
            if (stripos($childName, $namePrefix) === 0) {
                $label = trim(substr($childName, strlen($namePrefix)));
            }
            $variants[] = $this->normalizeVariantRow([
                'relation_id' => 0,
                'parent_item_id' => $parentItemId,
                'variant_item_id' => (int) ($row['id'] ?? 0),
                'variant_label' => $label,
                'variant_name_en' => (string) ($row['name2'] ?? ''),
                'sort_order' => $sortOrder++,
                'is_default' => 0,
                'is_active' => 0,
                'iname' => $childName,
                'name2' => (string) ($row['name2'] ?? ''),
                'barcode' => (string) ($row['barcode'] ?? ''),
                'info' => (string) ($row['info'] ?? ''),
                'market_price' => (float) ($row['market_price'] ?? 0),
                'cost_price' => (float) ($row['cost_price'] ?? 0),
                'price1' => (float) ($row['price1'] ?? 0),
                'price2' => (float) ($row['price2'] ?? 0),
                'price3' => (float) ($row['price3'] ?? 0),
                'group1' => (int) ($row['group1'] ?? 0),
                'group2' => (int) ($row['group2'] ?? 0),
                'itmqty' => (float) ($row['itmqty'] ?? 0),
                'img_filename' => '',
                'is_unlinked_recovery' => 1,
            ]);
        }
        $stmt->close();

        return $variants;
    }

    public function saveVariantsFromPost(mysqli $conn, int $parentItemId, array $post, array $context = []): array
    {
        if ($parentItemId <= 0) {
            throw new InvalidArgumentException('parent item id is required');
        }

        $this->ensureSchema($conn);
        $parent = $this->loadItem($conn, $parentItemId);
        if (!$parent) {
            throw new RuntimeException('parent item not found');
        }

        $existingLinks = $this->existingLinksForParent($conn, $parentItemId);
        $rows = $this->postedRows($post);
        $seenRelationIds = [];
        $affectedItemIds = [$parentItemId];
        $activeRelationIds = [];
        $requestedDefaultRelationId = null;

        foreach ($rows as $position => $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $explicitName = trim((string) ($row['name'] ?? ''));
            $relationId = (int) ($row['relation_id'] ?? 0);
            $variantItemId = (int) ($row['variant_item_id'] ?? 0);
            $active = (int) ($row['active'] ?? 1) === 1;

            if ($label === '' && $explicitName === '' && $variantItemId <= 0) {
                continue;
            }
            if ($this->decimal($row['price1'] ?? 0) <= 0) {
                throw new InvalidArgumentException('variant_sell_price_required');
            }
            if ($label === '') {
                $label = $explicitName !== '' ? $explicitName : 'Variant ' . ($position + 1);
            }
            if ($variantItemId === $parentItemId) {
                $variantItemId = 0;
            }

            $existingLink = $relationId > 0 ? ($existingLinks[$relationId] ?? null) : null;
            $variantName = $this->resolveVariantDisplayName(
                (string) $parent['iname'],
                $label,
                $explicitName,
                $existingLink
            );
            $code = trim((string) ($row['code'] ?? ''));
            $barcode = trim((string) ($row['barcode'] ?? ''));
            if ($code === '' && $variantItemId > 0 && $this->itemExists($conn, $variantItemId)) {
                $existingVariant = $this->loadItem($conn, $variantItemId);
                $code = (string) ($existingVariant['code'] ?? '');
            }
            if ($code === '') {
                $code = (string) $this->nextNumericValue($conn, 'code');
            }
            if ($barcode === '') {
                $barcode = (string) $this->nextNumericValue($conn, 'barcode');
            }

            $prices = [
                'market_price' => $this->decimal($row['market_price'] ?? ($row['price3'] ?? 0)),
                'cost_price' => $this->decimal($row['cost_price'] ?? 0),
                'price1' => $this->decimal($row['price1'] ?? 0),
                'price2' => $this->decimal($row['price2'] ?? 0),
                'price3' => $this->decimal($row['price3'] ?? ($row['market_price'] ?? 0)),
            ];

            [$variantItemId, $relationId] = $this->resolveVariantChildIdentity(
                $conn,
                $parentItemId,
                $variantName,
                $variantItemId,
                $relationId,
                $existingLinks
            );

            if ($variantItemId > 0 && $this->itemExists($conn, $variantItemId)) {
                $this->updateVariantItem($conn, $variantItemId, $variantName, $parent, $code, $barcode, $prices, $context);
            } else {
                $variantItemId = $this->insertVariantItem($conn, $variantName, $parent, $code, $barcode, $prices, $context);
                $this->copyFirstImageIfMissing($conn, $parentItemId, $variantItemId);
            }

            $this->ensureSingleVariantUnit($conn, $parentItemId, $variantItemId, $barcode, $prices);
            $relationId = $this->upsertRelation(
                $conn,
                $relationId,
                $parentItemId,
                $variantItemId,
                $label,
                trim((string) ($row['name2'] ?? '')),
                (int) ($row['sort_order'] ?? ($position + 1)),
                $active,
                (int) ($row['is_default'] ?? 0) === 1
            );

            $seenRelationIds[] = $relationId;
            $affectedItemIds[] = $variantItemId;
            if ($active) {
                $activeRelationIds[] = $relationId;
                if ((int) ($row['is_default'] ?? 0) === 1 && $requestedDefaultRelationId === null) {
                    $requestedDefaultRelationId = $relationId;
                }
            }
        }

        foreach ($existingLinks as $relationId => $link) {
            if (!in_array($relationId, $seenRelationIds, true)) {
                $this->deactivateRelation($conn, $relationId);
                $affectedItemIds[] = (int) $link['variant_item_id'];
            }
        }

        $this->enforceSingleDefault($conn, $parentItemId, $requestedDefaultRelationId, $activeRelationIds);

        foreach ($this->repairUnlinkedChildrenForParent($conn, $parentItemId) as $repairedItemId) {
            $affectedItemIds[] = $repairedItemId;
        }

        return array_values(array_unique(array_filter($affectedItemIds, static function (int $itemId): bool {
            return $itemId > 0;
        })));
    }

    public function repairUnlinkedChildrenForParent(mysqli $conn, int $parentItemId): array
    {
        if ($parentItemId <= 0) {
            return [];
        }

        $this->ensureSchema($conn);
        $repairedItemIds = [];
        foreach ($this->unlinkedVariantChildrenForParent($conn, $parentItemId) as $unlinked) {
            $childId = (int) ($unlinked['variant_item_id'] ?? 0);
            if ($childId <= 0) {
                continue;
            }

            $existingParent = $this->variantParentForChild($conn, $childId);
            if ($existingParent !== null && (int) ($existingParent['parent_item_id'] ?? 0) !== $parentItemId) {
                continue;
            }

            $label = trim((string) ($unlinked['variant_label'] ?? ''));
            if ($label === '') {
                $label = 'Variant';
            }

            $relationId = $this->upsertRelation(
                $conn,
                0,
                $parentItemId,
                $childId,
                $label,
                trim((string) ($unlinked['variant_name_en'] ?? '')),
                (int) ($unlinked['sort_order'] ?? 1000),
                false,
                false
            );
            if ($relationId > 0) {
                $repairedItemIds[] = $childId;
            }
        }

        return $repairedItemIds;
    }

    public function parentIdsWithUnlinkedVariantChildren(mysqli $conn): array
    {
        $this->ensureSchema($conn);
        $result = $conn->query("
            SELECT DISTINCT parent.id AS parent_item_id
            FROM myitems parent
            INNER JOIN myitems child
                ON child.id != parent.id
               AND COALESCE(child.isdeleted, 0) = 0
               AND COALESCE(parent.isdeleted, 0) = 0
               AND TRIM(parent.iname) <> ''
               AND child.iname LIKE CONCAT(parent.iname, ' - %')
            WHERE NOT EXISTS (
                SELECT 1
                FROM item_variants iv
                WHERE iv.parent_item_id = parent.id
                  AND iv.variant_item_id = child.id
            )
            ORDER BY parent.id ASC
        ");
        if (!$result) {
            return [];
        }

        $parentIds = [];
        while ($row = $result->fetch_assoc()) {
            $parentId = (int) ($row['parent_item_id'] ?? 0);
            if ($parentId > 0) {
                $parentIds[] = $parentId;
            }
        }

        return $parentIds;
    }

    public function repairAllUnlinkedVariantLinks(mysqli $conn): array
    {
        $summary = [
            'parents_scanned' => 0,
            'children_linked' => 0,
            'parent_item_ids' => [],
        ];

        foreach ($this->parentIdsWithUnlinkedVariantChildren($conn) as $parentItemId) {
            $summary['parents_scanned']++;
            $repaired = $this->repairUnlinkedChildrenForParent($conn, $parentItemId);
            if ($repaired) {
                $summary['parent_item_ids'][] = $parentItemId;
                $summary['children_linked'] += count($repaired);
            }
        }

        return $summary;
    }

    public function softDeleteParentAndVariantFamily(mysqli $conn, int $parentItemId): array
    {
        if ($parentItemId <= 0) {
            return [];
        }

        $this->ensureSchema($conn);
        $deletedItemIds = [];
        $childIds = $this->variantChildIdsForParent($conn, $parentItemId, true);

        foreach ($this->unlinkedVariantChildrenForParent($conn, $parentItemId) as $unlinked) {
            $childId = (int) ($unlinked['variant_item_id'] ?? 0);
            if ($childId > 0) {
                $childIds[$childId] = true;
            }
        }

        foreach (array_keys($childIds) as $childId) {
            if ($this->softDeleteItem($conn, (int) $childId)) {
                $deletedItemIds[] = (int) $childId;
            }
        }

        $stmt = $conn->prepare('UPDATE item_variants SET is_active = 0, is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE parent_item_id = ?');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $stmt->close();

        if ($this->softDeleteItem($conn, $parentItemId)) {
            $deletedItemIds[] = $parentItemId;
        }

        return array_values(array_unique($deletedItemIds));
    }

    public function softDeleteVariantChild(mysqli $conn, int $variantItemId): array
    {
        if ($variantItemId <= 0) {
            return [];
        }

        $this->ensureSchema($conn);
        $deletedItemIds = [];
        if ($this->softDeleteItem($conn, $variantItemId)) {
            $deletedItemIds[] = $variantItemId;
        }

        $stmt = $conn->prepare('UPDATE item_variants SET is_active = 0, is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE variant_item_id = ?');
        $stmt->bind_param('i', $variantItemId);
        $stmt->execute();
        $stmt->close();

        return $deletedItemIds;
    }

    public function isVariantChildId(mysqli $conn, int $itemId): bool
    {
        return $this->variantParentForChild($conn, $itemId) !== null;
    }

    private function normalizeVariantRow(array $row): array
    {
        return [
            'relation_id' => (int) ($row['relation_id'] ?? 0),
            'parent_item_id' => (int) ($row['parent_item_id'] ?? 0),
            'variant_item_id' => (int) ($row['variant_item_id'] ?? 0),
            'item_id' => (int) ($row['variant_item_id'] ?? 0),
            'id' => (int) ($row['variant_item_id'] ?? 0),
            'variant_label' => (string) ($row['variant_label'] ?? ''),
            'label' => (string) ($row['variant_label'] ?? ''),
            'variant_name_en' => (string) ($row['variant_name_en'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
            'iname' => (string) ($row['iname'] ?? ''),
            'name' => (string) ($row['iname'] ?? ''),
            'name2' => (string) ($row['name2'] ?? ''),
            'barcode' => (string) ($row['barcode'] ?? ''),
            'info' => (string) ($row['info'] ?? ''),
            'market_price' => (float) ($row['market_price'] ?? 0),
            'cost_price' => (float) ($row['cost_price'] ?? 0),
            'price' => (float) ($row['price1'] ?? 0),
            'price1' => (float) ($row['price1'] ?? 0),
            'price2' => (float) ($row['price2'] ?? 0),
            'price3' => (float) ($row['price3'] ?? 0),
            'group1' => (int) ($row['group1'] ?? 0),
            'group2' => (int) ($row['group2'] ?? 0),
            'itmqty' => (float) ($row['itmqty'] ?? 0),
            'img_filename' => (string) ($row['img_filename'] ?? ''),
            'is_unlinked_recovery' => (int) ($row['is_unlinked_recovery'] ?? 0) === 1,
        ];
    }

    private function postedRows(array $post): array
    {
        $labels = $this->arrayValue($post, 'variant_label');
        $max = count($labels);
        foreach (['variant_item_id', 'variant_name', 'variant_barcode', 'variant_price1', 'variant_price2', 'variant_price3', 'variant_market_price'] as $key) {
            $max = max($max, count($this->arrayValue($post, $key)));
        }

        $rows = [];
        for ($index = 0; $index < $max; $index++) {
            $rows[] = [
                'relation_id' => $this->arrayValue($post, 'variant_link_id')[$index] ?? 0,
                'variant_item_id' => $this->arrayValue($post, 'variant_item_id')[$index] ?? 0,
                'label' => $this->arrayValue($post, 'variant_label')[$index] ?? '',
                'name' => $this->arrayValue($post, 'variant_name')[$index] ?? '',
                'name2' => $this->arrayValue($post, 'variant_name_en')[$index] ?? '',
                'code' => $this->arrayValue($post, 'variant_code')[$index] ?? '',
                'barcode' => $this->arrayValue($post, 'variant_barcode')[$index] ?? '',
                'cost_price' => $this->arrayValue($post, 'variant_cost_price')[$index] ?? 0,
                'price1' => $this->arrayValue($post, 'variant_price1')[$index] ?? 0,
                'price2' => 0,
                'price3' => 0,
                'market_price' => 0,
                'active' => $this->arrayValue($post, 'variant_active')[$index] ?? 1,
                'is_default' => $this->arrayValue($post, 'variant_default')[$index] ?? 0,
                'sort_order' => $this->arrayValue($post, 'variant_sort')[$index] ?? ($index + 1),
            ];
        }

        return $rows;
    }

    private function arrayValue(array $post, string $key): array
    {
        return isset($post[$key]) && is_array($post[$key]) ? $post[$key] : [];
    }

    private function loadItem(mysqli $conn, int $itemId): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function itemExists(mysqli $conn, int $itemId): bool
    {
        $stmt = $conn->prepare('SELECT 1 FROM myitems WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    private function insertVariantItem(mysqli $conn, string $variantName, array $parent, string $code, string $barcode, array $prices, array $context): int
    {
        $this->assertVariantItemIdentityAvailable($conn, $variantName, $barcode, 0);

        $stmt = $conn->prepare("
            INSERT INTO myitems (
                iname, name2, code, barcode, info, market_price, cost_price, price1, price2, price3,
                group1, group2, user, isdeleted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $name2 = (string) ($parent['name2'] ?? '');
        $info = (string) ($parent['info'] ?? '');
        $marketPrice = $prices['market_price'];
        $costPrice = $prices['cost_price'];
        $price1 = $prices['price1'];
        $price2 = $prices['price2'];
        $price3 = $prices['price3'];
        $group1 = (int) ($parent['group1'] ?? 0);
        $group2 = (int) ($parent['group2'] ?? 0);
        $user = (int) ($context['user_id'] ?? ($parent['user'] ?? 1));
        if ($user <= 0) {
            $user = 1;
        }
        $stmt->bind_param(
            'sssssdddddiii',
            $variantName,
            $name2,
            $code,
            $barcode,
            $info,
            $marketPrice,
            $costPrice,
            $price1,
            $price2,
            $price3,
            $group1,
            $group2,
            $user
        );
        $stmt->execute();
        $itemId = (int) $conn->insert_id;
        $stmt->close();

        return $itemId;
    }

    private function updateVariantItem(mysqli $conn, int $variantItemId, string $variantName, array $parent, string $code, string $barcode, array $prices, array $context): void
    {
        unset($context);
        $this->assertVariantItemIdentityAvailable($conn, $variantName, $barcode, $variantItemId);

        $stmt = $conn->prepare("
            UPDATE myitems
            SET iname = ?,
                name2 = ?,
                code = ?,
                barcode = ?,
                info = ?,
                market_price = ?,
                cost_price = ?,
                price1 = ?,
                price2 = ?,
                price3 = ?,
                group1 = ?,
                group2 = ?,
                isdeleted = 0
            WHERE id = ?
        ");
        $name2 = (string) ($parent['name2'] ?? '');
        $info = (string) ($parent['info'] ?? '');
        $marketPrice = $prices['market_price'];
        $costPrice = $prices['cost_price'];
        $price1 = $prices['price1'];
        $price2 = $prices['price2'];
        $price3 = $prices['price3'];
        $group1 = (int) ($parent['group1'] ?? 0);
        $group2 = (int) ($parent['group2'] ?? 0);
        $stmt->bind_param(
            'sssssdddddiii',
            $variantName,
            $name2,
            $code,
            $barcode,
            $info,
            $marketPrice,
            $costPrice,
            $price1,
            $price2,
            $price3,
            $group1,
            $group2,
            $variantItemId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function ensureSingleVariantUnit(mysqli $conn, int $parentItemId, int $variantItemId, string $barcode, array $prices): void
    {
        if (!$this->tableExists($conn, 'item_units')) {
            return;
        }

        ItemUnitColumnSupport::ensureDefFlags($conn);
        $this->cloneParentUnitsForVariant($conn, $parentItemId, $variantItemId, $barcode, $prices);
    }

    private function cloneParentUnitsForVariant(mysqli $conn, int $parentItemId, int $variantItemId, string $barcode, array $prices): void
    {
        $parentRows = $this->parentUnitRows($conn, $parentItemId);
        if (!$parentRows) {
            return;
        }

        $delete = $conn->prepare('DELETE FROM item_units WHERE item_id = ?');
        $delete->bind_param('i', $variantItemId);
        $delete->execute();
        $delete->close();

        $hasDefFlags = ItemUnitColumnSupport::hasDefFlags($conn);
        $insertSql = $hasDefFlags
            ? 'INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3, def_sale, def_buy, def_stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($insertSql);

        foreach ($parentRows as $row) {
            $unitId = (int) ($row['unit_id'] ?? 0);
            if ($unitId < 1) {
                continue;
            }

            $uVal = (float) ($row['u_val'] ?? 1);
            $unitBarcode = (string) ($row['unit_barcode'] ?? '');
            $cost = (float) ($row['cost_price'] ?? 0);
            $price1 = (float) ($row['price1'] ?? 0);
            $price2 = (float) ($row['price2'] ?? 0);
            $price3 = (float) ($row['price3'] ?? 0);
            $defSale = (int) ($row['def_sale'] ?? 0);
            $defBuy = (int) ($row['def_buy'] ?? 0);
            $defStock = (int) ($row['def_stock'] ?? 0);

            if ($defSale === 1) {
                $price1 = (float) ($prices['price1'] ?? $price1);
                $price2 = (float) ($prices['price2'] ?? $price2);
                $price3 = (float) ($prices['price3'] ?? ($prices['market_price'] ?? $price3));
                if ($barcode !== '') {
                    $unitBarcode = $barcode;
                }
            }

            if ($hasDefFlags) {
                $stmt->bind_param('iidsddddiii', $variantItemId, $unitId, $uVal, $unitBarcode, $cost, $price1, $price2, $price3, $defSale, $defBuy, $defStock);
            } else {
                $stmt->bind_param('iidsdddd', $variantItemId, $unitId, $uVal, $unitBarcode, $cost, $price1, $price2, $price3);
            }
            $stmt->execute();
        }

        $stmt->close();
    }

    private function parentUnitRows(mysqli $conn, int $parentItemId): array
    {
        $stmt = $conn->prepare('SELECT * FROM item_units WHERE item_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return is_array($rows) ? $rows : [];
    }

    private function parentDefaultUnitId(mysqli $conn, int $parentItemId): int
    {
        $stock = ItemUnitResolver::stockRowForItem($conn, $parentItemId);
        if ($stock && (int) ($stock['unit_id'] ?? 0) > 0) {
            return (int) $stock['unit_id'];
        }

        $stmt = $conn->prepare('SELECT unit_id FROM item_units WHERE item_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && (int) $row['unit_id'] > 0) {
            return (int) $row['unit_id'];
        }

        if ($this->tableExists($conn, 'myunits')) {
            $row = $conn->query("SELECT id FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY id LIMIT 1")->fetch_assoc();
            if ($row && (int) $row['id'] > 0) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    private function upsertRelation(
        mysqli $conn,
        int $relationId,
        int $parentItemId,
        int $variantItemId,
        string $label,
        string $nameEn,
        int $sortOrder,
        bool $active,
        bool $isDefault
    ): int {
        $activeInt = $active ? 1 : 0;
        $defaultInt = $isDefault ? 1 : 0;
        if ($relationId > 0) {
            $stmt = $conn->prepare("
                UPDATE item_variants
                SET parent_item_id = ?,
                    variant_item_id = ?,
                    variant_label = ?,
                    variant_name_en = ?,
                    sort_order = ?,
                    is_default = ?,
                    is_active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->bind_param('iissiiii', $parentItemId, $variantItemId, $label, $nameEn, $sortOrder, $defaultInt, $activeInt, $relationId);
            $stmt->execute();
            $stmt->close();

            return $relationId;
        }

        $stmt = $conn->prepare("
            INSERT INTO item_variants (
                parent_item_id, variant_item_id, variant_label, variant_name_en, sort_order, is_default, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                parent_item_id = VALUES(parent_item_id),
                variant_label = VALUES(variant_label),
                variant_name_en = VALUES(variant_name_en),
                sort_order = VALUES(sort_order),
                is_default = VALUES(is_default),
                is_active = VALUES(is_active),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('iissiii', $parentItemId, $variantItemId, $label, $nameEn, $sortOrder, $defaultInt, $activeInt);
        $stmt->execute();
        $newRelationId = (int) $conn->insert_id;
        $stmt->close();

        return $newRelationId;
    }

    private function existingLinksForParent(mysqli $conn, int $parentItemId): array
    {
        $stmt = $conn->prepare('
            SELECT iv.id, iv.variant_item_id, iv.variant_label, child.iname AS variant_iname
            FROM item_variants iv
            JOIN myitems child ON child.id = iv.variant_item_id
            WHERE iv.parent_item_id = ?
        ');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $links = [];
        while ($row = $result->fetch_assoc()) {
            $links[(int) $row['id']] = [
                'variant_item_id' => (int) $row['variant_item_id'],
                'variant_label' => (string) ($row['variant_label'] ?? ''),
                'variant_iname' => (string) ($row['variant_iname'] ?? ''),
            ];
        }
        $stmt->close();

        return $links;
    }

    private function deactivateRelation(mysqli $conn, int $relationId): void
    {
        $stmt = $conn->prepare('UPDATE item_variants SET is_active = 0, is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('i', $relationId);
        $stmt->execute();
        $stmt->close();
    }

    private function enforceSingleDefault(mysqli $conn, int $parentItemId, ?int $requestedDefaultRelationId, array $activeRelationIds): void
    {
        $stmt = $conn->prepare('UPDATE item_variants SET is_default = 0 WHERE parent_item_id = ?');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $stmt->close();

        $defaultRelationId = $requestedDefaultRelationId ?: ($activeRelationIds[0] ?? null);
        if (!$defaultRelationId) {
            return;
        }

        $stmt = $conn->prepare('UPDATE item_variants SET is_default = 1 WHERE id = ? AND parent_item_id = ? AND is_active = 1');
        $stmt->bind_param('ii', $defaultRelationId, $parentItemId);
        $stmt->execute();
        $stmt->close();
    }

    private function copyFirstImageIfMissing(mysqli $conn, int $parentItemId, int $variantItemId): void
    {
        if (!$this->tableExists($conn, 'imgs')) {
            return;
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM imgs WHERE itemid = ? AND COALESCE(isdeleted, 0) = 0');
        $stmt->bind_param('i', $variantItemId);
        $stmt->execute();
        $countRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int) ($countRow['c'] ?? 0) > 0) {
            return;
        }

        $stmt = $conn->prepare('SELECT iname FROM imgs WHERE itemid = ? AND COALESCE(isdeleted, 0) = 0 ORDER BY id ASC LIMIT 1');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $imageRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $imageName = trim((string) ($imageRow['iname'] ?? ''));
        if ($imageName === '') {
            return;
        }

        $uploadsDir = dirname(__DIR__, 3) . '/uploads';
        $imagePath = $uploadsDir . '/' . $imageName;
        $imageSize = is_file($imagePath) ? (int) filesize($imagePath) : 0;

        $stmt = $conn->prepare('INSERT INTO imgs (iname, itemid, size) VALUES (?, ?, ?)');
        $stmt->bind_param('sii', $imageName, $variantItemId, $imageSize);
        $stmt->execute();
        $stmt->close();
    }

    private function resolveVariantDisplayName(string $parentName, string $label, string $explicitName, ?array $existingLink): string
    {
        $generatedName = $this->generatedVariantName($parentName, $label);
        if ($explicitName === '') {
            return $generatedName;
        }
        if ($explicitName === $generatedName) {
            return $generatedName;
        }
        if (!is_array($existingLink)) {
            return $explicitName;
        }

        $oldLabel = trim((string) ($existingLink['variant_label'] ?? ''));
        $existingChildName = trim((string) ($existingLink['variant_iname'] ?? ''));
        if ($oldLabel !== '' && $oldLabel !== $label) {
            if ($existingChildName !== '' && $explicitName === $existingChildName) {
                return $generatedName;
            }

            $oldGenerated = $this->generatedVariantName($parentName, $oldLabel);
            if ($explicitName === $oldGenerated) {
                return $generatedName;
            }
            if ($existingChildName !== '' && $explicitName === $existingChildName && $existingChildName === $oldGenerated) {
                return $generatedName;
            }
        }

        return $explicitName;
    }

    private function generatedVariantName(string $parentName, string $label): string
    {
        $parentName = trim($parentName);
        $label = trim($label);
        if ($parentName === '') {
            return $label;
        }
        if ($label === '') {
            return $parentName;
        }
        if (stripos($label, $parentName) === 0) {
            return $label;
        }

        return $parentName . ' - ' . $label;
    }

    private function resolveVariantChildIdentity(
        mysqli $conn,
        int $parentItemId,
        string $variantName,
        int $variantItemId,
        int $relationId,
        array $existingLinks
    ): array {
        $adoptedItemId = $this->adoptableVariantChildId($conn, $parentItemId, $variantName, $variantItemId);
        if ($adoptedItemId <= 0) {
            return [$variantItemId, $relationId];
        }

        $relationForAdopted = $this->relationIdForVariantChildOnParent($existingLinks, $adoptedItemId);

        return [$adoptedItemId, $relationForAdopted > 0 ? $relationForAdopted : $relationId];
    }

    private function adoptableVariantChildId(mysqli $conn, int $parentItemId, string $targetIname, int $currentVariantItemId): int
    {
        $targetIname = trim($targetIname);
        if ($targetIname === '') {
            return 0;
        }

        $conflictId = $this->conflictingItemIdByName($conn, $targetIname, $currentVariantItemId);
        if ($conflictId <= 0) {
            return 0;
        }

        $parentLink = $this->variantParentForChild($conn, $conflictId);
        if ($parentLink === null) {
            return $this->itemBelongsToParentVariantFamily($conn, $parentItemId, $targetIname) ? $conflictId : 0;
        }

        return (int) ($parentLink['parent_item_id'] ?? 0) === $parentItemId ? $conflictId : 0;
    }

    private function itemBelongsToParentVariantFamily(mysqli $conn, int $parentItemId, string $iname): bool
    {
        $parent = $this->loadItem($conn, $parentItemId);
        if (!$parent) {
            return false;
        }

        $parentName = trim((string) ($parent['iname'] ?? ''));
        if ($parentName === '') {
            return false;
        }

        $prefix = $parentName . ' - ';

        return stripos(trim($iname), $prefix) === 0;
    }

    private function relationIdForVariantChildOnParent(array $existingLinks, int $variantItemId): int
    {
        foreach ($existingLinks as $relationId => $link) {
            if ((int) ($link['variant_item_id'] ?? 0) === $variantItemId) {
                return (int) $relationId;
            }
        }

        return 0;
    }

    private function variantChildIdsForParent(mysqli $conn, int $parentItemId, bool $includeDeletedChildren): array
    {
        if ($parentItemId <= 0) {
            return [];
        }

        $deletedSql = $includeDeletedChildren ? '' : ' AND COALESCE(child.isdeleted, 0) = 0';
        $stmt = $conn->prepare("
            SELECT iv.variant_item_id
            FROM item_variants iv
            JOIN myitems child ON child.id = iv.variant_item_id
            WHERE iv.parent_item_id = ?
              {$deletedSql}
        ");
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $childIds = [];
        while ($row = $result->fetch_assoc()) {
            $childId = (int) ($row['variant_item_id'] ?? 0);
            if ($childId > 0) {
                $childIds[$childId] = true;
            }
        }
        $stmt->close();

        return $childIds;
    }

    private function softDeleteItem(mysqli $conn, int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        $stmt = $conn->prepare('UPDATE myitems SET isdeleted = 1 WHERE id = ? AND COALESCE(isdeleted, 0) = 0');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        return $changed;
    }

    private function assertVariantItemIdentityAvailable(mysqli $conn, string $iname, string $barcode, int $excludeItemId): void
    {
        $iname = trim($iname);
        if ($iname !== '' && $this->conflictingItemIdByName($conn, $iname, $excludeItemId) > 0) {
            throw new InvalidArgumentException('duplicate_item_name');
        }

        $barcode = trim($barcode);
        if ($barcode !== '' && $this->conflictingItemIdByBarcode($conn, $barcode, $excludeItemId) > 0) {
            throw new InvalidArgumentException('duplicate_item_barcode');
        }
    }

    private function conflictingItemIdByName(mysqli $conn, string $iname, int $excludeItemId): int
    {
        $stmt = $conn->prepare('SELECT id FROM myitems WHERE iname = ? AND id != ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
        $stmt->bind_param('si', $iname, $excludeItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['id'] ?? 0);
    }

    private function conflictingItemIdByBarcode(mysqli $conn, string $barcode, int $excludeItemId): int
    {
        $stmt = $conn->prepare('SELECT id FROM myitems WHERE barcode = ? AND id != ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
        $stmt->bind_param('si', $barcode, $excludeItemId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['id'] ?? 0);
    }

    private function nextNumericValue(mysqli $conn, string $column): int
    {
        if (!in_array($column, ['code', 'barcode'], true)) {
            throw new InvalidArgumentException('unsupported numeric item column');
        }

        $row = $conn->query("SELECT MAX(CAST({$column} AS UNSIGNED)) AS max_value FROM myitems WHERE {$column} REGEXP '^[0-9]+$'")->fetch_assoc();

        return ((int) ($row['max_value'] ?? 0)) + 1;
    }

    private function decimal($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        $safeTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");

        return $result && $result->num_rows > 0;
    }
}
