<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorMutationService.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorPreviewService.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorReadService.php';
require_once __DIR__ . '/classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/classes/Recipe/RecipeScopeResolver.php';

require_login();
if (!posmain_recipe_manage_can_edit($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeManageFlags = new RecipeFeatureFlags();
$recipeManageMode = $recipeManageFlags->mode();
$recipeManageWritesEnabled = $recipeManageFlags->isEnabled() && !in_array($recipeManageMode, ['schema_only', 'read_only'], true);
$recipeManageReadService = new RecipeEditorReadService();
$recipeManageFlash = posmain_recipe_manage_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('recipe_editor');

    try {
        $result = (new RecipeEditorMutationService())->handle(
            $conn,
            (string) ($_POST['action'] ?? ''),
            $_POST,
            posmain_recipe_manage_actor($conn)
        );
        posmain_recipe_manage_set_flash('success', $result['message'] ?? 'Recipe updated.');
        posmain_recipe_manage_redirect((int) ($result['recipe_id'] ?? 0));
    } catch (Throwable $exception) {
        posmain_recipe_manage_set_flash('danger', $exception->getMessage());
        posmain_recipe_manage_redirect((int) ($_POST['recipe_id'] ?? 0));
    }
}

$recipeManageFilters = [
    'status' => $_GET['status'] ?? '',
    'q' => $_GET['q'] ?? '',
    'limit' => 100,
];
$recipeManageRows = $recipeManageReadService->listRecipes($conn, $recipeManageFilters);
$recipeManageCreateItemId = isset($_GET['item_id']) && (int) $_GET['item_id'] > 0 ? (int) $_GET['item_id'] : 0;
$selectedRecipeId = isset($_GET['recipe_id']) && (int) $_GET['recipe_id'] > 0 ? (int) $_GET['recipe_id'] : 0;
$selectedRecipe = $selectedRecipeId > 0 ? $recipeManageReadService->recipeDetail($conn, $selectedRecipeId) : null;
$selectedHeader = $selectedRecipe['header'] ?? null;
$selectedIsDraft = $selectedHeader && ($selectedHeader['status'] ?? '') === 'draft';
$canApproveRecipe = posmain_recipe_manage_can_approve($conn);
$canViewRecipeCost = posmain_recipe_manage_can_view_cost($conn);
$recipeManagePreview = null;
$recipeManagePreviewError = null;
if ($selectedHeader && isset($_GET['preview'])) {
    try {
        $recipeManagePreview = (new RecipeEditorPreviewService())->preview(
            $conn,
            (int) $selectedHeader['id'],
            posmain_recipe_manage_preview_context($_GET, $selectedHeader),
            $canViewRecipeCost
        );
    } catch (Throwable $exception) {
        $recipeManagePreviewError = $exception->getMessage();
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h3 class="card-title">Recipe Draft Management</h3>
                </div>
                <div class="card-body">
                    <?php if ($recipeManageFlash): ?>
                        <div class="alert alert-<?= posmain_recipe_manage_h($recipeManageFlash['type']) ?>">
                            <?= posmain_recipe_manage_h($recipeManageFlash['message']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$recipeManageWritesEnabled): ?>
                        <div class="alert alert-warning">
                            Recipe writes are disabled by the current feature flags. Mode: <?= posmain_recipe_manage_h($recipeManageMode) ?>.
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-4">
                            <div class="card border-secondary mb-3">
                                <div class="card-header bg-light">Create Draft</div>
                                <div class="card-body">
                                    <form method="POST">
                                        <?= csrf_input('recipe_editor') ?>
                                        <input type="hidden" name="action" value="create_draft">
                                        <div class="mb-2">
                                            <label>Sellable Item ID</label>
                                            <?= posmain_recipe_manage_lookup_input('items', 'sellable', 'create_sellable_item_id', 'Search sellable item') ?>
                                            <input type="number" id="create_sellable_item_id" name="sellable_item_id" class="form-control mt-1" min="1" value="<?= $recipeManageCreateItemId > 0 ? $recipeManageCreateItemId : '' ?>" required>
                                        </div>
                                        <div class="mb-2">
                                            <label>Recipe Name</label>
                                            <input type="text" name="recipe_name" class="form-control" maxlength="255" required>
                                        </div>
                                        <div class="mb-2">
                                            <label>Recipe Type</label>
                                            <select name="recipe_type" class="form-control">
                                                <?php foreach (posmain_recipe_manage_recipe_types() as $value => $label): ?>
                                                    <option value="<?= posmain_recipe_manage_h($value) ?>"><?= posmain_recipe_manage_h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <label>Yield Qty</label>
                                                <input type="text" name="yield_qty" class="form-control" value="1.000000" required>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label>Wastage %</label>
                                                <input type="text" name="default_wastage_percent" class="form-control" value="0.0000">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label>Costing Method</label>
                                            <select name="costing_method" class="form-control">
                                                <option value="item_cost_price">Item cost price</option>
                                                <option value="moving_average">Moving average</option>
                                                <option value="last_purchase">Last purchase</option>
                                                <option value="manual_snapshot">Manual snapshot</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>Create Draft</button>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-secondary">
                                <div class="card-header bg-light">Recipes</div>
                                <div class="card-body">
                                    <form method="GET" class="mb-3">
                                        <div class="input-group">
                                            <input type="text" name="q" class="form-control" value="<?= posmain_recipe_manage_h($recipeManageFilters['q']) ?>" placeholder="Search">
                                            <button class="btn btn-outline-secondary" type="submit">Filter</button>
                                        </div>
                                    </form>
                                    <div class="list-group">
                                        <?php foreach ($recipeManageRows as $row): ?>
                                            <a class="list-group-item list-group-item-action <?= (int) $row['id'] === $selectedRecipeId ? 'active' : '' ?>" href="recipe_manage.php?recipe_id=<?= (int) $row['id'] ?>">
                                                <div class="d-flex justify-content-between">
                                                    <span><?= posmain_recipe_manage_h($row['recipe_name'] ?? '') ?></span>
                                                    <small>v<?= (int) ($row['version_number'] ?? 0) ?></small>
                                                </div>
                                                <small><?= posmain_recipe_manage_h(($row['status'] ?? '') . ' - item ' . (int) ($row['sellable_item_id'] ?? 0)) ?></small>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <?php if ($selectedRecipe): ?>
                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-secondary text-white">
                                        <?= posmain_recipe_manage_h($selectedHeader['recipe_name'] ?? '') ?>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-md-3"><strong>Status:</strong> <?= posmain_recipe_manage_h($selectedHeader['status'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Version:</strong> <?= (int) ($selectedHeader['version_number'] ?? 0) ?></div>
                                            <div class="col-md-3"><strong>Item:</strong> <?= (int) ($selectedHeader['sellable_item_id'] ?? 0) ?></div>
                                            <div class="col-md-3"><strong>Lines:</strong> <?= count($selectedRecipe['lines']) ?></div>
                                        </div>

                                        <div class="d-flex flex-wrap gap-2">
                                            <?php if ($selectedIsDraft && $canApproveRecipe): ?>
                                                <?= posmain_recipe_manage_action_button('approve', (int) $selectedHeader['id'], 'Approve', 'btn-outline-success', $recipeManageWritesEnabled) ?>
                                                <?= posmain_recipe_manage_action_button('activate', (int) $selectedHeader['id'], 'Activate', 'btn-success', $recipeManageWritesEnabled) ?>
                                            <?php endif; ?>
                                            <?php if (($selectedHeader['status'] ?? '') === 'active'): ?>
                                                <?= posmain_recipe_manage_action_button('clone_new_version', (int) $selectedHeader['id'], 'Clone New Version', 'btn-outline-primary', $recipeManageWritesEnabled) ?>
                                            <?php endif; ?>
                                            <?php if (($selectedHeader['status'] ?? '') !== 'archived' && $canApproveRecipe): ?>
                                                <?= posmain_recipe_manage_action_button('archive', (int) $selectedHeader['id'], 'Archive', 'btn-outline-danger', $recipeManageWritesEnabled) ?>
                                            <?php endif; ?>
                                            <a class="btn btn-outline-secondary" href="recipe_editor.php?recipe_id=<?= (int) $selectedHeader['id'] ?>">Read View</a>
                                        </div>

                                        <?php if ($selectedIsDraft): ?>
                                            <hr>
                                            <form method="POST">
                                                <?= csrf_input('recipe_editor') ?>
                                                <input type="hidden" name="action" value="update_draft">
                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                <div class="row">
                                                    <div class="col-md-4 mb-2">
                                                        <label>Recipe Name</label>
                                                        <input type="text" name="recipe_name" class="form-control" maxlength="255" value="<?= posmain_recipe_manage_h($selectedHeader['recipe_name'] ?? '') ?>" required>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Recipe Type</label>
                                                        <select name="recipe_type" class="form-control">
                                                            <?= posmain_recipe_manage_options(posmain_recipe_manage_recipe_types(), $selectedHeader['recipe_type'] ?? 'make_to_order') ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>Yield Qty</label>
                                                        <input type="text" name="yield_qty" class="form-control" value="<?= posmain_recipe_manage_h($selectedHeader['yield_qty'] ?? '1.000000') ?>" required>
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>Wastage %</label>
                                                        <input type="text" name="default_wastage_percent" class="form-control" value="<?= posmain_recipe_manage_h($selectedHeader['default_wastage_percent'] ?? '0.0000') ?>">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Costing Method</label>
                                                        <select name="costing_method" class="form-control">
                                                            <?= posmain_recipe_manage_options(posmain_recipe_manage_costing_methods(), $selectedHeader['costing_method'] ?? 'item_cost_price') ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2 d-flex align-items-end">
                                                        <div class="form-check">
                                                            <input type="checkbox" name="requires_recipe_for_sale" value="1" class="form-check-input" id="requires_recipe_for_sale" <?= !empty($selectedHeader['requires_recipe_for_sale']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="requires_recipe_for_sale">Requires recipe for sale</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 mb-2 d-flex align-items-end">
                                                        <div class="form-check">
                                                            <input type="checkbox" name="allow_sale_without_stock" value="1" class="form-check-input" id="allow_sale_without_stock" <?= !empty($selectedHeader['allow_sale_without_stock']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="allow_sale_without_stock">Allow sale without stock</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3 mb-2 d-flex align-items-end">
                                                        <button type="submit" class="btn btn-primary w-100" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>Save Draft Header</button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-light">Cost And Availability Preview</div>
                                    <div class="card-body">
                                        <form method="GET" class="row">
                                            <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                            <input type="hidden" name="preview" value="1">
                                            <div class="col-md-2 mb-2">
                                                <label>Store</label>
                                                <input type="number" name="store_id" class="form-control" min="0" value="<?= (int) ($_GET['store_id'] ?? 0) ?>">
                                            </div>
                                            <div class="col-md-2 mb-2">
                                                <label>Order Type</label>
                                                <select name="order_type" class="form-control">
                                                    <?= posmain_recipe_manage_options(posmain_recipe_manage_order_types(), $_GET['order_type'] ?? 'takeaway') ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 mb-2">
                                                <label>Channel</label>
                                                <select name="channel" class="form-control">
                                                    <?= posmain_recipe_manage_options(posmain_recipe_manage_channels(), $_GET['channel'] ?? 'pos') ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 mb-2">
                                                <label>Safety Stock</label>
                                                <input type="text" name="safety_stock" class="form-control" value="<?= posmain_recipe_manage_h($_GET['safety_stock'] ?? '0') ?>">
                                            </div>
                                            <?php if ($canViewRecipeCost): ?>
                                                <div class="col-md-2 mb-2">
                                                    <label>Costing</label>
                                                    <select name="costing_method" class="form-control">
                                                        <?= posmain_recipe_manage_options(posmain_recipe_manage_costing_methods(), $_GET['costing_method'] ?? ($selectedHeader['costing_method'] ?? 'item_cost_price')) ?>
                                                    </select>
                                                </div>
                                            <?php endif; ?>
                                            <div class="col-md-2 mb-2 d-flex align-items-end">
                                                <button type="submit" class="btn btn-outline-primary w-100">Preview</button>
                                            </div>
                                        </form>

                                        <?php if ($recipeManagePreviewError): ?>
                                            <div class="alert alert-warning mt-3 mb-0"><?= posmain_recipe_manage_h($recipeManagePreviewError) ?></div>
                                        <?php elseif ($recipeManagePreview): ?>
                                            <?php $availabilityPreview = $recipeManagePreview['availability'] ?? []; ?>
                                            <div class="row mt-3">
                                                <?php if ($canViewRecipeCost && !empty($recipeManagePreview['cost'])): ?>
                                                    <?php $costPreview = $recipeManagePreview['cost']; ?>
                                                    <div class="col-md-4">
                                                        <strong>Cost per yield:</strong>
                                                        <?= posmain_recipe_manage_qty($costPreview['cost_per_yield'] ?? '') ?>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <strong>Cost per sell unit:</strong>
                                                        <?= posmain_recipe_manage_qty($costPreview['cost_per_sell_unit'] ?? '') ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="col-md-4">
                                                    <strong>Can make:</strong>
                                                    <?= posmain_recipe_manage_qty($availabilityPreview['effective_available_qty'] ?? '') ?>
                                                </div>
                                                <div class="col-md-12 mt-2">
                                                    <strong>Availability:</strong>
                                                    <?= !empty($availabilityPreview['effective_is_available']) ? 'Available' : 'Unavailable' ?>
                                                    <?php if (!empty($availabilityPreview['unavailable_reason'])): ?>
                                                        <span class="text-muted">- <?= posmain_recipe_manage_h($availabilityPreview['unavailable_reason']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-light">Lines</div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Type</th>
                                                        <th>Ingredient / Sub-recipe</th>
                                                        <th class="text-end">Qty/Yield</th>
                                                        <th>Modifier</th>
                                                        <th>Scope</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($selectedRecipe['lines'] as $line): ?>
                                                        <tr>
                                                            <td><?= (int) $line['id'] ?></td>
                                                            <td><?= posmain_recipe_manage_h($line['line_type'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($line['ingredient_item_name'] ?? $line['sub_recipe_name'] ?? '') ?></td>
                                                            <td class="text-end"><?= posmain_recipe_manage_h($line['qty_per_yield'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h(($line['modifier_behavior'] ?? 'additive') . (($line['substitution_group'] ?? '') !== '' ? ' / ' . $line['substitution_group'] : '')) ?></td>
                                                            <td><?= posmain_recipe_manage_h(($line['order_type'] ?? 'any') . ' / ' . ($line['channel'] ?? 'any')) ?></td>
                                                            <td class="text-end">
                                                                <?php if ($selectedIsDraft): ?>
                                                                    <form method="POST" class="d-inline">
                                                                        <?= csrf_input('recipe_editor') ?>
                                                                        <input type="hidden" name="action" value="remove_line">
                                                                        <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                                        <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>Remove</button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($selectedIsDraft): ?>
                                    <?php foreach ($selectedRecipe['lines'] as $line): ?>
                                        <div class="card border-secondary mb-3">
                                            <div class="card-header bg-light">Edit Line #<?= (int) $line['id'] ?></div>
                                            <div class="card-body">
                                                <form method="POST">
                                                    <?= csrf_input('recipe_editor') ?>
                                                    <input type="hidden" name="action" value="update_line">
                                                    <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                    <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                                                    <div class="row">
                                                        <div class="col-md-3 mb-2">
                                                            <label>Line Type</label>
                                                            <select name="line_type" class="form-control">
                                                                <?= posmain_recipe_manage_options(posmain_recipe_manage_line_types(), $line['line_type'] ?? 'ingredient') ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Ingredient Item ID</label>
                                                            <?= posmain_recipe_manage_lookup_input('items', 'stock_component', 'line_' . (int) $line['id'] . '_ingredient_item_id', 'Search ingredient or packaging') ?>
                                                            <input type="number" id="line_<?= (int) $line['id'] ?>_ingredient_item_id" name="ingredient_item_id" class="form-control mt-1" min="1" value="<?= posmain_recipe_manage_h($line['ingredient_item_id'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Sub-recipe ID</label>
                                                            <?= posmain_recipe_manage_lookup_input('sub_recipes', '', 'line_' . (int) $line['id'] . '_sub_recipe_id', 'Search sub-recipe') ?>
                                                            <input type="number" id="line_<?= (int) $line['id'] ?>_sub_recipe_id" name="sub_recipe_id" class="form-control mt-1" min="1" value="<?= posmain_recipe_manage_h($line['sub_recipe_id'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Qty/Yield</label>
                                                            <input type="text" name="qty_per_yield" class="form-control" value="<?= posmain_recipe_manage_h($line['qty_per_yield'] ?? '1.000000') ?>" required>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Conversion</label>
                                                            <input type="text" name="unit_conversion_to_base" class="form-control" value="<?= posmain_recipe_manage_h($line['unit_conversion_to_base'] ?? '1.00000000') ?>" required>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Wastage %</label>
                                                            <input type="text" name="wastage_percent" class="form-control" value="<?= posmain_recipe_manage_h($line['wastage_percent'] ?? '0.0000') ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Order Type</label>
                                                            <select name="order_type" class="form-control">
                                                                <?= posmain_recipe_manage_options(posmain_recipe_manage_order_types(), $line['order_type'] ?? 'any') ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Channel</label>
                                                            <select name="channel" class="form-control">
                                                                <?= posmain_recipe_manage_options(posmain_recipe_manage_channels(), $line['channel'] ?? 'any') ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Modifier Group ID</label>
                                                            <input type="number" id="line_<?= (int) $line['id'] ?>_modifier_group_id" name="modifier_group_id" class="form-control" min="1" value="<?= posmain_recipe_manage_h($line['modifier_group_id'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Modifier Option ID</label>
                                                            <?= posmain_recipe_manage_lookup_input('modifier_options', '', 'line_' . (int) $line['id'] . '_modifier_option_id', 'Search modifier option', ['target_group_input' => 'line_' . (int) $line['id'] . '_modifier_group_id']) ?>
                                                            <input type="number" id="line_<?= (int) $line['id'] ?>_modifier_option_id" name="modifier_option_id" class="form-control mt-1" min="1" value="<?= posmain_recipe_manage_h($line['modifier_option_id'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Modifier Behavior</label>
                                                            <select name="modifier_behavior" class="form-control">
                                                                <?= posmain_recipe_manage_options(posmain_recipe_manage_modifier_behaviors(), $line['modifier_behavior'] ?? 'additive') ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 mb-2">
                                                            <label>Substitution Group</label>
                                                            <input type="text" name="substitution_group" class="form-control" maxlength="64" value="<?= posmain_recipe_manage_h($line['substitution_group'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-md-2 mb-2">
                                                            <label>Sort</label>
                                                            <input type="number" name="sort_order" class="form-control" min="0" value="<?= (int) ($line['sort_order'] ?? 0) ?>">
                                                        </div>
                                                        <div class="col-md-2 mb-2 d-flex align-items-end">
                                                            <div class="form-check">
                                                                <input type="checkbox" name="is_required" value="1" class="form-check-input" id="is_required_<?= (int) $line['id'] ?>" <?= !empty($line['is_required']) ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="is_required_<?= (int) $line['id'] ?>">Required</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-12 mb-3">
                                                            <label>Notes</label>
                                                            <input type="text" name="notes" class="form-control" maxlength="500" value="<?= posmain_recipe_manage_h($line['notes'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="btn btn-primary" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>Save Line</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <div class="card border-secondary">
                                        <div class="card-header bg-light">Add Line</div>
                                        <div class="card-body">
                                            <form method="POST">
                                                <?= csrf_input('recipe_editor') ?>
                                                <input type="hidden" name="action" value="add_line">
                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                <div class="row">
                                                    <div class="col-md-3 mb-2">
                                                        <label>Line Type</label>
                                                        <select name="line_type" class="form-control">
                                                            <option value="ingredient">Ingredient</option>
                                                            <option value="packaging">Packaging</option>
                                                            <option value="modifier_ingredient">Modifier ingredient</option>
                                                            <option value="sub_recipe">Sub-recipe</option>
                                                            <option value="labor_placeholder">Labor placeholder</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Ingredient Item ID</label>
                                                        <?= posmain_recipe_manage_lookup_input('items', 'stock_component', 'add_ingredient_item_id', 'Search ingredient or packaging') ?>
                                                        <input type="number" id="add_ingredient_item_id" name="ingredient_item_id" class="form-control mt-1" min="1">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Sub-recipe ID</label>
                                                        <?= posmain_recipe_manage_lookup_input('sub_recipes', '', 'add_sub_recipe_id', 'Search sub-recipe') ?>
                                                        <input type="number" id="add_sub_recipe_id" name="sub_recipe_id" class="form-control mt-1" min="1">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Qty/Yield</label>
                                                        <input type="text" name="qty_per_yield" class="form-control" value="1.000000" required>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Conversion</label>
                                                        <input type="text" name="unit_conversion_to_base" class="form-control" value="1.00000000" required>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Wastage %</label>
                                                        <input type="text" name="wastage_percent" class="form-control" value="0.0000">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Order Type</label>
                                                        <select name="order_type" class="form-control">
                                                            <option value="any">Any</option>
                                                            <option value="dine_in">Dine in</option>
                                                            <option value="takeaway">Takeaway</option>
                                                            <option value="delivery">Delivery</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Channel</label>
                                                        <select name="channel" class="form-control">
                                                            <option value="any">Any</option>
                                                            <option value="pos">POS</option>
                                                            <option value="table">Table</option>
                                                            <option value="moova">Moova</option>
                                                            <option value="cofe">Cofe</option>
                                                            <option value="api">API</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Modifier Group ID</label>
                                                        <input type="number" id="add_modifier_group_id" name="modifier_group_id" class="form-control" min="1">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Modifier Option ID</label>
                                                        <?= posmain_recipe_manage_lookup_input('modifier_options', '', 'add_modifier_option_id', 'Search modifier option', ['target_group_input' => 'add_modifier_group_id']) ?>
                                                        <input type="number" id="add_modifier_option_id" name="modifier_option_id" class="form-control mt-1" min="1">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Modifier Behavior</label>
                                                        <select name="modifier_behavior" class="form-control">
                                                            <?= posmain_recipe_manage_options(posmain_recipe_manage_modifier_behaviors(), 'additive') ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Substitution Group</label>
                                                        <input type="text" name="substitution_group" class="form-control" maxlength="64">
                                                    </div>
                                                    <div class="col-md-3 mb-2">
                                                        <label>Sort</label>
                                                        <input type="number" name="sort_order" class="form-control" min="0" value="0">
                                                    </div>
                                                    <div class="col-md-3 mb-2 d-flex align-items-end">
                                                        <div class="form-check">
                                                            <input type="checkbox" name="is_required" value="1" class="form-check-input" id="is_required" checked>
                                                            <label class="form-check-label" for="is_required">Required</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-12 mb-3">
                                                        <label>Notes</label>
                                                        <input type="text" name="notes" class="form-control" maxlength="500">
                                                    </div>
                                                </div>
                                                <button type="submit" class="btn btn-primary" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>Add Line</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="card border-secondary mt-3">
                                    <div class="card-header bg-light">Version History</div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Version</th>
                                                        <th>Status</th>
                                                        <th>Name</th>
                                                        <th>Approved</th>
                                                        <th>Updated</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (($selectedRecipe['versions'] ?? []) as $version): ?>
                                                        <tr class="<?= (int) $version['id'] === (int) $selectedHeader['id'] ? 'table-primary' : '' ?>">
                                                            <td><?= (int) ($version['version_number'] ?? 0) ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['status'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['recipe_name'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['approved_at'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['updated_at'] ?? '') ?></td>
                                                            <td><a class="btn btn-sm btn-outline-secondary" href="recipe_manage.php?recipe_id=<?= (int) $version['id'] ?>">View</a></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary">Select a recipe draft or create a new one.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    const scope = {
        pos_tenant: <?= (int) ($selectedHeader['pos_tenant'] ?? 0) ?>,
        pos_branch: <?= (int) ($selectedHeader['pos_branch'] ?? 0) ?>,
        exclude_recipe_id: <?= (int) ($selectedHeader['id'] ?? 0) ?>
    };
    const timers = new WeakMap();

    function clearResults(input) {
        const box = input.parentElement ? input.parentElement.querySelector('.recipe-lookup-results') : null;
        if (box) {
            box.innerHTML = '';
            box.style.display = 'none';
        }
    }

    function renderResults(input, items) {
        const box = input.parentElement ? input.parentElement.querySelector('.recipe-lookup-results') : null;
        if (!box) {
            return;
        }
        box.innerHTML = '';
        if (!items.length) {
            box.style.display = 'none';
            return;
        }
        items.forEach(function (item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action py-1';
            button.textContent = item.label || ('#' + item.id);
            button.addEventListener('click', function () {
                const target = document.getElementById(input.dataset.targetInput || '');
                if (target) {
                    target.value = item.id || '';
                }
                const groupTarget = document.getElementById(input.dataset.targetGroupInput || '');
                if (groupTarget && item.group_id) {
                    groupTarget.value = item.group_id;
                }
                input.value = button.textContent;
                clearResults(input);
            });
            box.appendChild(button);
        });
        box.style.display = 'block';
    }

    function fetchLookup(input) {
        const query = input.value.trim();
        if (query.length < 2) {
            clearResults(input);
            return;
        }
        const params = new URLSearchParams({
            type: input.dataset.lookupType || 'items',
            kind: input.dataset.lookupKind || 'any',
            q: query,
            limit: '12',
            pos_tenant: String(scope.pos_tenant),
            pos_branch: String(scope.pos_branch),
            exclude_recipe_id: String(scope.exclude_recipe_id)
        });
        fetch('ajax/recipe_editor_lookup.php?' + params.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) { return response.ok ? response.json() : { items: [] }; })
            .then(function (payload) { renderResults(input, Array.isArray(payload.items) ? payload.items : []); })
            .catch(function () { clearResults(input); });
    }

    document.querySelectorAll('.recipe-lookup-input').forEach(function (input) {
        input.addEventListener('input', function () {
            clearTimeout(timers.get(input));
            timers.set(input, setTimeout(function () { fetchLookup(input); }, 220));
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { clearResults(input); }, 200);
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_manage_can_edit(mysqli $conn): bool
{
    return auth_guard_has_permission('menu.edit', $conn)
        || auth_guard_has_permission('inventory.edit', $conn);
}

function posmain_recipe_manage_can_approve(mysqli $conn): bool
{
    return auth_guard_is_admin_session($_SESSION, auth_guard_current_role_flags($conn));
}

function posmain_recipe_manage_can_view_cost(mysqli $conn): bool
{
    return posmain_recipe_can_view_costs($conn);
}

function posmain_recipe_manage_actor(mysqli $conn): RecipeActorContext
{
    $scope = (new RecipeScopeResolver())->resolve($_POST);
    $roleFlags = auth_guard_current_role_flags($conn);
    $isAdmin = auth_guard_is_admin_session($_SESSION, $roleFlags);
    $permissions = [];

    if ($isAdmin) {
        $permissions = ['admin', 'recipe.manage', 'recipe.approve', 'inventory.manage', 'inventory.approve', 'menu.manage'];
    } else {
        if (auth_guard_has_permission('menu.edit', $conn)) {
            $permissions[] = 'recipe.manage';
            $permissions[] = 'menu.manage';
        }
        if (posmain_recipe_can_manage_stock_operations($conn)) {
            $permissions[] = 'recipe.manage';
            $permissions[] = 'inventory.manage';
        }
    }

    return new RecipeActorContext(
        current_user_id(),
        $scope->posTenant,
        $scope->posBranch,
        $scope->branchUuid,
        $permissions,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    );
}

function posmain_recipe_manage_preview_context(array $request, array $recipe): array
{
    return [
        'pos_tenant' => (int) ($recipe['pos_tenant'] ?? 0),
        'pos_branch' => (int) ($recipe['pos_branch'] ?? 0),
        'branch_uuid' => $recipe['branch_uuid'] ?? null,
        'store_id' => max(0, (int) ($request['store_id'] ?? 0)),
        'order_type' => (string) ($request['order_type'] ?? 'takeaway'),
        'channel' => (string) ($request['channel'] ?? 'pos'),
        'safety_stock' => (string) ($request['safety_stock'] ?? '0'),
        'costing_method' => (string) ($request['costing_method'] ?? ($recipe['costing_method'] ?? 'item_cost_price')),
    ];
}

function posmain_recipe_manage_action_button(string $action, int $recipeId, string $label, string $class, bool $enabled): string
{
    return '<form method="POST" class="d-inline">'
        . csrf_input('recipe_editor')
        . '<input type="hidden" name="action" value="' . posmain_recipe_manage_h($action) . '">'
        . '<input type="hidden" name="recipe_id" value="' . $recipeId . '">'
        . '<button type="submit" class="btn ' . posmain_recipe_manage_h($class) . '" ' . ($enabled ? '' : 'disabled') . '>'
        . posmain_recipe_manage_h($label)
        . '</button></form>';
}

function posmain_recipe_manage_lookup_input(string $type, string $kind, string $targetInput, string $placeholder, array $extra = []): string
{
    $attrs = [
        'type' => 'search',
        'class' => 'form-control recipe-lookup-input',
        'data-lookup-type' => $type,
        'data-lookup-kind' => $kind,
        'data-target-input' => $targetInput,
        'placeholder' => $placeholder,
        'autocomplete' => 'off',
    ];
    if (!empty($extra['target_group_input'])) {
        $attrs['data-target-group-input'] = (string) $extra['target_group_input'];
    }

    $htmlAttrs = '';
    foreach ($attrs as $name => $value) {
        $htmlAttrs .= ' ' . $name . '="' . posmain_recipe_manage_h($value) . '"';
    }

    return '<div class="recipe-lookup-wrapper position-relative">'
        . '<input' . $htmlAttrs . '>'
        . '<div class="recipe-lookup-results list-group position-absolute w-100 shadow-sm" style="z-index: 1050; display: none;"></div>'
        . '</div>';
}

function posmain_recipe_manage_recipe_types(): array
{
    return [
        'make_to_order' => 'Make to order',
        'batch_prepared' => 'Batch prepared',
        'hybrid' => 'Hybrid',
        'packaging_bundle' => 'Packaging bundle',
        'modifier_only' => 'Modifier only',
        'sub_recipe' => 'Sub-recipe',
    ];
}

function posmain_recipe_manage_line_types(): array
{
    return [
        'ingredient' => 'Ingredient',
        'packaging' => 'Packaging',
        'modifier_ingredient' => 'Modifier ingredient',
        'sub_recipe' => 'Sub-recipe',
        'labor_placeholder' => 'Labor placeholder',
    ];
}

function posmain_recipe_manage_modifier_behaviors(): array
{
    return [
        'additive' => 'Additive',
        'substitution_remove' => 'Substitution remove',
        'substitution_add' => 'Substitution add',
    ];
}

function posmain_recipe_manage_order_types(): array
{
    return [
        'any' => 'Any',
        'dine_in' => 'Dine in',
        'takeaway' => 'Takeaway',
        'delivery' => 'Delivery',
    ];
}

function posmain_recipe_manage_channels(): array
{
    return [
        'any' => 'Any',
        'pos' => 'POS',
        'table' => 'Table',
        'moova' => 'Moova',
        'cofe' => 'Cofe',
        'api' => 'API',
    ];
}

function posmain_recipe_manage_costing_methods(): array
{
    return [
        'item_cost_price' => 'Item cost price',
        'moving_average' => 'Moving average',
        'last_purchase' => 'Last purchase',
        'manual_snapshot' => 'Manual snapshot',
    ];
}

function posmain_recipe_manage_options(array $options, $selected): string
{
    $selected = (string) $selected;
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . posmain_recipe_manage_h($value) . '" '
            . ((string) $value === $selected ? 'selected' : '')
            . '>' . posmain_recipe_manage_h($label) . '</option>';
    }

    return $html;
}

function posmain_recipe_manage_take_flash(): ?array
{
    $flash = $_SESSION['recipe_manage_flash'] ?? null;
    unset($_SESSION['recipe_manage_flash']);

    return is_array($flash) ? $flash : null;
}

function posmain_recipe_manage_set_flash(string $type, string $message): void
{
    $_SESSION['recipe_manage_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function posmain_recipe_manage_redirect(int $recipeId = 0): void
{
    $url = 'recipe_manage.php';
    if ($recipeId > 0) {
        $url .= '?recipe_id=' . $recipeId;
    }

    header('Location: ' . $url);
    exit;
}

function posmain_recipe_manage_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function posmain_recipe_manage_qty($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
}
