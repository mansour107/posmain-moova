<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/classes/Recipe/RecipeScopeResolver.php';
require_once __DIR__ . '/classes/Recipe/RecipeWasteAdjustmentReadService.php';
require_once __DIR__ . '/classes/Recipe/RecipeWasteAdjustmentService.php';

require_login();
if (!posmain_recipe_waste_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeWasteFlags = new RecipeFeatureFlags();
$recipeWasteMode = $recipeWasteFlags->mode();
$recipeWasteWritesEnabled = $recipeWasteFlags->isEnabled() && !in_array($recipeWasteMode, ['schema_only', 'read_only'], true);
$recipeWasteFlash = posmain_recipe_waste_take_flash();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf('recipe_waste_adjustment');

    try {
        $result = (new RecipeWasteAdjustmentService())->handle(
            $conn,
            (string) ($_POST['action'] ?? ''),
            $_POST,
            posmain_recipe_waste_actor($conn)
        );
        posmain_recipe_waste_set_flash('success', $result['message'] ?? 'Recipe stock operation recorded.');
        posmain_recipe_waste_redirect();
    } catch (Throwable $exception) {
        posmain_recipe_waste_set_flash('danger', $exception->getMessage());
        posmain_recipe_waste_redirect();
    }
}

$recipeWasteFilters = posmain_recipe_waste_filters($_GET);
$recipeWasteReadService = new RecipeWasteAdjustmentReadService();
$recipeWasteRows = $recipeWasteReadService->recentMovements($conn, $recipeWasteFilters);
$recipeWasteCanRecord = posmain_recipe_waste_can_record($conn);
$recipeWasteCanApproveBackdated = posmain_recipe_waste_can_approve_backdated($conn);
$recipeWasteCanViewCost = posmain_recipe_waste_can_view_cost($conn);
$recipeWasteUuid = posmain_recipe_waste_uuid();
$recipeAdjustmentUuid = posmain_recipe_waste_uuid();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h3 class="card-title">Recipe Waste and Stock Adjustments</h3>
                </div>
                <div class="card-body">
                    <?php if ($recipeWasteFlash): ?>
                        <div class="alert alert-<?= posmain_recipe_waste_h($recipeWasteFlash['type']) ?>">
                            <?= posmain_recipe_waste_h($recipeWasteFlash['message']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$recipeWasteWritesEnabled): ?>
                        <div class="alert alert-warning">
                            Recipe waste and stock adjustment writes are disabled by the current feature flags. Mode: <?= posmain_recipe_waste_h($recipeWasteMode) ?>.
                        </div>
                    <?php endif; ?>

                    <div class="row mb-4">
                        <div class="col-lg-6 mb-3">
                            <form method="POST" class="border rounded p-3 h-100">
                                <?= csrf_input('recipe_waste_adjustment') ?>
                                <input type="hidden" name="action" value="record_waste">
                                <input type="hidden" name="waste_uuid" value="<?= posmain_recipe_waste_h($recipeWasteUuid) ?>">
                                <h5>Record Waste</h5>
                                <div class="mb-2">
                                    <label>Item</label>
                                    <?= posmain_recipe_waste_lookup_input('waste_item_lookup', 'waste_item_id', 'Search ingredient, packaging, or prepared item') ?>
                                    <input type="number" name="item_id" id="waste_item_id" class="form-control mt-2" min="1" placeholder="Item ID" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label>Quantity</label>
                                        <input type="text" name="qty" class="form-control" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Store</label>
                                        <input type="number" name="store_id" class="form-control" min="0" value="0">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Unit ID</label>
                                        <input type="number" name="unit_id" class="form-control" min="0">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label>Unit Cost</label>
                                        <input type="text" name="unit_cost" class="form-control" value="0.000000" <?= $recipeWasteCanViewCost ? '' : 'readonly' ?>>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label>Operation Date</label>
                                        <input type="date" name="occurred_at" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Reason</label>
                                    <input type="text" name="reason" class="form-control" maxlength="255" required>
                                </div>
                                <button type="submit" class="btn btn-danger" <?= ($recipeWasteWritesEnabled && $recipeWasteCanRecord) ? '' : 'disabled' ?>>Record Waste</button>
                            </form>
                        </div>

                        <div class="col-lg-6 mb-3">
                            <form method="POST" class="border rounded p-3 h-100">
                                <?= csrf_input('recipe_waste_adjustment') ?>
                                <input type="hidden" name="action" value="record_adjustment">
                                <input type="hidden" name="adjustment_uuid" value="<?= posmain_recipe_waste_h($recipeAdjustmentUuid) ?>">
                                <h5>Record Stock Adjustment</h5>
                                <div class="mb-2">
                                    <label>Item</label>
                                    <?= posmain_recipe_waste_lookup_input('adjustment_item_lookup', 'adjustment_item_id', 'Search stock item') ?>
                                    <input type="number" name="item_id" id="adjustment_item_id" class="form-control mt-2" min="1" placeholder="Item ID" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label>Direction</label>
                                        <select name="direction" class="form-control" required>
                                            <option value="increase">Increase</option>
                                            <option value="decrease">Decrease</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Quantity</label>
                                        <input type="text" name="qty" class="form-control" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Store</label>
                                        <input type="number" name="store_id" class="form-control" min="0" value="0">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label>Unit ID</label>
                                        <input type="number" name="unit_id" class="form-control" min="0">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Unit Cost</label>
                                        <input type="text" name="unit_cost" class="form-control" value="0.000000" <?= $recipeWasteCanViewCost ? '' : 'readonly' ?>>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label>Operation Date</label>
                                        <input type="date" name="occurred_at" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label>Reason</label>
                                    <input type="text" name="reason" class="form-control" maxlength="255" required>
                                </div>
                                <button type="submit" class="btn btn-outline-danger" <?= ($recipeWasteWritesEnabled && $recipeWasteCanRecord) ? '' : 'disabled' ?>>Record Adjustment</button>
                            </form>
                        </div>
                    </div>

                    <?php if (!$recipeWasteCanApproveBackdated): ?>
                        <div class="alert alert-secondary">Backdated waste and stock adjustments require approval permission.</div>
                    <?php endif; ?>

                    <form method="GET" class="row mb-3 bg-light p-3 rounded">
                        <div class="col-md-2 mb-2">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" value="<?= $recipeWasteFilters['pos_tenant'] >= 0 ? (int) $recipeWasteFilters['pos_tenant'] : '' ?>" min="0">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" value="<?= $recipeWasteFilters['pos_branch'] >= 0 ? (int) $recipeWasteFilters['pos_branch'] : '' ?>" min="0">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Store</label>
                            <input type="number" name="store_id" class="form-control" value="<?= $recipeWasteFilters['store_id'] >= 0 ? (int) $recipeWasteFilters['store_id'] : '' ?>" min="0">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Item</label>
                            <input type="number" name="item_id" class="form-control" value="<?= $recipeWasteFilters['item_id'] >= 0 ? (int) $recipeWasteFilters['item_id'] : '' ?>" min="1">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Search</label>
                            <input type="text" name="q" class="form-control" value="<?= posmain_recipe_waste_h($recipeWasteFilters['q']) ?>">
                        </div>
                        <div class="col-md-2 mb-2">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" value="<?= (int) $recipeWasteFilters['limit'] ?>" min="1" max="500">
                        </div>
                        <div class="col-md-2 mt-3">
                            <button type="submit" class="btn btn-primary">Filter</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Time</th>
                                    <th>Type</th>
                                    <th>Direction</th>
                                    <th>Item</th>
                                    <th class="text-end">Qty</th>
                                    <?php if ($recipeWasteCanViewCost): ?>
                                        <th class="text-end">Total Cost</th>
                                    <?php endif; ?>
                                    <th>Source</th>
                                    <th class="text-center">User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipeWasteRows as $row): ?>
                                    <tr>
                                        <td><?= posmain_recipe_waste_h($row['created_at'] ?? '') ?></td>
                                        <td><?= posmain_recipe_waste_h($row['movement_type'] ?? '') ?></td>
                                        <td><?= posmain_recipe_waste_h($row['movement_direction'] ?? '') ?></td>
                                        <td><?= posmain_recipe_waste_h(($row['item_name'] ?? '') !== '' ? $row['item_name'] : ('Item ' . (int) ($row['item_id'] ?? 0))) ?></td>
                                        <td class="text-end"><?= posmain_recipe_waste_qty($row['movement_qty'] ?? '') ?></td>
                                        <?php if ($recipeWasteCanViewCost): ?>
                                            <td class="text-end"><?= posmain_recipe_waste_money($row['total_cost'] ?? '') ?></td>
                                        <?php endif; ?>
                                        <td><code><?= posmain_recipe_waste_h($row['source_uuid'] ?? $row['idempotency_key'] ?? '') ?></code></td>
                                        <td class="text-center"><?= $row['created_by'] !== null ? (int) $row['created_by'] : '' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$recipeWasteRows): ?>
                        <div class="alert alert-secondary mt-3">No recipe waste or stock adjustment rows matched the selected filters.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
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
            type: 'items',
            kind: 'stock_component',
            q: query,
            limit: '12'
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
function posmain_recipe_waste_can_view(mysqli $conn): bool
{
    return posmain_recipe_can_view_sensitive_reports($conn);
}

