<?php

require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerMigrationService.php';

if (!function_exists('posmain_apply_pos_customer_migrations')) {
    function posmain_apply_pos_customer_migrations(mysqli $conn): array
    {
        $schemaTables = (new SyncSchemaManager())->applyPosCustomerSchema($conn);

        $migration = new PosCustomerMigrationService();
        $delivery = $migration->migrateFromDeliveryClientsIfNeeded($conn);
        $backfill = $migration->backfillOrderFulfillmentCustomers($conn);

        return [
            'schema_tables' => $schemaTables,
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

        if ((new SyncSchemaManager())->pendingPosCustomerStatements($conn) !== []) {
            throw new RuntimeException('POS_CUSTOMER_SCHEMA_MIGRATIONS_PENDING');
        }
        $ensured[$key] = true;
    }
}
