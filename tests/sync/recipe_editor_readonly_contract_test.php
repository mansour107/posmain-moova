<?php

$root = dirname(__DIR__, 2);
$page = recipeEditorContractSource($root . '/recipe_editor.php');
$service = recipeEditorContractSource($root . '/classes/Recipe/RecipeEditorReadService.php');
$reports = recipeEditorContractSource($root . '/reports.php');

recipeEditorContractAssert(strpos($page, 'RecipeEditorReadService') !== false, 'recipe editor page should use read service');
recipeEditorContractAssert(strpos($page, 'require_login()') !== false, 'recipe editor page should require login');
recipeEditorContractAssert(strpos($page, 'posmain_recipe_editor_can_view') !== false, 'recipe editor page should enforce view permission');
recipeEditorContractAssert(strpos($page, 'posmain_recipe_editor_can_view_cost') !== false, 'recipe editor page should separate cost visibility from read-only view permission');
recipeEditorContractAssert(strpos($page, 'Cost snapshots are hidden') !== false, 'recipe editor page should disclose hidden costs for non-cost viewers');
recipeEditorContractAssert(strpos($page, 'if ($recipeEditorCanViewCost)') !== false, 'recipe editor page should gate cost snapshot output');
recipeEditorContractAssert(strpos($page, 'This screen is read-only') !== false, 'recipe editor page should state read-only mode');
recipeEditorContractAssert(strpos($page, 'RecipeDefinitionService') === false, 'recipe editor page must not call mutation definition service');
recipeEditorContractAssert(strpos($page, 'RecipeOrderLifecycleService') === false, 'recipe editor page must not call lifecycle service');

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', '->create', '->activate', '->approve', '->archive'] as $writeNeedle) {
    recipeEditorContractAssert(strpos($page, $writeNeedle) === false, 'recipe editor page should not contain write operation: ' . $writeNeedle);
}

recipeEditorContractAssert(strpos($service, 'class RecipeEditorReadService') !== false, 'read service class missing');
recipeEditorContractAssert(strpos($service, 'SELECT') !== false, 'read service should query recipe data');
recipeEditorContractAssert(strpos($service, 'recipe_lines') !== false, 'read service should expose recipe lines');
recipeEditorContractAssert(strpos($service, 'recipe_cost_snapshots') !== false, 'read service should expose latest cost snapshot');
recipeEditorContractAssert(strpos($service, 'recipe_availability_cache') !== false, 'read service should expose cached availability');
recipeEditorContractAssert(strpos($service, 'recipe_audit_log') !== false, 'read service should expose recent audit');
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $writeNeedle) {
    recipeEditorContractAssert(strpos($service, $writeNeedle) === false, 'read service should not contain write operation: ' . $writeNeedle);
}

recipeEditorContractAssert(strpos($reports, 'recipe_editor.php') !== false, 'reports page should link to recipe editor');

echo "recipe-editor-readonly-contract-ok\n";

function recipeEditorContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeEditorContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
