<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorReadService.php';

require_login();
if (!posmain_recipe_editor_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeEditorService = new RecipeEditorReadService();
$recipeEditorFilters = posmain_recipe_editor_filters($_GET);
$recipeEditorRows = $recipeEditorService->listRecipes($conn, $recipeEditorFilters);
$selectedRecipeId = isset($_GET['recipe_id']) && (int) $_GET['recipe_id'] > 0 ? (int) $_GET['recipe_id'] : 0;
$selectedRecipe = $selectedRecipeId > 0 ? $recipeEditorService->recipeDetail($conn, $selectedRecipeId) : null;
$recipeEditorCanViewCost = posmain_recipe_editor_can_view_cost($conn);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h3 class="card-title">Recipe Editor - Read Only</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-3">
                            <label>Search</label>
                            <input type="text" name="q" class="form-control" value="<?= posmain_recipe_editor_h($recipeEditorFilters['q'] ?? '') ?>" placeholder="Recipe, item, barcode">
                        </div>
                        <div class="col-md-2">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <?php foreach (['' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'] as $value => $label): ?>
                                    <option value="<?= posmain_recipe_editor_h($value) ?>" <?= (($recipeEditorFilters['status'] ?? '') === $value) ? 'selected' : '' ?>>
                                        <?= posmain_recipe_editor_h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" min="0" value="<?= (int) ($recipeEditorFilters['pos_tenant'] ?? 0) ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" min="0" value="<?= (int) ($recipeEditorFilters['pos_branch'] ?? 0) ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Item ID</label>
                            <input type="number" name="sellable_item_id" class="form-control" min="1" value="<?= isset($_GET['sellable_item_id']) ? posmain_recipe_editor_h($_GET['sellable_item_id']) : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" min="1" max="500" value="<?= (int) ($recipeEditorFilters['limit'] ?? 100) ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Load Recipes</button>
                        </div>
                    </form>

                    <div class="alert alert-info">
                        This screen is read-only. Recipe creation, approval, activation, stock consumption, accounting, and availability writes still go through recipe services and feature flags.
                        <?php if (!$recipeEditorCanViewCost): ?>
                            Cost snapshots are hidden for this session.
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="col-lg-7">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>ID</th>
                                            <th>Recipe</th>
                                            <th>Item</th>
                                            <th>Status</th>
                                            <th>Version</th>
                                            <th>Lines</th>
                                            <?php if ($recipeEditorCanViewCost): ?>
                                                <th>Cost</th>
                                            <?php endif; ?>
                                            <th>Availability</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recipeEditorRows as $row): ?>
                                            <tr class="<?= (int) ($row['id'] ?? 0) === $selectedRecipeId ? 'table-primary' : '' ?>">
                                                <td><?= (int) $row['id'] ?></td>
                                                <td>
                                                    <div class="fw-bold"><?= posmain_recipe_editor_h($row['recipe_name'] ?? '') ?></div>
                                                    <small class="text-muted"><?= posmain_recipe_editor_h($row['recipe_type'] ?? '') ?></small>
                                                </td>
                                                <td>
                                                    <div><?= posmain_recipe_editor_h($row['sellable_item_name'] ?? ('Item ' . (int) $row['sellable_item_id'])) ?></div>
                                                    <small class="text-muted"><?= posmain_recipe_editor_h($row['sellable_item_barcode'] ?? '') ?></small>
                                                </td>
                                                <td><?= posmain_recipe_editor_status_badge($row['status'] ?? '') ?></td>
                                                <td class="text-center"><?= (int) ($row['version_number'] ?? 0) ?></td>
                                                <td class="text-center"><?= (int) ($row['line_count'] ?? 0) ?></td>
                                                <?php if ($recipeEditorCanViewCost): ?>
                                                    <td class="text-end"><?= posmain_recipe_editor_money($row['latest_cost_per_sell_unit'] ?? null) ?></td>
                                                <?php endif; ?>
                                                <td>
                                                    <?= posmain_recipe_editor_availability_badge($row) ?>
                                                    <small class="d-block text-muted">rev <?= posmain_recipe_editor_h($row['cached_availability_revision'] ?? '') ?></small>
                                                </td>
                                                <td>
                                                    <a class="btn btn-sm btn-outline-primary" href="<?= posmain_recipe_editor_detail_url($recipeEditorFilters, (int) $row['id']) ?>">View</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (!$recipeEditorRows): ?>
                                <div class="alert alert-secondary">No recipes matched the selected filters.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-5">
                            <?php if ($selectedRecipe): ?>
                                <?php $header = $selectedRecipe['header']; ?>
                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-secondary text-white">Selected Recipe</div>
                                    <div class="card-body">
                                        <h5><?= posmain_recipe_editor_h($header['recipe_name'] ?? '') ?></h5>
                                        <div class="row">
                                            <div class="col-6"><strong>Item:</strong> <?= posmain_recipe_editor_h($header['sellable_item_name'] ?? '') ?></div>
                                            <div class="col-6"><strong>Type:</strong> <?= posmain_recipe_editor_h($header['recipe_type'] ?? '') ?></div>
                                            <div class="col-6"><strong>Status:</strong> <?= posmain_recipe_editor_h($header['status'] ?? '') ?></div>
                                            <div class="col-6"><strong>Version:</strong> <?= (int) ($header['version_number'] ?? 0) ?></div>
                                            <div class="col-6"><strong>Yield:</strong> <?= posmain_recipe_editor_qty($header['yield_qty'] ?? null) ?></div>
                                            <div class="col-6"><strong>Wastage:</strong> <?= posmain_recipe_editor_qty($header['default_wastage_percent'] ?? null) ?>%</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-light">Lines</div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Type</th>
                                                        <th>Ingredient / Sub-recipe</th>
                                                        <th class="text-end">Qty/Yield</th>
                                                        <th>Scope</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($selectedRecipe['lines'] as $line): ?>
                                                        <tr>
                                                            <td><?= posmain_recipe_editor_h($line['line_type'] ?? '') ?></td>
                                                            <td>
                                                                <?= posmain_recipe_editor_h($line['ingredient_item_name'] ?? $line['sub_recipe_name'] ?? 'Unlinked') ?>
                                                                <?php if (!empty($line['modifier_option_id'])): ?>
                                                                    <small class="d-block text-muted">Modifier option #<?= (int) $line['modifier_option_id'] ?></small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end"><?= posmain_recipe_editor_qty($line['qty_per_yield'] ?? null) ?></td>
                                                            <td><?= posmain_recipe_editor_h(($line['order_type'] ?? 'any') . ' / ' . ($line['channel'] ?? 'any')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-light"><?= $recipeEditorCanViewCost ? 'Cost And Availability Snapshot' : 'Availability Snapshot' ?></div>
                                    <div class="card-body">
                                        <?php if ($recipeEditorCanViewCost): ?>
                                            <?php $cost = $selectedRecipe['latest_cost']; ?>
                                            <div><strong>Cost per yield:</strong> <?= $cost ? posmain_recipe_editor_money($cost['cost_per_yield'] ?? null) : 'No snapshot' ?></div>
                                            <div><strong>Cost per sell unit:</strong> <?= $cost ? posmain_recipe_editor_money($cost['cost_per_sell_unit'] ?? null) : 'No snapshot' ?></div>
                                            <hr>
                                        <?php endif; ?>
                                        <?php foreach ($selectedRecipe['availability'] as $availability): ?>
                                            <div class="mb-2">
                                                <?= posmain_recipe_editor_availability_badge($availability) ?>
                                                <span class="text-muted">
                                                    <?= posmain_recipe_editor_h(($availability['order_type'] ?? 'any') . ' / ' . ($availability['channel'] ?? 'any')) ?>,
                                                    qty <?= posmain_recipe_editor_qty($availability['effective_available_qty'] ?? null) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!$selectedRecipe['availability']): ?>
                                            <div class="text-muted">No cached availability rows yet.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card border-secondary">
                                    <div class="card-header bg-light">Recent Audit</div>
                                    <div class="card-body p-0">
                                        <table class="table table-sm mb-0">
                                            <tbody>
                                                <?php foreach ($selectedRecipe['audit'] as $audit): ?>
                                                    <tr>
                                                        <td><?= posmain_recipe_editor_h($audit['created_at'] ?? '') ?></td>
                                                        <td><?= posmain_recipe_editor_h($audit['action'] ?? '') ?></td>
                                                        <td class="text-end">user <?= (int) ($audit['actor_user_id'] ?? 0) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php if (!$selectedRecipe['audit']): ?>
                                            <div class="p-3 text-muted">No audit rows for this recipe.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($selectedRecipeId > 0): ?>
                                <div class="alert alert-warning">Selected recipe was not found.</div>
                            <?php else: ?>
                                <div class="alert alert-secondary">Select a recipe to inspect its version, lines, cached availability, and recent audit rows.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_editor_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('menu.edit', $conn)
        || auth_guard_has_permission('inventory.edit', $conn)
        || auth_guard_has_permission('accounting.view', $conn);
}

function posmain_recipe_editor_can_view_cost(mysqli $conn): bool
{
    return posmain_recipe_can_view_costs($conn);
}

function posmain_recipe_editor_filters(array $request): array
{
    $status = strtolower(trim((string) ($request['status'] ?? '')));

    return [
        'q' => trim((string) ($request['q'] ?? '')),
        'status' => in_array($status, ['draft', 'active', 'archived'], true) ? $status : '',
        'pos_tenant' => isset($request['pos_tenant']) ? max(0, (int) $request['pos_tenant']) : 0,
        'pos_branch' => isset($request['pos_branch']) ? max(0, (int) $request['pos_branch']) : 0,
        'sellable_item_id' => isset($request['sellable_item_id']) && (int) $request['sellable_item_id'] > 0 ? (int) $request['sellable_item_id'] : -1,
        'limit' => max(1, min(500, (int) ($request['limit'] ?? 100))),
    ];
}

function posmain_recipe_editor_detail_url(array $filters, int $recipeId): string
{
    $query = $filters;
    $query['recipe_id'] = $recipeId;
    if (($query['sellable_item_id'] ?? -1) < 1) {
        unset($query['sellable_item_id']);
    }

    return 'recipe_editor.php?' . http_build_query($query);
}

function posmain_recipe_editor_status_badge($status): string
{
    $status = strtolower(trim((string) $status));
    $class = $status === 'active' ? 'success' : ($status === 'draft' ? 'warning text-dark' : 'secondary');

    return '<span class="badge bg-' . $class . '">' . posmain_recipe_editor_h($status !== '' ? $status : 'unknown') . '</span>';
}

function posmain_recipe_editor_availability_badge(array $row): string
{
    if (!array_key_exists('effective_is_available', $row) && !array_key_exists('cached_effective_is_available', $row)) {
        return '<span class="badge bg-secondary">not cached</span>';
    }

    $available = (int) ($row['effective_is_available'] ?? $row['cached_effective_is_available'] ?? 0) === 1;
    if ($available) {
        return '<span class="badge bg-success">available</span>';
    }

    $reason = (string) ($row['unavailable_reason'] ?? $row['cached_unavailable_reason'] ?? 'unavailable');
    return '<span class="badge bg-danger" title="' . posmain_recipe_editor_h($reason) . '">unavailable</span>';
}

function posmain_recipe_editor_money($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float) $value, 2, '.', '');
}

function posmain_recipe_editor_qty($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
}

function posmain_recipe_editor_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
