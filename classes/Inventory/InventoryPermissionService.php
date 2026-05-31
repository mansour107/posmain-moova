<?php

class InventoryPermissionService
{
    public function can(string $permission, array $actor = []): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        return in_array($permission, $this->permissions($actor), true)
            || in_array('inventory.*', $this->permissions($actor), true)
            || in_array('admin', $this->permissions($actor), true);
    }

    public function assertCan(string $permission, array $actor = []): void
    {
        if ($this->can($permission, $actor)) {
            return;
        }

        throw new RuntimeException('Inventory permission is required: ' . $permission);
    }

    public function canViewCost(array $actor = []): bool
    {
        return $this->can('inventory.cost.view', $actor);
    }

    public function canAdjustStock(array $actor = []): bool
    {
        return $this->can('inventory.adjust', $actor);
    }

    public function canApprove(array $actor = []): bool
    {
        return $this->can('inventory.approve', $actor);
    }

    private function permissions(array $actor): array
    {
        $permissions = $actor['permissions'] ?? [];
        if (!is_array($permissions)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $permissions)));
    }
}
