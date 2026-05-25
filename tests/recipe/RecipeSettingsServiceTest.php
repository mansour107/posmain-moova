<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Recipe/RecipeSettingsService.php';

class RecipeSettingsServiceTest extends TestCase
{
    public function testSettingsResolveAccountingAccountsAndOperationalDefaults(): void
    {
        $settings = new RecipeSettingsService([
            'recipe' => [
                'default_reservation_minutes' => 45,
                'default_safety_stock_qty' => '2.500000',
                'refund_stock_policy' => 'manager_choice',
                'allow_negative_stock_with_approval' => true,
                'production_variance_policy' => 'post_variance',
                'accounts' => [
                    'cogs_account_id' => 510,
                    'raw_inventory_account_id' => 120,
                    'prepared_inventory_account_id' => 130,
                    'packaging_inventory_account_id' => 140,
                    'waste_expense_account_id' => 540,
                    'production_variance_account_id' => 530,
                ],
            ],
        ]);

        $this->assertSame(510, $settings->accountId('cogs_account_id'));
        $this->assertSame(120, $settings->inventoryAccountId());
        $this->assertSame(130, $settings->inventoryAccountId(['recipe_inventory_account_type' => 'prepared']));
        $this->assertSame(999, $settings->accountId('cogs_account_id', ['cogs_account_id' => 999]));
        $this->assertSame(45, $settings->defaultReservationMinutes());
        $this->assertSame('2.500000', $settings->defaultSafetyStockQty());
        $this->assertSame('manager_choice', $settings->refundStockPolicy());
        $this->assertTrue($settings->allowNegativeStockWithApproval());
        $this->assertSame('post_variance', $settings->productionVariancePolicy());
    }

    public function testInvalidPoliciesAndValuesFallBackSafely(): void
    {
        $settings = new RecipeSettingsService([
            'recipe' => [
                'default_reservation_minutes' => -1,
                'default_safety_stock_qty' => 'not-a-number',
                'refund_stock_policy' => 'surprise',
                'accounts' => [
                    'cogs_account_id' => -5,
                ],
            ],
        ]);

        $this->assertSame(0, $settings->accountId('cogs_account_id'));
        $this->assertSame(90, $settings->defaultReservationMinutes());
        $this->assertSame('0', $settings->defaultSafetyStockQty());
        $this->assertSame('waste', $settings->refundStockPolicy());
        $this->assertSame('adjust_unit_cost', $settings->productionVariancePolicy());
    }
}
