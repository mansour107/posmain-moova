<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class RecipeSchemaMigrationTest extends TestCase
{
    public function testRecipeTablesArePlannedThroughSchemaManager(): void
    {
        $manager = new SyncSchemaManager();
        $planned = $manager->plannedStatements();

        foreach ($this->recipeTables() as $table) {
            $this->assertArrayHasKey($table, $planned, "{$table} schema is not planned");
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $planned[$table]);
        }

        $this->assertStringContainsString('recipe_order_line_usage', $planned['inventory_movements']);
        $this->assertStringContainsString('external_order_line_map', implode("\n", array_keys($planned)));
    }

    public function testRecipeSchemaUsesPosmainIdentityAndStoreDefaults(): void
    {
        $manager = new SyncSchemaManager();
        $sql = implode("\n", array_intersect_key($manager->plannedStatements(), array_flip($this->recipeTables())));

        $this->assertStringContainsString('pos_tenant INT NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('pos_branch INT NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('store_id BIGINT UNSIGNED NOT NULL DEFAULT 0', $sql);
        $this->assertStringNotContainsString('pos_tenant INT UNSIGNED NOT NULL DEFAULT 1', $sql);
        $this->assertStringNotContainsString('pos_branch INT UNSIGNED NOT NULL DEFAULT 1', $sql);
        $this->assertStringNotContainsString('tenant VARCHAR(64)', $sql);
        $this->assertStringNotContainsString('branch VARCHAR(64)', $sql);
    }

    public function testFinalPlanFixesAreReflectedInIndexes(): void
    {
        $manager = new SyncSchemaManager();
        $planned = $manager->plannedStatements();

        $this->assertStringContainsString(
            'UNIQUE KEY uq_recipe_availability_item (pos_tenant, pos_branch, store_id, sellable_item_id, order_type, channel)',
            $planned['recipe_availability_cache']
        );
        $this->assertStringContainsString(
            'UNIQUE KEY uq_external_line (pos_tenant, pos_branch, source_channel, external_order_id, external_line_id)',
            $planned['external_order_line_map']
        );
        $this->assertStringContainsString(
            'UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key)',
            $planned['inventory_movements']
        );
        $this->assertStringContainsString(
            'UNIQUE KEY uq_stock_reservation_idem (pos_tenant, pos_branch, store_id, idempotency_key)',
            $planned['stock_reservations']
        );
        $this->assertStringContainsString('qty_reserved DECIMAL(18,6) NOT NULL DEFAULT 0.000000', $planned['inventory_item_balances']);
        $this->assertStringNotContainsString('qty_on_hand', $planned['stock_reservations']);
    }

    public function testRecipeSchemaStatementsAreAdditiveOnly(): void
    {
        $manager = new SyncSchemaManager();
        $planned = array_intersect_key($manager->plannedStatements(), array_flip($this->recipeTables()));

        foreach ($planned as $table => $sql) {
            $this->assertStringStartsWith("\nCREATE TABLE IF NOT EXISTS {$table}", $sql, "{$table} must be additive");
            $this->assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET|ALTER\s+TABLE)\b/i', $sql);
        }
    }

    private function recipeTables(): array
    {
        return [
            'recipe_headers',
            'recipe_lines',
            'recipe_cost_snapshots',
            'recipe_order_line_usage',
            'inventory_movements',
            'inventory_item_balances',
            'stock_reservations',
            'production_batches',
            'production_batch_lines',
            'recipe_audit_log',
            'recipe_availability_cache',
            'external_order_line_map',
        ];
    }
}
