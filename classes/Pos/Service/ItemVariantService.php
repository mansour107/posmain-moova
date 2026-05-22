<?php

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
            if ($label === '') {
                $label = $explicitName !== '' ? $explicitName : 'Variant ' . ($position + 1);
            }
            if ($variantItemId === $parentItemId) {
                $variantItemId = 0;
            }

            $variantName = $explicitName !== ''
                ? $explicitName
                : $this->generatedVariantName((string) $parent['iname'], $label);
            $code = trim((string) ($row['code'] ?? ''));
            $barcode = trim((string) ($row['barcode'] ?? ''));
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

        return array_values(array_unique(array_filter($affectedItemIds, static function (int $itemId): bool {
            return $itemId > 0;
        })));
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
            'code' => (string) ($row['code'] ?? ''),
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
                'price2' => $this->arrayValue($post, 'variant_price2')[$index] ?? 0,
                'price3' => $this->arrayValue($post, 'variant_price3')[$index] ?? ($this->arrayValue($post, 'variant_market_price')[$index] ?? 0),
                'market_price' => $this->arrayValue($post, 'variant_market_price')[$index] ?? ($this->arrayValue($post, 'variant_price3')[$index] ?? 0),
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

        $unitId = $this->parentDefaultUnitId($conn, $parentItemId);
        if ($unitId <= 0) {
            return;
        }

        $delete = $conn->prepare('DELETE FROM item_units WHERE item_id = ?');
        $delete->bind_param('i', $variantItemId);
        $delete->execute();
        $delete->close();

        $uVal = 1.0;
        $stmt = $conn->prepare("
            INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $cost = $prices['cost_price'];
        $price1 = $prices['price1'];
        $price2 = $prices['price2'];
        $price3 = $prices['price3'];
        $stmt->bind_param('iidsdddd', $variantItemId, $unitId, $uVal, $barcode, $cost, $price1, $price2, $price3);
        $stmt->execute();
        $stmt->close();
    }

    private function parentDefaultUnitId(mysqli $conn, int $parentItemId): int
    {
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
        $stmt = $conn->prepare('SELECT id, variant_item_id FROM item_variants WHERE parent_item_id = ?');
        $stmt->bind_param('i', $parentItemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $links = [];
        while ($row = $result->fetch_assoc()) {
            $links[(int) $row['id']] = [
                'variant_item_id' => (int) $row['variant_item_id'],
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

        $stmt = $conn->prepare('INSERT INTO imgs (iname, itemid) VALUES (?, ?)');
        $stmt->bind_param('si', $imageName, $variantItemId);
        $stmt->execute();
        $stmt->close();
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
