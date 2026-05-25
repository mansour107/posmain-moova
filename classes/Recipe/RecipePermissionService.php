<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';

class RecipePermissionService
{
    public function assertCanEdit(RecipeActorContext $actor): void
    {
        if ($this->canEdit($actor)) {
            return;
        }

        throw new RuntimeException('Recipe edit permission is required.');
    }

    public function assertCanApprove(RecipeActorContext $actor): void
    {
        if ($this->canApprove($actor)) {
            return;
        }

        throw new RuntimeException('Recipe approval permission is required.');
    }

    public function canEdit(RecipeActorContext $actor): bool
    {
        return $actor->hasPermission('recipe.manage')
            || $actor->hasPermission('inventory.manage')
            || $actor->hasPermission('menu.manage')
            || $actor->hasPermission('admin');
    }

    public function canApprove(RecipeActorContext $actor): bool
    {
        return $actor->hasPermission('recipe.approve')
            || $actor->hasPermission('inventory.approve')
            || $actor->hasPermission('admin');
    }
}
