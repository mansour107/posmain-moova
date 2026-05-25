<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipeCostLeakAuditService.php';

class RecipeCostLeakAuditServiceTest extends TestCase
{
    public function testFindsSensitiveCostFieldsRecursively(): void
    {
        $paths = (new RecipeCostLeakAuditService())->sensitivePaths([
            'item_id' => 10,
            'cost_price' => '12.000000',
            'purchase_price' => '11.000000',
            'recipe' => [
                'availability_revision' => 4,
                'ingredient_cost_json' => ['bun' => 1.25],
            ],
            'variants' => [
                [
                    'item_id' => 11,
                    'unit_cost' => '3.500000',
                ],
            ],
        ]);

        sort($paths);

        $this->assertSame([
            'cost_price',
            'purchase_price',
            'recipe.ingredient_cost_json',
            'variants.0.unit_cost',
        ], $paths);
    }

    public function testMasksSensitiveFieldsForMoovaPayloads(): void
    {
        $sanitized = (new RecipeCostLeakAuditService())->sanitizePayload([
            'item_id' => 10,
            'item_name' => 'Burger',
            'cost_price' => '12.000000',
            'recipe_availability' => [
                'recipe_enabled' => true,
                'effective_is_available' => true,
                'internal_cost_per_sell_unit' => '18.000000',
            ],
            'variants' => [
                [
                    'item_id' => 11,
                    'price' => '15.0000',
                    'profit' => '5.0000',
                ],
            ],
        ], 'moova-facing api');

        $this->assertSame(10, $sanitized['item_id']);
        $this->assertArrayNotHasKey('cost_price', $sanitized);
        $this->assertArrayNotHasKey('internal_cost_per_sell_unit', $sanitized['recipe_availability']);
        $this->assertArrayNotHasKey('profit', $sanitized['variants'][0]);
        $this->assertSame('15.0000', $sanitized['variants'][0]['price']);
    }

    public function testMasksCamelCaseSensitiveCostFieldsForPublicPayloads(): void
    {
        $sanitized = (new RecipeCostLeakAuditService())->sanitizePayload([
            'item_id' => 10,
            'recipeCostSnapshot' => 99,
            'ingredientCostJson' => ['bun' => 1.25],
            'availability' => [
                'effectiveIsAvailable' => true,
                'internalCostPerSellUnit' => '18.000000',
            ],
            'variants' => [
                [
                    'itemId' => 11,
                    'unitCost' => '3.500000',
                    'totalCost' => '7.000000',
                    'priceDelta' => '2.000000',
                ],
            ],
        ], 'moova-facing api');

        $this->assertSame(10, $sanitized['item_id']);
        $this->assertArrayNotHasKey('recipeCostSnapshot', $sanitized);
        $this->assertArrayNotHasKey('ingredientCostJson', $sanitized);
        $this->assertArrayNotHasKey('internalCostPerSellUnit', $sanitized['availability']);
        $this->assertArrayNotHasKey('unitCost', $sanitized['variants'][0]);
        $this->assertArrayNotHasKey('totalCost', $sanitized['variants'][0]);
        $this->assertSame('2.000000', $sanitized['variants'][0]['priceDelta']);
    }

    public function testInternalPayloadsAreNotMaskedByDefault(): void
    {
        $payload = [
            'item_id' => 10,
            'cost_price' => '12.000000',
        ];

        $this->assertSame(
            $payload,
            (new RecipeCostLeakAuditService())->sanitizePayload($payload, 'internal cloud mirror')
        );
    }

    public function testExplicitCostPayloadFlagAllowsPublicPayloadCosts(): void
    {
        $payload = [
            'item_id' => 10,
            'cost_price' => '12.000000',
        ];
        $flags = new RecipeFeatureFlags([
            'recipe' => [
                'enabled' => true,
                'mode' => 'full',
                'cost_public_payloads' => true,
            ],
        ]);

        $this->assertSame(
            $payload,
            (new RecipeCostLeakAuditService())->sanitizePayload($payload, 'moova-facing api', $flags)
        );
    }
}
