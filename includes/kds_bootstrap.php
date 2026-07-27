<?php

require_once __DIR__ . '/../classes/Sync/KdsSchemaReadinessGuard.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsStationService.php';

if (!function_exists('posmain_ensure_kds_schema')) {
    function posmain_ensure_kds_schema(mysqli $conn): void
    {
        static $ensured = [];
        $key = spl_object_hash($conn);
        if (isset($ensured[$key])) {
            return;
        }

        (new KdsSchemaReadinessGuard())->assertReady($conn);
        (new KdsStationService())->ensureDefaultStation($conn);
        $ensured[$key] = true;
    }
}
