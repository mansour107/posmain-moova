<?php

require_once __DIR__ . '/DTO/RecipeActorContext.php';
require_once __DIR__ . '/RecipeAuditService.php';
require_once __DIR__ . '/RecipeDecimal.php';
require_once __DIR__ . '/RecipeFeatureFlags.php';
require_once __DIR__ . '/RecipePermissionService.php';
require_once __DIR__ . '/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/Repository/RecipeRepository.php';

class RecipeDefinitionService
{
    private $flags;
    private $recipes;
    private $lines;
    private $audit;
    private $permissions;

    public function __construct(
        ?RecipeFeatureFlags $flags = null,
        ?RecipeRepository $recipes = null,
        ?RecipeLineRepository $lines = null,
        ?RecipeAuditService $audit = null,
        ?RecipePermissionService $permissions = null
    ) {
        $this->flags = $flags ?: new RecipeFeatureFlags();
        $this->recipes = $recipes ?: new RecipeRepository();
        $this->lines = $lines ?: new RecipeLineRepository();
        $this->audit = $audit ?: new RecipeAuditService();
        $this->permissions = $permissions ?: new RecipePermissionService();
    }

    public function createDraft(mysqli $conn, array $data, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $payload = array_merge([
            'recipe_uuid' => $this->uuid(),
            'pos_tenant' => $actor->posTenant,
            'pos_branch' => $actor->posBranch,
            'branch_uuid' => $actor->branchUuid,
            'status' => 'draft',
            'version_number' => 1,
            'created_by' => $actor->userId,
        ], $data);
        $this->validateHeaderPayload($payload);

        $recipeId = $this->recipes->createHeader($conn, $payload);
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        $this->audit->record($conn, $actor, 'create_draft', 'recipe_header', $recipeId, $recipeId, null, $recipe);

        return $recipe;
    }

    public function updateDraft(mysqli $conn, int $recipeId, array $data, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $before = $this->requireRecipe($conn, $recipeId);
        $this->assertDraft($before);
        $this->validateHeaderPayload(array_merge($before, $data));
        $this->recipes->updateDraft($conn, $recipeId, $data);

        $after = $this->requireRecipe($conn, $recipeId);
        $this->audit->record($conn, $actor, 'update_draft', 'recipe_header', $recipeId, $recipeId, $before, $after);

        return $after;
    }

    public function addLine(mysqli $conn, int $recipeId, array $lineData, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $recipe = $this->requireRecipe($conn, $recipeId);
        $this->assertDraft($recipe);

        $payload = array_merge([
            'recipe_id' => $recipeId,
            'line_uuid' => $this->uuid(),
        ], $lineData);
        $this->validateLinePayload($payload);

        $lineId = $this->lines->createLine($conn, $payload);
        $line = $this->lines->findLineById($conn, $lineId);
        $this->audit->record($conn, $actor, 'add_line', 'recipe_line', $lineId, $recipeId, null, $line);

        return $line;
    }

    public function updateLine(mysqli $conn, int $lineId, array $lineData, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $before = $this->requireLine($conn, $lineId);
        $recipe = $this->requireRecipe($conn, (int) $before['recipe_id']);
        $this->assertDraft($recipe);
        $this->validateLinePayload(array_merge($before, $lineData));
        $this->lines->updateLine($conn, $lineId, $lineData);

        $after = $this->requireLine($conn, $lineId);
        $this->audit->record($conn, $actor, 'update_line', 'recipe_line', $lineId, (int) $after['recipe_id'], $before, $after);

        return $after;
    }

