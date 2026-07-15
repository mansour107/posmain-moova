<?php

require_once __DIR__ . '/SchemaManager.php';

final class SyncSchemaReadinessGuard
{
    public const ERROR_CODE = 'SCHEMA_MIGRATIONS_PENDING';

    public function inspect(mysqli $conn): array
    {
        $pending = (new SyncSchemaManager())->pendingStatements($conn);

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
