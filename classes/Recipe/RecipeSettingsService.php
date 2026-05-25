<?php

class RecipeSettingsService
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function accountingContext(array $overrides = []): array
    {
        $accounts = $this->accounts();
        foreach ($this->accountKeys() as $key) {
            if ((int) ($overrides[$key] ?? 0) > 0) {
                $accounts[$key] = (int) $overrides[$key];
            }
        }

        return array_merge($overrides, $accounts);
    }

    public function accountId(string $key, array $overrides = []): int
    {
        if ((int) ($overrides[$key] ?? 0) > 0) {
            return (int) $overrides[$key];
        }

        return (int) ($this->accounts()[$key] ?? 0);
    }

    public function inventoryAccountId(array $context = []): int
    {
        $inventoryType = strtolower(trim((string) ($context['recipe_inventory_account_type'] ?? '')));
        if ($inventoryType === 'prepared') {
            foreach (['inventory_account_id', 'prepared_inventory_account_id', 'raw_inventory_account_id', 'packaging_inventory_account_id'] as $key) {
                $id = $this->accountId($key, $context);
                if ($id > 0) {
                    return $id;
                }
            }

            return 0;
        }

        foreach (['inventory_account_id', 'raw_inventory_account_id', 'prepared_inventory_account_id', 'packaging_inventory_account_id'] as $key) {
            $id = $this->accountId($key, $context);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    public function refundStockPolicy(array $context = []): string
    {
        $policy = strtolower(trim((string) ($context['policy'] ?? $context['refund_stock_policy'] ?? $this->recipeConfig()['refund_stock_policy'] ?? 'waste')));

        return in_array($policy, ['return_to_stock', 'waste', 'manager_choice'], true) ? $policy : 'waste';
    }

    public function defaultReservationMinutes(): int
    {
        $minutes = (int) ($this->recipeConfig()['default_reservation_minutes'] ?? 90);

        return $minutes > 0 ? $minutes : 90;
    }

    public function defaultSafetyStockQty(): string
    {
        $qty = trim((string) ($this->recipeConfig()['default_safety_stock_qty'] ?? '0'));

        return preg_match('/^\d+(\.\d+)?$/', $qty) ? $qty : '0';
    }

    public function allowNegativeStockWithApproval(): bool
    {
        return (bool) ($this->recipeConfig()['allow_negative_stock_with_approval'] ?? false);
    }

    public function productionVariancePolicy(): string
    {
        $policy = strtolower(trim((string) ($this->recipeConfig()['production_variance_policy'] ?? 'adjust_unit_cost')));

        return in_array($policy, ['adjust_unit_cost', 'post_variance'], true) ? $policy : 'adjust_unit_cost';
    }

    private function accounts(): array
    {
        $configured = is_array($this->recipeConfig()['accounts'] ?? null) ? $this->recipeConfig()['accounts'] : [];
        $accounts = [];
        foreach ($this->accountKeys() as $key) {
            $accounts[$key] = max(0, (int) ($configured[$key] ?? 0));
        }

        return $accounts;
    }

    private function accountKeys(): array
    {
        return [
            'cogs_account_id',
            'raw_inventory_account_id',
            'prepared_inventory_account_id',
            'packaging_inventory_account_id',
            'waste_expense_account_id',
            'production_variance_account_id',
            'inventory_account_id',
        ];
    }

    private function recipeConfig(): array
    {
        return is_array($this->config['recipe'] ?? null) ? $this->config['recipe'] : [];
    }
}
