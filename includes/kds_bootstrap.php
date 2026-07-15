<?php

require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';
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

        (new SyncSchemaReadinessGuard())->assertReady($conn);
        (new KdsStationService())->ensureDefaultStation($conn);
    }
}
