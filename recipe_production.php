<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/classes/Recipe/ProductionBatchMutationService.php';
require_once __DIR__ . '/classes/Recipe/ProductionBatchReadService.php';
require_once __DIR__ . '/classes/Recipe/ProductionBatchService.php';
require_once __DIR__ . '/classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/classes/Recipe/RecipeScopeResolver.php';

require_login();
if (!posmain_recipe_production_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeProductionFlags = new RecipeFeatureFlags();
$recipeProductionMode = $recipeProductionFlags->mode();
$recipeProductionWritesEnabled = $recipeProductionFlags->isEnabled() && !in_array($recipeProductionMode, ['schema_only', 'read_only'], true);
$recipeProductionReadService = new ProductionBatchReadService();
$recipeProductionFlash = posmain_recipe_production_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('recipe_production');

    try {
        $result = (new ProductionBatchMutationService())->handle(
            $conn,
            (string) ($_POST['action'] ?? ''),
            $_POST,
            posmain_recipe_production_actor($conn)
        );
        posmain_recipe_production_set_flash('success', $result['message'] ?? 'Production batch updated.');
        posmain_recipe_production_redirect((int) ($result['batch_id'] ?? 0));
    } catch (Throwable $exception) {
        posmain_recipe_production_set_flash('danger', $exception->getMessage());
        posmain_recipe_production_redirect((int) ($_POST['batch_id'] ?? 0));
    }
}

