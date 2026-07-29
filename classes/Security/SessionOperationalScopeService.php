<?php

require_once __DIR__ . '/../../config/app_config.php';

final class SessionOperationalScopeService
{
    /**
     * Establish the branch scope used by operational writes.
     *
     * Explicit trusted login context (for example a router-selected shop) wins,
     * followed by the configured branch identity. User columns are retained only
     * as a compatibility fallback for older single-database installations.
     *
     * @param array<string,mixed> $context
     * @return array{pos_tenant:int,pos_branch:int}
     */
    public function establish(mysqli $conn, int $userId, array $context = []): array
    {
        $userScope = $this->loadUserScope($conn, $userId);
        $scope = $this->resolve($userScope, $context);

        if ($scope['pos_tenant'] > 0) {
            $_SESSION['pos_tenant'] = $scope['pos_tenant'];
        } else {
            unset($_SESSION['pos_tenant']);
        }
        if ($scope['pos_branch'] > 0) {
            $_SESSION['pos_branch'] = $scope['pos_branch'];
        } else {
            unset($_SESSION['pos_branch']);
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $userScope
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $config
     * @return array{pos_tenant:int,pos_branch:int}
     */
    public function resolve(array $userScope, array $context = [], ?array $config = null): array
    {
        $config = $config ?? posmain_app_config();
        $branchConfig = is_array($config['branch'] ?? null) ? $config['branch'] : [];

        return [
            'pos_tenant' => $this->firstPositive([
                $context['pos_tenant'] ?? $context['tenant'] ?? null,
                $branchConfig['pos_tenant'] ?? null,
                $userScope['tenant'] ?? $userScope['pos_tenant'] ?? null,
            ]),
            'pos_branch' => $this->firstPositive([
                $context['pos_branch'] ?? $context['branch'] ?? null,
                $branchConfig['pos_branch'] ?? null,
                $userScope['branch'] ?? $userScope['pos_branch'] ?? null,
            ]),
        ];
    }

    public function clear(): void
    {
        unset($_SESSION['pos_tenant'], $_SESSION['pos_branch']);
    }

    /**
     * @return array{tenant:int,branch:int}
     */
    private function loadUserScope(mysqli $conn, int $userId): array
    {
        if ($userId < 1
            || !$this->columnExists($conn, 'users', 'tenant')
            || !$this->columnExists($conn, 'users', 'branch')
        ) {
            return ['tenant' => 0, 'branch' => 0];
        }

        $stmt = $conn->prepare(
            'SELECT COALESCE(tenant, 0) AS tenant, COALESCE(branch, 0) AS branch'
            . ' FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'tenant' => max(0, (int) ($row['tenant'] ?? 0)),
            'branch' => max(0, (int) ($row['branch'] ?? 0)),
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private function firstPositive(array $values): int
    {
        foreach ($values as $value) {
            $candidate = (int) $value;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();

        return $exists;
    }
}
