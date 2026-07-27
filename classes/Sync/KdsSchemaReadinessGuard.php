<?php

require_once __DIR__ . '/SchemaManager.php';

/**
 * Request-time KDS readiness check.
 *
 * KDS pages must only depend on the tables and columns they actually use.
 * Unrelated pending migrations are still reported by the global readiness
 * guard and remain an explicit deployment concern.
 */
final class KdsSchemaReadinessGuard
{
    public const ERROR_CODE = 'KDS_SCHEMA_MIGRATIONS_PENDING';

    public function inspect(mysqli $conn): array
    {
        $pending = (new SyncSchemaManager())->pendingKdsStatements($conn);

        return [
            'ready' => $pending === [],
            'pending_count' => count($pending),
            'pending_labels' => array_keys($pending),
        ];
    }

    public function assertReady(mysqli $conn): void
    {
        $status = $this->inspect($conn);
        if ($status['ready']) {
            return;
        }

        throw new RuntimeException(self::ERROR_CODE);
    }
}