$recipeProductionFilters = posmain_recipe_production_filters($_GET);
$recipeProductionRows = $recipeProductionReadService->listBatches($conn, $recipeProductionFilters);
$recipeProductionRecipes = $recipeProductionReadService->activeProductionRecipes($conn, [
    'pos_tenant' => $recipeProductionFilters['pos_tenant'],
    'pos_branch' => $recipeProductionFilters['pos_branch'],
    'limit' => 300,
]);
$selectedBatchId = isset($_GET['batch_id']) && (int) $_GET['batch_id'] > 0 ? (int) $_GET['batch_id'] : 0;
$selectedBatch = $selectedBatchId > 0 ? $recipeProductionReadService->batchDetail($conn, $selectedBatchId) : null;
$selectedBatchHeader = $selectedBatch['batch'] ?? null;
$selectedIsDraft = $selectedBatchHeader && ($selectedBatchHeader['status'] ?? '') === 'draft';
$canManageProduction = posmain_recipe_production_can_manage($conn);
$canCommitProduction = posmain_recipe_production_can_commit($conn);
$canViewProductionCost = posmain_recipe_production_can_view_cost($conn);
$productionPreview = null;
$productionPreviewError = null;
if ($selectedBatchHeader && $selectedIsDraft) {
    try {
        $productionPreview = (new ProductionBatchService())->preview($conn, (int) $selectedBatchHeader['id']);
    } catch (Throwable $exception) {
        $productionPreviewError = $exception->getMessage();
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
                    <h3 class="card-title">Recipe Production Batches</h3>
                </div>
                <div class="card-body">
                    <?php if ($recipeProductionFlash): ?>
                        <div class="alert alert-<?= posmain_recipe_production_h($recipeProductionFlash['type']) ?>">
                            <?= posmain_recipe_production_h($recipeProductionFlash['message']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$recipeProductionWritesEnabled): ?>
                        <div class="alert alert-warning">
                            Production batch writes are disabled by the current recipe feature flags. Mode: <?= posmain_recipe_production_h($recipeProductionMode) ?>.
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-lg-4">
                            <div class="card border-secondary mb-3">
                                <div class="card-header bg-light">Create Draft Batch</div>
                                <div class="card-body">
                                    <form method="POST">
                                        <?= csrf_input('recipe_production') ?>
                                        <input type="hidden" name="action" value="create_draft">
                                        <div class="mb-2">
                                            <label>Recipe</label>
                                            <select name="recipe_id" class="form-control" required>
                                                <option value="">Select active production recipe</option>
                                                <?php foreach ($recipeProductionRecipes as $recipe): ?>
                                                    <option value="<?= (int) $recipe['id'] ?>">
                                                        <?= posmain_recipe_production_h(($recipe['recipe_name'] ?? '') . ' v' . (int) ($recipe['version_number'] ?? 0) . ' - item ' . (int) ($recipe['sellable_item_id'] ?? 0)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <label>Planned Output</label>
                                                <input type="text" name="planned_output_qty" class="form-control" value="1.000000" required>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <label>Store</label>
                                                <input type="number" name="store_id" class="form-control" min="0" value="0">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label>Notes</label>
                                            <input type="text" name="notes" class="form-control" maxlength="500">
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100" <?= ($recipeProductionWritesEnabled && $canManageProduction) ? '' : 'disabled' ?>>Create Draft</button>
                                    </form>
                                </div>
                            </div>

                            <div class="card border-secondary">
                                <div class="card-header bg-light">Batches</div>
                                <div class="card-body">
                                    <form method="GET" class="mb-3">
                                        <div class="mb-2">
                                            <input type="text" name="q" class="form-control" value="<?= posmain_recipe_production_h($recipeProductionFilters['q']) ?>" placeholder="Search">
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-2">
                                                <select name="status" class="form-control">
                                                    <?= posmain_recipe_production_options(['' => 'All', 'draft' => 'Draft', 'committed' => 'Committed', 'cancelled' => 'Cancelled'], $recipeProductionFilters['status']) ?>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-2">
                                                <input type="number" name="store_id" class="form-control" min="0" value="<?= $recipeProductionFilters['store_id'] >= 0 ? (int) $recipeProductionFilters['store_id'] : '' ?>" placeholder="Store">
                                            </div>
                                        </div>
                                        <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
                                    </form>
                                    <div class="list-group">
                                        <?php foreach ($recipeProductionRows as $row): ?>
                                            <a class="list-group-item list-group-item-action <?= (int) $row['id'] === $selectedBatchId ? 'active' : '' ?>" href="<?= posmain_recipe_production_batch_url($recipeProductionFilters, (int) $row['id']) ?>">
                                                <div class="d-flex justify-content-between">
                                                    <span><?= posmain_recipe_production_h($row['recipe_name'] ?? ('Recipe ' . (int) ($row['recipe_id'] ?? 0))) ?></span>
                                                    <small>#<?= (int) $row['id'] ?></small>
                                                </div>
                                                <small><?= posmain_recipe_production_h(($row['status'] ?? '') . ' - planned ' . posmain_recipe_production_qty($row['planned_output_qty'] ?? '')) ?></small>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <?php if ($selectedBatch): ?>
                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-secondary text-white">
                                        Batch #<?= (int) $selectedBatchHeader['id'] ?>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-md-3"><strong>Status:</strong> <?= posmain_recipe_production_status_badge($selectedBatchHeader['status'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Recipe:</strong> <?= posmain_recipe_production_h($selectedBatchHeader['recipe_name'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Output:</strong> <?= posmain_recipe_production_h($selectedBatchHeader['output_item_name'] ?? ('Item ' . (int) ($selectedBatchHeader['output_item_id'] ?? 0))) ?></div>
                                            <div class="col-md-3"><strong>Store:</strong> <?= (int) ($selectedBatchHeader['store_id'] ?? 0) ?></div>
                                            <div class="col-md-3"><strong>Planned:</strong> <?= posmain_recipe_production_qty($selectedBatchHeader['planned_output_qty'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Actual:</strong> <?= posmain_recipe_production_qty($selectedBatchHeader['actual_output_qty'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Committed:</strong> <?= posmain_recipe_production_h($selectedBatchHeader['committed_at'] ?? '') ?></div>
                                            <div class="col-md-3"><strong>Variance:</strong> <?= posmain_recipe_production_h($selectedBatchHeader['variance_reason'] ?? '') ?></div>
                                        </div>

                                        <?php if ($selectedIsDraft): ?>
                                            <div class="row">
                                                <div class="col-md-7 mb-3">
                                                    <form method="POST" class="border rounded p-3 bg-light">
                                                        <?= csrf_input('recipe_production') ?>
                                                        <input type="hidden" name="action" value="commit">
                                                        <input type="hidden" name="batch_id" value="<?= (int) $selectedBatchHeader['id'] ?>">
                                                        <div class="row">
                                                            <div class="col-md-6 mb-2">
                                                                <label>Actual Output</label>
                                                                <input type="text" name="actual_output_qty" class="form-control" value="<?= posmain_recipe_production_h($selectedBatchHeader['planned_output_qty'] ?? '1.000000') ?>" required>
                                                            </div>
                                                            <div class="col-md-6 mb-2">
                                                                <label>Variance Reason</label>
                                                                <input type="text" name="variance_reason" class="form-control" maxlength="255">
                                                            </div>
                                                        </div>
                                                        <button type="submit" class="btn btn-success" <?= ($recipeProductionWritesEnabled && $canCommitProduction) ? '' : 'disabled' ?>>Commit Batch</button>
                                                    </form>
                                                </div>
                                                <div class="col-md-5 mb-3">
                                                    <form method="POST" class="border rounded p-3">
                                                        <?= csrf_input('recipe_production') ?>
                                                        <input type="hidden" name="action" value="cancel">
                                                        <input type="hidden" name="batch_id" value="<?= (int) $selectedBatchHeader['id'] ?>">
                                                        <div class="mb-2">
                                                            <label>Cancel Reason</label>
                                                            <input type="text" name="cancel_reason" class="form-control" maxlength="255">
                                                        </div>
                                                        <button type="submit" class="btn btn-outline-danger" <?= ($recipeProductionWritesEnabled && $canManageProduction) ? '' : 'disabled' ?>>Cancel Draft</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($productionPreview): ?>
                                    <div class="card border-secondary mb-3">
                                        <div class="card-header bg-light">Input Preview</div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Input Item</th>
                                                            <th class="text-end">Required Qty</th>
                                                            <?php if ($canViewProductionCost): ?>
                                                                <th class="text-end">Unit Cost</th>
                                                                <th class="text-end">Total Cost</th>
                                                            <?php endif; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($productionPreview['requirements'] as $requirement): ?>
                                                            <tr>
                                                                <td>Item <?= (int) ($requirement['ingredient_item_id'] ?? 0) ?></td>
                                                                <td class="text-end"><?= posmain_recipe_production_qty($requirement['required_qty_base'] ?? '') ?></td>
                                                                <?php if ($canViewProductionCost): ?>
                                                                    <td class="text-end"><?= posmain_recipe_production_money($requirement['unit_cost'] ?? '') ?></td>
                                                                    <td class="text-end"><?= posmain_recipe_production_money($requirement['total_cost'] ?? '') ?></td>
                                                                <?php endif; ?>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <?php if ($canViewProductionCost): ?>
                                                        <tfoot>
                                                            <tr>
                                                                <th colspan="3" class="text-end">Total</th>
                                                                <th class="text-end"><?= posmain_recipe_production_money($productionPreview['total_input_cost'] ?? '') ?></th>
                                                            </tr>
                                                        </tfoot>
                                                    <?php endif; ?>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($productionPreviewError): ?>
                                    <div class="alert alert-warning"><?= posmain_recipe_production_h($productionPreviewError) ?></div>
                                <?php endif; ?>

                                <div class="card border-secondary">
                                    <div class="card-header bg-light">Committed Lines</div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Type</th>
                                                        <th>Item</th>
                                                        <th class="text-end">Planned</th>
                                                        <th class="text-end">Actual</th>
                                                        <?php if ($canViewProductionCost): ?>
                                                            <th class="text-end">Cost</th>
                                                        <?php endif; ?>
                                                        <th>Movement</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($selectedBatch['lines'] as $line): ?>
                                                        <tr>
                                                            <td><?= posmain_recipe_production_h($line['line_type'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_production_h($line['item_name'] ?? ('Item ' . (int) ($line['item_id'] ?? 0))) ?></td>
                                                            <td class="text-end"><?= posmain_recipe_production_qty($line['planned_qty'] ?? '') ?></td>
                                                            <td class="text-end"><?= posmain_recipe_production_qty($line['actual_qty'] ?? '') ?></td>
                                                            <?php if ($canViewProductionCost): ?>
                                                                <td class="text-end"><?= posmain_recipe_production_money($line['total_cost'] ?? '') ?></td>
                                                            <?php endif; ?>
                                                            <td><?= posmain_recipe_production_h($line['inventory_movement_id'] ?? '') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php if (!$selectedBatch['lines']): ?>
                                            <div class="p-3 text-muted">No committed batch lines yet.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary">Select a production batch to preview or commit it.</div>
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
function posmain_recipe_production_can_view(mysqli $conn): bool
{
    return posmain_recipe_can_manage_stock_operations($conn)
        || auth_guard_has_permission('menu.edit', $conn)
        || auth_guard_has_permission('accounting.view', $conn);
}

function posmain_recipe_production_can_manage(mysqli $conn): bool
{
    return posmain_recipe_can_manage_stock_operations($conn)
        || auth_guard_has_permission('menu.edit', $conn);
}

function posmain_recipe_production_can_commit(mysqli $conn): bool
{
    return auth_guard_is_admin_session($_SESSION, auth_guard_current_role_flags($conn));
}

function posmain_recipe_production_can_view_cost(mysqli $conn): bool
{
    return posmain_recipe_can_view_costs($conn);
}

function posmain_recipe_production_actor(mysqli $conn): RecipeActorContext
{
    $scope = (new RecipeScopeResolver())->resolve($_POST);
    $roleFlags = auth_guard_current_role_flags($conn);
    $isAdmin = auth_guard_is_admin_session($_SESSION, $roleFlags);
    $permissions = [];

    if ($isAdmin) {
        $permissions = ['admin', 'recipe.manage', 'recipe.approve', 'inventory.manage', 'inventory.approve'];
    } else {
        if (posmain_recipe_can_manage_stock_operations($conn)) {
            $permissions[] = 'recipe.manage';
            $permissions[] = 'inventory.manage';
        }
        if (auth_guard_has_permission('menu.edit', $conn)) {
            $permissions[] = 'recipe.manage';
            $permissions[] = 'menu.manage';
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

function posmain_recipe_production_filters(array $request): array
{
    $status = strtolower(trim((string) ($request['status'] ?? '')));

    return [
        'q' => trim((string) ($request['q'] ?? '')),
        'status' => in_array($status, ['draft', 'committed', 'cancelled'], true) ? $status : '',
        'pos_tenant' => isset($request['pos_tenant']) && $request['pos_tenant'] !== '' ? max(0, (int) $request['pos_tenant']) : -1,
        'pos_branch' => isset($request['pos_branch']) && $request['pos_branch'] !== '' ? max(0, (int) $request['pos_branch']) : -1,
        'store_id' => isset($request['store_id']) && $request['store_id'] !== '' ? max(0, (int) $request['store_id']) : -1,
        'recipe_id' => isset($request['recipe_id']) && (int) $request['recipe_id'] > 0 ? (int) $request['recipe_id'] : -1,
        'limit' => max(1, min(500, (int) ($request['limit'] ?? 100))),
    ];
}

function posmain_recipe_production_batch_url(array $filters, int $batchId): string
{
    $query = $filters;
    $query['batch_id'] = $batchId;
    foreach (['pos_tenant', 'pos_branch', 'store_id', 'recipe_id'] as $key) {
        if (($query[$key] ?? -1) < 0) {
            unset($query[$key]);
        }
    }

    return 'recipe_production.php?' . http_build_query($query);
}

function posmain_recipe_production_options(array $options, $selected): string
{
    $selected = (string) $selected;
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . posmain_recipe_production_h($value) . '" '
            . ((string) $value === $selected ? 'selected' : '')
            . '>' . posmain_recipe_production_h($label) . '</option>';
    }

    return $html;
}

function posmain_recipe_production_status_badge($status): string
{
    $status = strtolower(trim((string) $status));
    $class = $status === 'committed' ? 'success' : ($status === 'draft' ? 'warning text-dark' : 'secondary');

    return '<span class="badge bg-' . $class . '">' . posmain_recipe_production_h($status !== '' ? $status : 'unknown') . '</span>';
}

function posmain_recipe_production_take_flash(): ?array
{
    $flash = $_SESSION['recipe_production_flash'] ?? null;
    unset($_SESSION['recipe_production_flash']);

    return is_array($flash) ? $flash : null;
}

function posmain_recipe_production_set_flash(string $type, string $message): void
{
    $_SESSION['recipe_production_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function posmain_recipe_production_redirect(int $batchId = 0): void
{
    $url = 'recipe_production.php';
    if ($batchId > 0) {
        $url .= '?batch_id=' . $batchId;
    }

    header('Location: ' . $url);
    exit;
}

function posmain_recipe_production_qty($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
}

function posmain_recipe_production_money($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return number_format((float) $value, 2, '.', '');
}

function posmain_recipe_production_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
