<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeScopeResolver.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeSettingsService.php';

class RecipeFeatureFlagsTest extends TestCase
{
    public function testAppConfigProductionProfilePromotesLegacyOffModeToFull(): void
    {
        $this->withRecipeEnv([
            'POSMAIN_RECIPE_MODE' => 'off',
            'POSMAIN_RECIPE_SHADOW_LEDGER' => '0',
            'POSMAIN_RECIPE_RESERVATIONS' => '0',
            'POSMAIN_RECIPE_CONSUMPTION' => '0',
            'POSMAIN_RECIPE_ACCOUNTING' => '0',
            'POSMAIN_RECIPE_AVAILABILITY' => '0',
            'POSMAIN_RECIPE_MOOVA_SYNC' => '0',
            'POSMAIN_RECIPE_STRICT_STOCK' => '0',
            'POSMAIN_RECIPE_COST_PUBLIC_PAYLOADS' => '0',
            'POSMAIN_RECIPE_DEFAULT_COGS_ACCOUNT_ID' => '0',
            'POSMAIN_RECIPE_RAW_INVENTORY_ACCOUNT_ID' => '0',
            'POSMAIN_RECIPE_DEFAULT_RESERVATION_MINUTES' => '90',
            'POSMAIN_RECIPE_DEFAULT_SAFETY_STOCK_QTY' => '0',
            'POSMAIN_RECIPE_REFUND_STOCK_POLICY' => 'waste',
            'POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY' => 'adjust_unit_cost',
            'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        ], function (): void {
            $config = posmain_app_config();

            $this->assertArrayHasKey('recipe', $config);
            $this->assertTrue($config['recipe']['enabled']);
            $this->assertSame('full', $config['recipe']['mode']);
            $this->assertFalse($config['recipe']['shadow_ledger']);
            $this->assertTrue($config['recipe']['reservations']);
            $this->assertTrue($config['recipe']['consumption']);
            $this->assertTrue($config['recipe']['accounting']);
            $this->assertTrue($config['recipe']['availability']);
            $this->assertTrue($config['recipe']['moova_sync']);
            $this->assertFalse($config['recipe']['strict_stock']);
            $this->assertFalse($config['recipe']['cost_public_payloads']);
            $this->assertSame(0, $config['recipe']['accounts']['cogs_account_id']);
            $this->assertSame(0, $config['recipe']['accounts']['raw_inventory_account_id']);
            $this->assertSame(90, $config['recipe']['default_reservation_minutes']);
            $this->assertSame('0', $config['recipe']['default_safety_stock_qty']);
            $this->assertSame('waste', $config['recipe']['refund_stock_policy']);
            $this->assertSame('adjust_unit_cost', $config['recipe']['production_variance_policy']);
            $this->assertSame([], $config['recipe']['pilot']['item_ids']);
            $this->assertSame([], $config['recipe']['pilot']['category_ids']);
            $this->assertFalse($config['features']['recipes']);
        });
    }

    public function testAppConfigExposesProductionVariancePolicyEnv(): void
    {
        $this->withRecipeEnv([
            'POSMAIN_RECIPE_PRODUCTION_VARIANCE_POLICY' => 'post_variance',
        ], function (): void {
            $config = posmain_app_config();
            $settings = new RecipeSettingsService($config);

            $this->assertSame('post_variance', $config['recipe']['production_variance_policy']);
            $this->assertSame('post_variance', $settings->productionVariancePolicy());
        });
    }

