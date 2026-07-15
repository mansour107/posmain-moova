<?php

require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerMigrationService.php';

if (!function_exists('posmain_apply_pos_customer_migrations')) {
    function posmain_apply_pos_customer_migrations(mysqli $conn): array
    {
        (new SyncSchemaReadinessGuard())->assertReady($conn);

        $migration = new PosCustomerMigrationService();
        $delivery = $migration->migrateFromDeliveryClientsIfNeeded($conn);
        $backfill = $migration->backfillOrderFulfillmentCustomers($conn);

        return [
            'schema_tables' => [],
            'delivery_migration' => $delivery,
            'fulfillment_backfill' => $backfill,
        ];
    }
}

if (!function_exists('posmain_ensure_pos_customer_schema')) {
    function posmain_ensure_pos_customer_schema(mysqli $conn): void
    {
        static $ensured = [];
        $key = spl_object_hash($conn);
        if (isset($ensured[$key])) {
            return;
        }

        (new SyncSchemaReadinessGuard())->assertReady($conn);
        $ensured[$key] = true;
    }
}