    public function removeLine(mysqli $conn, int $lineId, RecipeActorContext $actor): void
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $before = $this->requireLine($conn, $lineId);
        $recipe = $this->requireRecipe($conn, (int) $before['recipe_id']);
        $this->assertDraft($recipe);
        $this->lines->removeLine($conn, $lineId);
        $this->audit->record($conn, $actor, 'remove_line', 'recipe_line', $lineId, (int) $before['recipe_id'], $before, null);
    }

    public function approve(mysqli $conn, int $recipeId, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanApprove($actor);

        $recipe = $this->requireRecipe($conn, $recipeId);
        $this->assertDraft($recipe);
        $this->validateCanActivate($conn, $recipeId);
        $stmt = $conn->prepare('UPDATE recipe_headers SET approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('ii', $actor->userId, $recipeId);
        $stmt->execute();
        $stmt->close();

        $after = $this->requireRecipe($conn, $recipeId);
        $this->audit->record($conn, $actor, 'approve', 'recipe_header', $recipeId, $recipeId, $recipe, $after);

        return $after;
    }

    public function activate(mysqli $conn, int $recipeId, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanApprove($actor);

        $conn->begin_transaction();
        try {
            $recipe = $this->recipes->findHeaderByIdForUpdate($conn, $recipeId);
            if (!$recipe) {
                throw new RuntimeException('Recipe not found.');
            }
            $this->assertDraft($recipe);
            $this->lockRecipeSet($conn, (int) $recipe['pos_tenant'], (int) $recipe['pos_branch'], (int) $recipe['sellable_item_id']);
            $this->validateCanActivate($conn, $recipeId);

            $this->recipes->archiveActiveForItem(
                $conn,
                (int) $recipe['pos_tenant'],
                (int) $recipe['pos_branch'],
                (int) $recipe['sellable_item_id'],
                $recipeId
            );
            $this->recipes->updateStatus($conn, $recipeId, 'active', $actor->userId);
            $after = $this->requireRecipe($conn, $recipeId);
            $this->audit->record($conn, $actor, 'activate', 'recipe_header', $recipeId, $recipeId, $recipe, $after);
            $conn->commit();

            return $after;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function archive(mysqli $conn, int $recipeId, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanApprove($actor);

        $before = $this->requireRecipe($conn, $recipeId);
        $this->recipes->updateStatus($conn, $recipeId, 'archived', $actor->userId);
        $after = $this->requireRecipe($conn, $recipeId);
        $this->audit->record($conn, $actor, 'archive', 'recipe_header', $recipeId, $recipeId, $before, $after);

        return $after;
    }

    public function cloneAsNewVersion(mysqli $conn, int $activeRecipeId, RecipeActorContext $actor): array
    {
        $this->assertRecipeManagementWritesEnabled();
        $this->permissions->assertCanEdit($actor);

        $conn->begin_transaction();
        try {
            $active = $this->recipes->findHeaderByIdForUpdate($conn, $activeRecipeId);
            if (!$active) {
                throw new RuntimeException('Recipe not found.');
            }
            if ($active['status'] !== 'active') {
                throw new RuntimeException('Only active recipes can be cloned into a new version.');
            }

            $nextVersion = $this->recipes->maxVersionForItem(
                $conn,
                (int) $active['pos_tenant'],
                (int) $active['pos_branch'],
                (int) $active['sellable_item_id']
            ) + 1;
            $draft = $active;
            unset($draft['id']);
            $draft['recipe_uuid'] = $this->uuid();
            $draft['status'] = 'draft';
            $draft['version_number'] = $nextVersion;
            $draft['created_by'] = $actor->userId;
            $draft['approved_by'] = null;
            $draft['approved_at'] = null;
            $draft['effective_to'] = null;
            $draftId = $this->recipes->createHeader($conn, $draft);

            foreach ($this->lines->findLinesByRecipeId($conn, $activeRecipeId) as $line) {
                unset($line['id']);
                $line['recipe_id'] = $draftId;
                $line['line_uuid'] = $this->uuid();
                $this->lines->createLine($conn, $line);
            }

            $after = $this->requireRecipe($conn, $draftId);
            $this->audit->record($conn, $actor, 'clone_new_version', 'recipe_header', $draftId, $draftId, $active, $after);
            $conn->commit();

            return $after;
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }

    public function getActiveRecipeForItem(mysqli $conn, int $itemId, int $posTenant, int $posBranch): ?array
    {
        return $this->recipes->findActiveHeaderForItem($conn, $posTenant, $posBranch, $itemId);
    }

    private function assertRecipeManagementWritesEnabled(): void
    {
        if ($this->flags->isEnabled() && !in_array($this->flags->mode(), ['schema_only', 'read_only'], true)) {
            return;
        }

        throw new RuntimeException('Recipe management writes are disabled by feature flags.');
    }

    private function requireRecipe(mysqli $conn, int $recipeId): array
    {
        $recipe = $this->recipes->findHeaderById($conn, $recipeId);
        if (!$recipe) {
            throw new RuntimeException('Recipe not found.');
        }

        return $recipe;
    }

    private function requireLine(mysqli $conn, int $lineId): array
    {
        $line = $this->lines->findLineById($conn, $lineId);
        if (!$line) {
            throw new RuntimeException('Recipe line not found.');
        }

        return $line;
    }

    private function assertDraft(array $recipe): void
    {
        if (($recipe['status'] ?? null) === 'draft') {
            return;
        }

        throw new RuntimeException('Only draft recipes are editable.');
    }

    private function validateHeaderPayload(array $data): void
    {
        if ((int) ($data['sellable_item_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Recipe sellable item is required.');
        }
        if (trim((string) ($data['recipe_name'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe name is required.');
        }
        if (!RecipeDecimal::isPositive((string) ($data['yield_qty'] ?? '1'))) {
            throw new InvalidArgumentException('Recipe yield quantity must be positive.');
        }
    }

    private function validateLinePayload(array $data): void
    {
        $lineType = (string) ($data['line_type'] ?? 'ingredient');
        $modifierBehavior = (string) ($data['modifier_behavior'] ?? 'additive');
        if (!in_array($modifierBehavior, ['additive', 'substitution_remove', 'substitution_add'], true)) {
            throw new InvalidArgumentException('Invalid recipe modifier behavior.');
        }
        if (strlen(trim((string) ($data['substitution_group'] ?? ''))) > 64) {
            throw new InvalidArgumentException('Recipe substitution group is too long.');
        }
        if ($lineType === 'sub_recipe') {
            if ((int) ($data['sub_recipe_id'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Sub-recipe lines require sub_recipe_id.');
            }
        } elseif ($lineType !== 'labor_placeholder' && (int) ($data['ingredient_item_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Recipe ingredient lines require ingredient_item_id.');
        }

        if ($lineType !== 'labor_placeholder' && !RecipeDecimal::isPositive((string) ($data['qty_per_yield'] ?? '0'))) {
            throw new InvalidArgumentException('Recipe line quantity must be positive.');
        }
        if (!RecipeDecimal::isPositive((string) ($data['unit_conversion_to_base'] ?? '1'))) {
            throw new InvalidArgumentException('Recipe line unit conversion must be positive.');
        }
        if (strpos((string) ($data['wastage_percent'] ?? '0'), '-') === 0) {
            throw new InvalidArgumentException('Recipe line wastage cannot be negative.');
        }
    }

    private function validateCanActivate(mysqli $conn, int $recipeId): void
    {
        $lines = $this->lines->findLinesByRecipeId($conn, $recipeId);
        foreach ($lines as $line) {
            if ((int) $line['is_required'] === 1 && $line['line_type'] !== 'labor_placeholder') {
                return;
            }
        }

        throw new RuntimeException('A recipe needs at least one required stock-affecting line before activation.');
    }

    private function lockRecipeSet(mysqli $conn, int $posTenant, int $posBranch, int $sellableItemId): void
    {
        $stmt = $conn->prepare("
SELECT id
FROM recipe_headers
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND sellable_item_id = ?
FOR UPDATE");
        $stmt->bind_param('iii', $posTenant, $posBranch, $sellableItemId);
        $stmt->execute();
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
