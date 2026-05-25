<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/RecipeDefinitionService.php';

class RecipeVersioningService
{
    private $definitionService;

    public function __construct(?RecipeDefinitionService $definitionService = null)
    {
        $this->definitionService = $definitionService ?: new RecipeDefinitionService();
    }

    public function cloneActiveAsDraft(mysqli $conn, int $activeRecipeId, RecipeActorContext $actor): array
    {
        return $this->definitionService->cloneAsNewVersion($conn, $activeRecipeId, $actor);
    }
}
