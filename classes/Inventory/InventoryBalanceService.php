<?php

require_once __DIR__ . '/InventoryFeatureFlags.php';
require_once __DIR__ . '/InventoryDecimal.php';

class InventoryBalanceService
{
    private InventoryFeatureFlags $flags;

    public function __construct(?InventoryFeatureFlags $flags = null)
    {
        $this->flags = $flags ?: new InventoryFeatureFlags();
    }

    public function currentBalanceRequest(array $scope, int $itemId): array
    {
        return [
            'success' => true,
            'noop' => true,
            'mode' => $this->flags->mode(),
            'intended_action' => 'read_balance',
            'item_id' => max(0, $itemId),
            'scope' => $scope,
            'writes' => [],
        ];
    }

    public function calculateAvailable($qtyOnHand, $qtyReserved = '0', $safetyStock = '0'): string
    {
        $available = InventoryDecimal::subtract($qtyOnHand, $qtyReserved);

        return InventoryDecimal::subtract($available, $safetyStock);
    }

    public function refreshBalance(array $scope, int $itemId): array
    {
        return [
            'success' => true,
            'noop' => !$this->flags->canWriteLedger(),
            'mode' => $this->flags->mode(),
            'intended_action' => 'refresh_balance',
            'item_id' => max(0, $itemId),
            'scope' => $scope,
            'writes' => [],
        ];
    }
}
