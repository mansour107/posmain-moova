<?php

require_once __DIR__ . '/DTO/RecipeScope.php';

class RecipeFeatureFlags
{
    private const MODES = [
        'off',
        'schema_only',
        'read_only',
        'shadow',
        'reserve_only',
        'consume_pilot',
        'accounting_pilot',
        'availability_pilot',
        'full',
    ];

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
    }

    public function isEnabled(): bool
    {
        return $this->boolValue($this->recipeConfig()['enabled'] ?? false) && $this->mode() !== 'off';
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) ($this->recipeConfig()['mode'] ?? 'off')));
        $mode = str_replace(['-', ' '], '_', $mode);

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    public function isShadowLedgerEnabled(): bool
    {
        return $this->isEnabled()
            && $this->boolValue($this->recipeConfig()['shadow_ledger'] ?? false)
            && in_array($this->mode(), ['shadow', 'reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true);
    }

    public function isReservationEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $mode = $this->mode();
        $reservationModes = ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'];
        if (!in_array($mode, $reservationModes, true)) {
            return false;
        }

        if ($this->boolValue($this->recipeConfig()['reservations'] ?? false)) {
            return true;
        }

        // Unpaid orders should reserve ingredients whenever payment will consume them.
        $consumptionModes = ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'];

        return in_array($mode, $consumptionModes, true)
            && $this->boolValue($this->recipeConfig()['consumption'] ?? false);
    }

    public function isReservationEnabledForItem(?RecipeScope $scope, int $itemId, ?int $itemCategoryId = null): bool
    {
        return $this->isReservationEnabled()
            && $this->isInPilotScope($scope, $itemId, $itemCategoryId);
    }

    public function isConsumptionEnabledForItem(?RecipeScope $scope, int $itemId, ?int $itemCategoryId = null): bool
    {
        return $this->isEnabled()
            && $this->boolValue($this->recipeConfig()['consumption'] ?? false)
            && in_array($this->mode(), ['consume_pilot', 'accounting_pilot', 'availability_pilot', 'full'], true)
            && $this->isInPilotScope($scope, $itemId, $itemCategoryId);
    }

    public function isAccountingEnabledForItem(?RecipeScope $scope, int $itemId, ?int $itemCategoryId = null): bool
    {
        return $this->isEnabled()
            && $this->boolValue($this->recipeConfig()['accounting'] ?? false)
            && in_array($this->mode(), ['accounting_pilot', 'availability_pilot', 'full'], true)
            && $this->isInPilotScope($scope, $itemId, $itemCategoryId);
    }

    public function isAvailabilityEnabledForItem(?RecipeScope $scope, int $itemId, ?int $itemCategoryId = null): bool
    {
        return $this->isEnabled()
            && $this->boolValue($this->recipeConfig()['availability'] ?? false)
            && in_array($this->mode(), ['availability_pilot', 'full'], true)
            && $this->isInPilotScope($scope, $itemId, $itemCategoryId);
    }

    public function isMoovaSyncEnabled(): bool
    {
        return $this->isEnabled()
            && $this->boolValue($this->recipeConfig()['moova_sync'] ?? false)
            && $this->boolValue($this->recipeConfig()['availability'] ?? false)
            && in_array($this->mode(), ['availability_pilot', 'full'], true);
    }

    public function isStrictStockEnabled(): bool
    {
        return $this->isEnabled() && $this->boolValue($this->recipeConfig()['strict_stock'] ?? false);
    }

    public function canExposeCostsToPayload(string $payloadClass): bool
    {
        return $this->isEnabled()
            && $payloadClass !== ''
            && $this->boolValue($this->recipeConfig()['cost_public_payloads'] ?? false);
    }

    public function isInPilotScope(?RecipeScope $scope, int $itemId, ?int $itemCategoryId = null): bool
    {
        if ($this->mode() === 'full') {
            return true;
        }

        $pilot = $this->recipeConfig()['pilot'] ?? [];
        if ($this->isPilotMode() && !$this->hasExplicitPilotScope($pilot)) {
            return false;
        }

        $pilotBranch = trim((string) ($pilot['pos_branch'] ?? ''));
        if ($pilotBranch !== '') {
            if ($scope === null) {
                return false;
            }
            if ((string) $scope->posBranch !== $pilotBranch) {
                return false;
            }
        }

        $itemIds = $this->intList($pilot['item_ids'] ?? []);
        $categoryIds = $this->intList($pilot['category_ids'] ?? []);
        if ($itemIds || $categoryIds) {
            $itemMatches = $itemIds && in_array($itemId, $itemIds, true);
            $categoryMatches = $categoryIds
                && $itemCategoryId !== null
                && $itemCategoryId > 0
                && in_array($itemCategoryId, $categoryIds, true);

            return $itemMatches || $categoryMatches;
        }

        return true;
    }

    private function isPilotMode(): bool
    {
        return in_array($this->mode(), ['reserve_only', 'consume_pilot', 'accounting_pilot', 'availability_pilot'], true);
    }

    private function hasExplicitPilotScope(array $pilot): bool
    {
        if (trim((string) ($pilot['pos_branch'] ?? '')) !== '') {
            return true;
        }

        return $this->intList($pilot['item_ids'] ?? []) !== []
            || $this->intList($pilot['category_ids'] ?? []) !== [];
    }

    public function config(): array
    {
        return $this->recipeConfig();
    }

    public function appConfig(): array
    {
        return $this->config;
    }

    private function recipeConfig(): array
    {
        return is_array($this->config['recipe'] ?? null) ? $this->config['recipe'] : [];
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function intList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ints = [];
        foreach ($value as $item) {
            $int = (int) $item;
            if ($int > 0) {
                $ints[] = $int;
            }
        }

        return array_values(array_unique($ints));
    }
}
