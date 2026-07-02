<?php

require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsStationService.php';

if (!function_exists('posmain_ensure_kds_schema')) {
    function posmain_ensure_kds_schema(mysqli $conn): void
    {
        static $ensured = [];
        $key = spl_object_hash($conn);
        if (isset($ensured[$key])) {
            return;
        }
        $ensured[$key] = true;

        try {
            $schema = new SyncSchemaManager();
            $schema->applyKdsSchema($conn);
            (new KdsStationService($schema))->ensureDefaultStation($conn);
            $conn->query("UPDATE kds_tickets SET status = 'in_progress' WHERE status = 'ready'");
        } catch (Throwable $exception) {
            error_log('KDS schema ensure skipped: ' . $exception->getMessage());
        }
    }
}
