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
