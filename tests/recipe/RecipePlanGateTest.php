<?php

use PHPUnit\Framework\TestCase;

class RecipePlanGateTest extends TestCase
{
    public function testDiscoveryDocumentsCaptureCurrentImplementationGates(): void
    {
        $discovery = file_get_contents(__DIR__ . '/../../docs/recipe/implementation_discovery.md');
        $checkpoint = file_get_contents(__DIR__ . '/../../docs/recipe/worktree_checkpoint.md');
        $fixes = file_get_contents(__DIR__ . '/../../docs/recipe/final_plan_fixes.md');

        $this->assertIsString($discovery);
        $this->assertIsString($checkpoint);
        $this->assertIsString($fixes);
        $this->assertStringContainsString('pos_tenant INT NOT NULL DEFAULT 0', $discovery);
        $this->assertStringContainsString('pos_branch INT NOT NULL DEFAULT 0', $discovery);
        $this->assertStringContainsString('store_id BIGINT UNSIGNED NOT NULL DEFAULT 0', $discovery);
        $this->assertStringContainsString('recipe_availability_cache', $fixes);
        $this->assertStringContainsString('order_type', $fixes);
        $this->assertStringContainsString('channel', $fixes);
        $this->assertStringContainsString('Reservation movement rows must not reduce `qty_on_hand`', $fixes);
        $this->assertStringContainsString('modifier substitution recipe setup', $fixes);
        $this->assertStringContainsString('non-placeholder operator proof for production batches', $fixes);
        $this->assertStringContainsString('Moova/Cofe replay when recipe menu sync is enabled', $fixes);
        $this->assertStringContainsString('Evidence completed at UTC', $fixes);
        $this->assertStringContainsString('separate hosted runtime schema evidence', $fixes);
        $this->assertStringContainsString('underlying menu sync transport', $fixes);
        $this->assertStringContainsString('require recipe availability itself', $fixes);
        $this->assertStringContainsString('POSMAIN_ROLE=fake_cloud', $fixes);
        $this->assertStringContainsString('not effectively enabled unless `POSMAIN_RECIPE_AVAILABILITY`', $fixes);
        $this->assertStringContainsString('POSMAIN_RECIPE_ALLOW_NEGATIVE_STOCK_WITH_APPROVAL', $fixes);
        $this->assertStringContainsString('POSMAIN_RECIPE_STRICT_STOCK=1', $fixes);
        $this->assertStringContainsString('must require `POSMAIN_RECIPE_AVAILABILITY=1`', $fixes);
        $this->assertStringContainsString('branch `sync_outbox` must prove the branch outbox can actually deliver', $fixes);
        $this->assertStringContainsString('camelCase fields like `costPrice`, `unitCost`, `totalCost`, `ingredientCostJson`, or `internalCostPerSellUnit`', $fixes);
        $this->assertStringContainsString('Production batch commits are active inventory writes', $fixes);
        $this->assertStringContainsString('Production batch accounting must be posted by `ProductionBatchService`', $fixes);
        $this->assertStringContainsString('Production batch commits must refresh computed availability inside `ProductionBatchService`', $fixes);
        $this->assertStringContainsString('Batch-prepared item availability must be based on prepared output stock on hand', $fixes);
        $this->assertStringContainsString('Batch-prepared sale consumption must consume the prepared output item stock', $fixes);
        $this->assertStringContainsString('credit the prepared inventory account when recipe accounting is active', $fixes);
        $this->assertStringContainsString('Batch-prepared return-to-stock refunds must restore prepared output stock', $fixes);
        $this->assertStringContainsString('derive its inventory account type from the stored recipe usage explosion', $fixes);
        $this->assertStringContainsString('Production yield variance policy must be explicit', $fixes);
        $this->assertStringContainsString('production_variance_policy=post_variance', $fixes);
        $this->assertStringContainsString('POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY=post_variance', $fixes);
        $this->assertStringContainsString('must require active recipe accounting', $fixes);
        $this->assertStringContainsString('production_variance_policy_requires_accounting', $fixes);
        $this->assertStringContainsString('Active recipe modes must require their matching runtime flags', $fixes);
        $this->assertStringContainsString('active_mode_flag_mismatches', $fixes);
        $this->assertStringContainsString('Runtime preflight must also block active recipe mode/flag mismatches', $fixes);
        $this->assertStringContainsString('public_cost_payloads_enabled', $fixes);
        $this->assertStringContainsString('stock policy mismatches', $fixes);
        $this->assertStringContainsString('Pilot modes must fail closed without an explicit pilot scope', $fixes);
        $this->assertStringContainsString('`reserve_only` is an active write mode and must require fresh pilot evidence', $fixes);
        $this->assertStringContainsString('Pilot evidence must follow effective mode gates', $fixes);
        $this->assertStringContainsString('must require effective recipe availability', $fixes);
        $this->assertStringContainsString('strict_stock_requires_effective_recipe_availability', $fixes);
        $this->assertStringContainsString('tests/sync/recipe_reservation_lifecycle_runtime_test.php', $discovery);
        $this->assertStringContainsString('local mode-off browser smoke for recipe operator/report surfaces', $discovery);
        $this->assertStringContainsString('tools/recipe_hosted_schema_preflight.php', file_get_contents(__DIR__ . '/../../docs/recipe/rollout_readiness.md'));
        $this->assertStringContainsString('tools/recipe_operator_surface_smoke.php', file_get_contents(__DIR__ . '/../../docs/recipe/rollout_readiness.md'));
        $this->assertStringContainsString('tools/recipe_report_export_smoke.php', file_get_contents(__DIR__ . '/../../docs/recipe/rollout_readiness.md'));
        $this->assertStringContainsString('tools/recipe_paid_reversal_surface_smoke.php', file_get_contents(__DIR__ . '/../../docs/recipe/rollout_readiness.md'));
        $this->assertStringContainsString('active recipe behavior remains blocked', $checkpoint);
    }
}
