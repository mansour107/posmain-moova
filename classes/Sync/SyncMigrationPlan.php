<?php

class SyncMigrationPlan
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_ADDITIVE = 'additive';
    public const SCOPE_REVIEWED = 'reviewed';

    public const ADDITIVE = 'additive';
    public const REWRITE = 'rewrite';
    public const DESTRUCTIVE = 'destructive';
    public const AMBIGUOUS = 'ambiguous';

    public function classify(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return self::AMBIGUOUS;
        }

        if (preg_match('/^\s*(DROP\b|TRUNCATE\b|DELETE\s+FROM\b)/i', $sql) === 1) {
            return self::DESTRUCTIVE;
        }
        if (
            preg_match('/^\s*ALTER\s+TABLE\b/is', $sql) === 1
            && preg_match('/\bDROP\s+(COLUMN|INDEX|KEY|CONSTRAINT|FOREIGN\s+KEY|PRIMARY\s+KEY)\b/i', $sql) === 1
        ) {
            return self::DESTRUCTIVE;
        }

        if (preg_match('/^\s*(UPDATE\b|INSERT\s+INTO\b|REPLACE\s+INTO\b)/i', $sql) === 1) {
            return self::REWRITE;
        }
        if (
            preg_match('/^\s*ALTER\s+TABLE\b/is', $sql) === 1
            && preg_match('/\b(MODIFY|CHANGE|RENAME)\b/i', $sql) === 1
        ) {
            return self::REWRITE;
        }

        if (preg_match('/^\s*CREATE\s+(TABLE|INDEX|UNIQUE\s+INDEX)\b/i', $sql) === 1) {
            return self::ADDITIVE;
        }
        if (
            preg_match('/^\s*ALTER\s+TABLE\b/is', $sql) === 1
            && preg_match('/\bADD\s+(COLUMN\b|INDEX\b|KEY\b|UNIQUE\b|CONSTRAINT\b|FOREIGN\s+KEY\b|PRIMARY\s+KEY\b)/i', $sql) === 1
        ) {
            return self::ADDITIVE;
        }

        return self::AMBIGUOUS;
    }

    public function requiresDestructiveOptIn(string $sql): bool
    {
        if ($this->classify($sql) === self::DESTRUCTIVE) {
            return true;
        }

        // Preserve the migration CLI's pre-planner safety contract: UPDATE is
        // a rewrite, but still requires the operator's explicit destructive
        // opt-in in addition to a backup.
        return preg_match('/^\s*UPDATE\b/i', trim($sql)) === 1;
    }

    /**
     * @param array<string,string> $pending
     * @param string[] $requestedLabels
     * @return array<string,mixed>
     */
    public function select(array $pending, string $scope = self::SCOPE_ALL, array $requestedLabels = []): array
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_ADDITIVE, self::SCOPE_REVIEWED], true)) {
            throw new InvalidArgumentException('MIGRATION_SCOPE_INVALID:' . $scope);
        }

        $labels = [];
        foreach ($requestedLabels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            if (isset($labels[$label])) {
                throw new InvalidArgumentException('MIGRATION_LABEL_DUPLICATE:' . $label);
            }
            $labels[$label] = true;
        }
        if ($scope === self::SCOPE_ALL && $labels !== []) {
            throw new InvalidArgumentException('MIGRATION_LABELS_REQUIRE_ADDITIVE_SCOPE');
        }
        if ($scope === self::SCOPE_REVIEWED && $labels === []) {
            throw new InvalidArgumentException('MIGRATION_LABELS_REQUIRED_FOR_REVIEWED_SCOPE');
        }

        $classifications = [];
        $counts = [
            self::ADDITIVE => 0,
            self::REWRITE => 0,
            self::DESTRUCTIVE => 0,
            self::AMBIGUOUS => 0,
        ];
        foreach ($pending as $label => $sql) {
            $classification = $this->classify((string) $sql);
            $classifications[(string) $label] = $classification;
            $counts[$classification]++;
        }

        if ($labels !== []) {
            foreach (array_keys($labels) as $label) {
                if (!array_key_exists($label, $pending)) {
                    throw new InvalidArgumentException('MIGRATION_LABEL_NOT_PENDING:' . $label);
                }
                if ($scope === self::SCOPE_ADDITIVE && ($classifications[$label] ?? self::AMBIGUOUS) !== self::ADDITIVE) {
                    throw new InvalidArgumentException(
                        'MIGRATION_LABEL_NOT_ADDITIVE:' . $label . ':' . ($classifications[$label] ?? self::AMBIGUOUS)
                    );
                }
                if ($scope === self::SCOPE_REVIEWED && ($classifications[$label] ?? self::AMBIGUOUS) === self::AMBIGUOUS) {
                    throw new InvalidArgumentException('MIGRATION_LABEL_AMBIGUOUS:' . $label);
                }
            }
        }

        $selected = [];
        $deferred = [];
        foreach ($pending as $label => $sql) {
            $label = (string) $label;
            $include = $scope === self::SCOPE_ALL
                || ($scope === self::SCOPE_REVIEWED && isset($labels[$label]))
                || ($scope === self::SCOPE_ADDITIVE
                    && ($classifications[$label] ?? self::AMBIGUOUS) === self::ADDITIVE
                    && ($labels === [] || isset($labels[$label])));
            if ($include) {
                $selected[$label] = (string) $sql;
            } else {
                $deferred[$label] = (string) $sql;
            }
        }

        return [
            'scope' => $scope,
            'requested_labels' => array_keys($labels),
            'selected' => $selected,
            'deferred' => $deferred,
            'classifications' => $classifications,
            'classification_counts' => $counts,
        ];
    }
}
