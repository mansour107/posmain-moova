<?php

require_once __DIR__ . '/OperationalSyncDomains.php';

class CloudOperationalMirrorService
{
    private array $columnCache = [];

    public function applyFromBranchEvent(mysqli $conn, string $branchUuid, array $event): ?array
    {
        $payload = $this->payload($event);
        $snapshotType = (string) ($payload['snapshot_type'] ?? '');

        if ($snapshotType === 'operational_row') {
            return $this->mirrorOperationalRow($conn, $payload);
        }

        if ($snapshotType === 'operational_delete') {
            return $this->mirrorOperationalDelete($conn, $payload);
        }

        if ($snapshotType === 'recipe_bundle') {
            return $this->mirrorRecipeBundle($conn, $payload);
        }

        if ($snapshotType === 'shop_settings') {
            return $this->mirrorShopSettings($conn, $payload);
        }

        if ($snapshotType === 'modifier_group_bundle') {
            return $this->mirrorModifierGroupBundle($conn, $payload);
        }

        if ($snapshotType === 'moova_shop_link') {
            return $this->mirrorMoovaShopLink($conn, $payload);
        }

        if ($snapshotType === 'shift_close') {
            return $this->mirrorShiftClose($conn, $payload);
        }

        return null;
    }

    private function mirrorOperationalRow(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $row = $payload['row'] ?? null;
        if ($table === '' || !is_array($row) || empty($row['id'])) {
            return null;
        }

        $domain = (string) ($payload['domain'] ?? '');
        $definition = $domain !== '' ? OperationalSyncDomains::get($domain) : null;
        if ($definition) {
            $row = $this->sanitizeRow($row, $definition['exclude_columns'] ?? []);
        }

        $this->upsertRow($conn, $table, $row);

        return ['entity_id' => $table . ':' . (int) $row['id']];
    }

    private function mirrorOperationalDelete(mysqli $conn, array $payload): ?array
    {
        $table = (string) ($payload['table'] ?? '');
        $rowId = (int) ($payload['row_id'] ?? 0);
        if ($table === '' || $rowId <= 0 || !$this->tableExists($conn, $table)) {
            return null;
        }

        $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE id = ?");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $stmt->close();

        return ['entity_id' => $table . ':' . $rowId, 'deleted' => true];
    }

    private function mirrorRecipeBundle(mysqli $conn, array $payload): ?array
    {
        $header = $payload['header'] ?? null;
        if (!is_array($header) || empty($header['id'])) {
            return null;
        }

        $recipeId = (int) $header['id'];
        $this->upsertRow($conn, 'recipe_headers', $header);

        if ($this->tableExists($conn, 'recipe_lines')) {
            $conn->query('DELETE FROM recipe_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_variant_lines')) {
            $conn->query('DELETE FROM recipe_variant_lines WHERE recipe_id = ' . $recipeId);
            foreach ($payload['variant_lines'] ?? [] as $line) {
                if (!is_array($line) || empty($line['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_variant_lines', $line);
            }
        }

        if ($this->tableExists($conn, 'recipe_cost_snapshots')) {
            foreach ($payload['cost_snapshots'] ?? [] as $snapshot) {
                if (!is_array($snapshot) || empty($snapshot['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'recipe_cost_snapshots', $snapshot);
            }
        }

        return ['entity_id' => 'recipe_headers:' . $recipeId];
    }

    private function mirrorShopSettings(mysqli $conn, array $payload): ?array
    {
        $settings = $payload['settings'] ?? null;
        if (!is_array($settings)) {
            return null;
        }

        $settings['id'] = 1;
        $this->upsertRow($conn, 'settings', $settings);

        return ['entity_id' => 'settings:1'];
    }

    private function mirrorModifierGroupBundle(mysqli $conn, array $payload): ?array
    {
        $group = $payload['group'] ?? null;
        if (!is_array($group) || empty($group['id'])) {
            return null;
        }

        $groupId = (int) $group['id'];
        $this->upsertRow($conn, 'modifier_groups', $group);

        if ($this->tableExists($conn, 'modifier_options')) {
            foreach ($payload['options'] ?? [] as $option) {
                if (!is_array($option) || empty($option['id'])) {
                    continue;
                }
                $this->upsertRow($conn, 'modifier_options', $option);
            }
        }

        if ($this->tableExists($conn, 'item_modifier_groups')) {
            foreach ($payload['item_links'] ?? [] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $this->upsertJunctionRow($conn, 'item_modifier_groups', $link, ['item_id', 'group_id']);
            }
        }

        return ['entity_id' => 'modifier_groups:' . $groupId];
    }

    private function mirrorMoovaShopLink(mysqli $conn, array $payload): ?array
    {
        $link = $payload['link'] ?? null;
        if (!is_array($link) || empty($link['id'])) {
            return null;
        }

        $this->upsertRow($conn, 'moova_pos_shop_links', $link);

        return ['entity_id' => 'moova_pos_shop_links:' . (int) $link['id']];
    }

    private function mirrorShiftClose(mysqli $conn, array $payload): ?array
    {
        $shift = $payload['shift'] ?? null;
        if (!is_array($shift)) {
            return null;
        }

        $legacy = $shift['legacy'] ?? null;
        if (is_array($legacy) && !empty($legacy['id'])) {
            $this->upsertRow($conn, 'closed_orders', $legacy);

            return ['entity_id' => 'closed_orders:' . (int) $legacy['id']];
        }

        $closedOrderId = (int) ($shift['local_closed_order_id'] ?? 0);
        if ($closedOrderId <= 0 || !$this->tableExists($conn, 'closed_orders')) {
            return null;
        }

        $stmt = $conn->prepare('SELECT * FROM closed_orders WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $closedOrderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $this->upsertRow($conn, 'closed_orders', $row);

        return ['entity_id' => 'closed_orders:' . $closedOrderId];
    }

    private function upsertJunctionRow(mysqli $conn, string $table, array $row, array $keyColumns): void
    {
        if (!$this->tableExists($conn, $table)) {
            return;
        }

        foreach ($keyColumns as $column) {
            if (empty($row[$column])) {
                return;
            }
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function upsertRow(mysqli $conn, string $table, array $row): void
    {
        if (!$this->tableExists($conn, $table) || empty($row['id'])) {
            return;
        }

        $columns = $this->tableColumns($conn, $table);
        $fields = [];
        $values = [];
        foreach ($row as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $fields[] = '`' . $column . '`';
            $values[] = $value;
        }

        if ($fields === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $updates = [];
        foreach ($fields as $field) {
            if ($field === '`id`') {
                continue;
            }
            $updates[] = $field . ' = VALUES(' . $field . ')';
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
        if ($updates !== []) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        }

        $stmt = $conn->prepare($sql);
        $types = str_repeat('s', count($values));
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function sanitizeRow(array $row, array $excludeColumns): array
    {
        foreach ($excludeColumns as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function payload(array $event): array
    {
        $payload = $event['payload'] ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $result && $result->num_rows > 0;
    }

    private function tableColumns(mysqli $conn, string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $columns = [];
        $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        while ($row = $result->fetch_assoc()) {
            $columns[] = (string) $row['Field'];
        }
        $this->columnCache[$table] = $columns;

        return $columns;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $key => &$value) {
            $refs[$key] = &$value;
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