    public function testInvalidModeFallsBackToOff(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'surprise_mode',
            ],
        ]);

        $this->assertSame('off', $flags->mode());
        $this->assertFalse($flags->isEnabled());
    }

    public function testPilotScopeUsesBranchZeroDefaultsAndExplicitBranchFilters(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'cost_public_payloads' => false,
                'pilot' => [
                    'pos_branch' => '7',
                    'item_ids' => [10, 20],
                    'category_ids' => [],
                ],
            ],
        ]);

        $matchingScope = new RecipeScope(0, 7, null, 0, 'pos', 'takeaway', 'pos');
        $wrongBranch = new RecipeScope(0, 8, null, 0, 'pos', 'takeaway', 'pos');

        $this->assertTrue($flags->isConsumptionEnabledForItem($matchingScope, 10));
        $this->assertFalse($flags->isConsumptionEnabledForItem($wrongBranch, 10));
        $this->assertFalse($flags->isConsumptionEnabledForItem(null, 10));
        $this->assertFalse($flags->isConsumptionEnabledForItem($matchingScope, 99));
        $this->assertFalse($flags->canExposeCostsToPayload('moova_menu'));
    }

    public function testBranchScopedPilotsFailClosedWhenScopeIsMissingAcrossRuntimeGates(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'reservations' => true,
                'consumption' => true,
                'accounting' => true,
                'availability' => true,
                'pilot' => [
                    'pos_branch' => '7',
                    'item_ids' => [10],
                    'category_ids' => [],
                ],
            ],
        ]);

        $this->assertFalse($flags->isReservationEnabledForItem(null, 10));
        $this->assertFalse($flags->isConsumptionEnabledForItem(null, 10));
        $this->assertFalse($flags->isAccountingEnabledForItem(null, 10));
        $this->assertFalse($flags->isAvailabilityEnabledForItem(null, 10));
    }

    public function testPilotModesFailClosedWithoutExplicitPilotScope(): void
    {
        $scope = new RecipeScope(0, 0, null, 0, 'pos', 'takeaway', 'pos');

        foreach (['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'] as $mode) {
            $flags = new RecipeFeatureFlags([
                'recipe' => [
                    'enabled' => true,
                    'mode' => $mode,
                    'reservations' => true,
                    'consumption' => true,
                    'accounting' => true,
                    'availability' => true,
                    'pilot' => [
                        'pos_branch' => '',
                        'item_ids' => [],
                        'category_ids' => [],
                    ],
                ],
            ]);

            $this->assertFalse($flags->isReservationEnabledForItem($scope, 10), $mode);
            $this->assertFalse($flags->isConsumptionEnabledForItem($scope, 10), $mode);
            $this->assertFalse($flags->isAccountingEnabledForItem($scope, 10), $mode);
            $this->assertFalse($flags->isAvailabilityEnabledForItem($scope, 10), $mode);
        }
    }

    public function testMoovaSyncRequiresRecipeAvailabilityToBeEffective(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => false,
                'moova_sync' => true,
            ],
        ]);
        $enabledFlags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'availability_pilot',
                'availability' => true,
                'moova_sync' => true,
            ],
        ]);

        $this->assertFalse($flags->isMoovaSyncEnabled());
        $this->assertTrue($enabledFlags->isMoovaSyncEnabled());
    }

    public function testConsumptionImpliesReservationsForUnpaidOrderHold(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'reservations' => false,
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [10],
                    'category_ids' => [],
                ],
            ],
        ]);

        $this->assertTrue($flags->isReservationEnabled());
        $this->assertTrue($flags->isConsumptionEnabledForItem(new RecipeScope(0, 0, null, 0, 'pos', 'takeaway', 'pos'), 10));
    }

    public function testReserveOnlyStillRequiresExplicitReservationFlag(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'reserve_only',
                'reservations' => false,
                'consumption' => false,
            ],
        ]);

        $this->assertFalse($flags->isReservationEnabled());
    }

    public function testPilotCategoryScopeFailsClosedUnlessCategoryMatches(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [],
                    'category_ids' => [7],
                ],
            ],
        ]);
        $scope = new RecipeScope(0, 0, null, 0, 'pos', 'takeaway', 'pos');

        $this->assertTrue($flags->isConsumptionEnabledForItem($scope, 10, 7));
        $this->assertFalse($flags->isConsumptionEnabledForItem($scope, 10, 8));
        $this->assertFalse($flags->isConsumptionEnabledForItem($scope, 10));
    }

    public function testReservationPilotScopeUsesSameBranchAndCategoryFilters(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'reserve_only',
                'reservations' => true,
                'pilot' => [
                    'pos_branch' => '7',
                    'item_ids' => [],
                    'category_ids' => [3],
                ],
            ],
        ]);
        $matchingScope = new RecipeScope(0, 7, null, 0, 'pos', 'takeaway', 'pos');
        $wrongBranch = new RecipeScope(0, 8, null, 0, 'pos', 'takeaway', 'pos');

        $this->assertTrue($flags->isReservationEnabledForItem($matchingScope, 10, 3));
        $this->assertFalse($flags->isReservationEnabledForItem($wrongBranch, 10, 3));
        $this->assertFalse($flags->isReservationEnabledForItem($matchingScope, 10, 4));
        $this->assertFalse($flags->isReservationEnabledForItem($matchingScope, 10));
    }

    public function testExplicitPilotItemStillMatchesWhenCategoryIsUnknown(): void
    {
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'pilot' => [
                    'pos_branch' => '',
                    'item_ids' => [10],
                    'category_ids' => [7],
                ],
            ],
        ]);
        $scope = new RecipeScope(0, 0, null, 0, 'pos', 'takeaway', 'pos');

        $this->assertTrue($flags->isConsumptionEnabledForItem($scope, 10));
        $this->assertTrue($flags->isConsumptionEnabledForItem($scope, 99, 7));
        $this->assertFalse($flags->isConsumptionEnabledForItem($scope, 99, 8));
    }

    public function testScopeResolverDefaultsToZeroIdentityAndStoreZero(): void
    {
        $resolver = new RecipeScopeResolver([
            'branch' => [
                'pos_tenant' => null,
                'pos_branch' => null,
                'uuid' => '',
            ],
        ]);

        $scope = $resolver->resolve();

        $this->assertSame(0, $scope->posTenant);
        $this->assertSame(0, $scope->posBranch);
        $this->assertSame(0, $scope->storeId);
        $this->assertNull($scope->branchUuid);
    }

    private function withRecipeEnv(array $values, callable $callback): void
    {
        $old = [];
        foreach ($values as $name => $value) {
            $old[$name] = getenv($name);
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($old as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name]);
                } else {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }
    }
}
