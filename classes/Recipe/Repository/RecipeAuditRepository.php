<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';

class RecipeAuditRepository extends RecipeRepositoryBase
{
    public function log(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'recipe_id' => null,
            'entity_id' => null,
            'before_json' => null,
            'after_json' => null,
            'actor_user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['action'] = trim((string) ($row['action'] ?? ''));
        $row['entity_type'] = trim((string) ($row['entity_type'] ?? ''));
        $row['branch_uuid'] = $this->nullableTrimmed($row['branch_uuid'] ?? null, 36);
        $row['ip_address'] = $this->nullableTrimmed($row['ip_address'] ?? null, 64);
        $row['user_agent'] = $this->nullableTrimmed($row['user_agent'] ?? null, 255);
        $this->assertValidAuditRow($row);

        return $this->insertRow($conn, 'recipe_audit_log', $row);
    }

    public function findForRecipe(mysqli $conn, int $posTenant, int $posBranch, int $recipeId): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_audit_log
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND recipe_id = ?
ORDER BY created_at, id",
            [$posTenant, $posBranch, $recipeId]
        );
    }

    public function search(mysqli $conn, array $filters = []): array
    {
        if (!$this->tableExists($conn)) {
            return [];
        }

        $conditions = ['1 = 1'];
        $params = [];
        foreach (['pos_tenant', 'pos_branch', 'recipe_id', 'actor_user_id'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        foreach (['action', 'entity_type'] as $column) {
            $value = trim((string) ($filters[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            $conditions[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);
        if ($dateFrom !== null) {
            $conditions[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = $this->normalizeDate($filters['date_to'] ?? null);
        if ($dateTo !== null) {
            $conditions[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $limit = isset($filters['limit']) ? max(1, min(5000, (int) $filters['limit'])) : 1000;

        return $this->fetchAll(
            $conn,
            'SELECT * FROM recipe_audit_log WHERE ' . implode(' AND ', $conditions) . " ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            $params
        );
    }

    public function distinctValues(mysqli $conn, string $column): array
    {
        if (!$this->tableExists($conn) || !in_array($column, ['action', 'entity_type'], true)) {
            return [];
        }

        $rows = $this->fetchAll(
            $conn,
            'SELECT DISTINCT ' . $this->quoteIdentifier($column) . ' AS value FROM recipe_audit_log ORDER BY ' . $this->quoteIdentifier($column)
        );
        $values = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function tableExists(mysqli $conn): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'recipe_audit_log'"
        );

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function normalizeDate($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    private function assertValidAuditRow(array $row): void
    {
        foreach (['pos_tenant', 'pos_branch'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' cannot be negative.');
            }
        }

        foreach (['recipe_id', 'entity_id', 'actor_user_id'] as $field) {
            if ($row[$field] !== null && $row[$field] !== '' && (int) $row[$field] < 1) {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' must be positive when provided.');
            }
        }

        $requiredMessages = [
            'action' => 'Recipe audit action is required.',
            'entity_type' => 'Recipe audit entity_type is required.',
        ];
        foreach (['action', 'entity_type'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if ($value === '') {
                throw new InvalidArgumentException($requiredMessages[$field]);
            }
            if (strlen($value) > 64) {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' is too long.');
            }
            if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $value)) {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' contains invalid characters.');
            }
        }

        foreach (['before_json', 'after_json'] as $field) {
            $value = $row[$field] ?? null;
            if ($value === null) {
                continue;
            }
            $json = trim((string) $value);
            if ($json === '') {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' must be valid JSON when provided.');
            }
            json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Recipe audit ' . $field . ' must be valid JSON when provided.');
            }
        }
    }

    private function nullableTrimmed($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return substr($text, 0, $maxLength);
    }
}
