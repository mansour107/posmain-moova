<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/ProductionBatchService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeScopeResolver.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class ProductionBatchMutationService
{
    private $production;
    private $recipes;
    private RecipeScopeResolver $scopeResolver;

    public function __construct(
        ?ProductionBatchService $production = null,
        ?RecipeRepository $recipes = null,
        ?RecipeScopeResolver $scopeResolver = null
    ) {
        $this->production = $production ?: new ProductionBatchService();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->scopeResolver = $scopeResolver ?: new RecipeScopeResolver();
    }

    public function handle(mysqli $conn, string $action, array $input, RecipeActorContext $actor): array
    {
        $action = strtolower(trim($action));
        switch ($action) {
            case 'create_draft':
                $recipeId = $this->positiveInt($input['recipe_id'] ?? null, 'Recipe id is required.');
                $recipe = $this->recipes->findHeaderById($conn, $recipeId);
                if (!$recipe || ($recipe['status'] ?? '') !== 'active') {
                    throw new InvalidArgumentException('Production batch requires an active recipe.');
                }
                if (!in_array((string) ($recipe['recipe_type'] ?? ''), ['batch_prepared', 'hybrid', 'sub_recipe'], true)) {
                    throw new InvalidArgumentException('Production batch recipe type must be batch, hybrid, or sub-recipe.');
                }

                $scope = $this->scopeResolver->resolveForConn($conn, [
                    'pos_tenant' => (int) ($recipe['pos_tenant'] ?? $actor->posTenant),
                    'pos_branch' => (int) ($recipe['pos_branch'] ?? $actor->posBranch),
                    'branch_uuid' => $recipe['branch_uuid'] ?? $actor->branchUuid,
                    'store_id' => $this->nonNegativeInt($input['store_id'] ?? 0),
                ], 'write');

                $batch = $this->production->createDraft($conn, [
                    'pos_tenant' => $scope->posTenant,
                    'pos_branch' => $scope->posBranch,
                    'branch_uuid' => $scope->branchUuid,
                    'store_id' => $scope->storeId,
                    'recipe_id' => $recipeId,
                    'output_item_id' => (int) $recipe['sellable_item_id'],
                    'planned_output_qty' => $this->positiveDecimal($input['planned_output_qty'] ?? '0', 'Planned output quantity is required.'),
                    'notes' => $this->nullableText($input['notes'] ?? null),
                ], $actor);

                return $this->result('Production batch draft created.', (int) $batch['id']);

            case 'commit':
                $batchId = $this->positiveInt($input['batch_id'] ?? null, 'Production batch id is required.');
                $this->production->commit($conn, $batchId, [
                    'actual_output_qty' => $this->positiveDecimal($input['actual_output_qty'] ?? '0', 'Actual output quantity is required.'),
                    'variance_reason' => $this->nullableText($input['variance_reason'] ?? null),
                ], $actor);

                return $this->result('Production batch committed.', $batchId);

            case 'cancel':
                $batchId = $this->positiveInt($input['batch_id'] ?? null, 'Production batch id is required.');
                $this->production->cancel(
                    $conn,
                    $batchId,
                    $this->nullableText($input['cancel_reason'] ?? null) ?: 'operator cancelled',
                    $actor
                );

                return $this->result('Production batch cancelled.', $batchId);
        }

        throw new InvalidArgumentException('Unsupported production batch action.');
    }

    private function result(string $message, int $batchId): array
    {
        return [
            'success' => true,
            'message' => $message,
            'batch_id' => $batchId,
        ];
    }

    private function positiveInt($value, string $message): int
    {
        $int = (int) $value;
        if ($int > 0) {
            return $int;
        }

        throw new InvalidArgumentException($message);
    }

    private function nonNegativeInt($value): int
    {
        return max(0, (int) $value);
    }

    private function positiveDecimal($value, string $message): string
    {
        $text = trim((string) $value);
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $text)) {
            throw new InvalidArgumentException($message);
        }

        $decimal = RecipeDecimal::normalize($text);
        if (RecipeDecimal::compare($decimal, '0') > 0) {
            return $decimal;
        }

        throw new InvalidArgumentException($message);
    }

    private function nullableText($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
