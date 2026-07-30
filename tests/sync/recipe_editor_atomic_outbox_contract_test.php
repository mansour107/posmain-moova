<?php

$root = dirname(__DIR__, 2);
$editor = file_get_contents($root . '/classes/Recipe/RecipeEditorMutationService.php');
$definition = file_get_contents($root . '/classes/Recipe/RecipeDefinitionService.php');
$recorder = file_get_contents($root . '/classes/Sync/OperationalSyncRecorder.php');

function recipeEditorAtomicContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

recipeEditorAtomicContractAssert(
    strpos($editor, '(new SyncBranchIdentity())->ensure($conn, posmain_operational_sync_config());') !== false,
    'recipe editor must reconcile branch identity before opening its business transaction'
);
recipeEditorAtomicContractAssert(
    strpos($editor, '$conn->begin_transaction();') !== false
        && strpos($editor, '$result = $this->dispatch($conn, $action, $input, $actor);') !== false
        && strpos($editor, '$conn->commit();') !== false
        && strpos($editor, '$conn->rollback();') !== false,
    'recipe editor must own one transaction around mutation and sync recording'
);
recipeEditorAtomicContractAssert(
    substr_count($editor, 'posmain_record_recipe_sync(') === 1
        && strpos($editor, "'recipe.saved',\n                true,\n                \$this->syncActorUserId") !== false,
    'recipe editor must record its recipe snapshot exactly once and fail closed with the authenticated actor'
);
recipeEditorAtomicContractAssert(
    substr_count($editor, '->activate($conn, $recipeId, $actor, false)') === 1
        && substr_count($editor, '->cloneAsNewVersion(') === 2
        && substr_count($editor, ', $actor, false)') >= 3,
    'recipe editor must suppress nested definition transactions while retaining the outer atomic boundary'
);
recipeEditorAtomicContractAssert(
    strpos($definition, 'bool $manageTransaction = true') !== false
        && substr_count($definition, 'if ($manageTransaction)') >= 6,
    'definition service must retain transaction ownership by default for compatibility callers'
);
recipeEditorAtomicContractAssert(
    strpos($recorder, 'bool $failClosed = false') !== false
        && strpos($recorder, 'if ($failClosed && $event === null)') !== false
        && strpos($recorder, 'if ($failClosed) {' . "\n" . '            throw $exception;') !== false,
    'recipe sync recorder must retain legacy best-effort mode but support explicit fail-closed writes'
);

echo "recipe-editor-atomic-outbox-contract-ok\n";