function posmain_recipe_waste_can_record(mysqli $conn): bool
{
    return posmain_recipe_can_manage_stock_operations($conn);
}

function posmain_recipe_waste_can_approve_backdated(mysqli $conn): bool
{
    return auth_guard_is_admin_session($_SESSION, auth_guard_current_role_flags($conn));
}

function posmain_recipe_waste_can_view_cost(mysqli $conn): bool
{
    return posmain_recipe_can_view_costs($conn);
}

function posmain_recipe_waste_actor(mysqli $conn): RecipeActorContext
{
    $scope = (new RecipeScopeResolver())->resolve($_POST);
    $roleFlags = auth_guard_current_role_flags($conn);
    $isAdmin = auth_guard_is_admin_session($_SESSION, $roleFlags);
    $permissions = [];

    if ($isAdmin) {
        $permissions = ['admin', 'recipe.manage', 'recipe.approve', 'inventory.manage', 'inventory.approve'];
    } elseif (posmain_recipe_can_manage_stock_operations($conn)) {
        $permissions = ['recipe.manage', 'inventory.manage'];
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

function posmain_recipe_waste_filters(array $request): array
{
    return [
        'q' => trim((string) ($request['q'] ?? '')),
        'pos_tenant' => isset($request['pos_tenant']) && $request['pos_tenant'] !== '' ? max(0, (int) $request['pos_tenant']) : -1,
        'pos_branch' => isset($request['pos_branch']) && $request['pos_branch'] !== '' ? max(0, (int) $request['pos_branch']) : -1,
        'store_id' => isset($request['store_id']) && $request['store_id'] !== '' ? max(0, (int) $request['store_id']) : -1,
        'item_id' => isset($request['item_id']) && (int) $request['item_id'] > 0 ? (int) $request['item_id'] : -1,
        'limit' => max(1, min(500, (int) ($request['limit'] ?? 100))),
    ];
}

function posmain_recipe_waste_lookup_input(string $id, string $targetInput, string $placeholder): string
{
    return '<div class="recipe-lookup-wrapper position-relative">'
        . '<input type="search" id="' . posmain_recipe_waste_h($id) . '" class="form-control recipe-lookup-input" data-target-input="' . posmain_recipe_waste_h($targetInput) . '" placeholder="' . posmain_recipe_waste_h($placeholder) . '" autocomplete="off">'
        . '<div class="recipe-lookup-results list-group position-absolute w-100 shadow-sm" style="z-index: 1050; display: none;"></div>'
        . '</div>';
}

function posmain_recipe_waste_take_flash(): ?array
{
    $flash = $_SESSION['recipe_waste_flash'] ?? null;
    unset($_SESSION['recipe_waste_flash']);

    return is_array($flash) ? $flash : null;
}

function posmain_recipe_waste_set_flash(string $type, string $message): void
{
    $_SESSION['recipe_waste_flash'] = [
        'type' => in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info',
        'message' => $message,
    ];
}

function posmain_recipe_waste_redirect(): void
{
    header('Location: recipe_waste.php');
    exit;
}

function posmain_recipe_waste_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8) . '-'
        . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-'
        . substr($hex, 20);
}

function posmain_recipe_waste_qty($value): string
{
    return number_format((float) $value, 6, '.', '');
}

function posmain_recipe_waste_money($value): string
{
    return number_format((float) $value, 4, '.', '');
}

function posmain_recipe_waste_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
