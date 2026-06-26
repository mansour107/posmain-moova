<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorMutationService.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorPreviewService.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorReadService.php';
require_once __DIR__ . '/classes/Recipe/RecipeEditorItemCostService.php';
require_once __DIR__ . '/classes/Recipe/RecipeDecimal.php';
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
        posmain_recipe_manage_set_flash('success', $result['message'] ?? 'تم تحديث الوصفة.');
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
$recipeManageCreateMode = isset($_GET['create_recipe']) && (string) $_GET['create_recipe'] === '1';
$recipeManageCreateItemLabel = $recipeManageCreateItemId > 0 ? posmain_recipe_manage_item_label($conn, $recipeManageCreateItemId) : '';
$selectedRecipeId = isset($_GET['recipe_id']) && (int) $_GET['recipe_id'] > 0 ? (int) $_GET['recipe_id'] : 0;
$selectedRecipe = $selectedRecipeId > 0 ? $recipeManageReadService->recipeDetail($conn, $selectedRecipeId) : null;
$selectedHeader = $selectedRecipe['header'] ?? null;
$selectedIsDraft = $selectedHeader && ($selectedHeader['status'] ?? '') === 'draft';
$canApproveRecipe = posmain_recipe_manage_can_approve($conn);
$canViewRecipeCost = posmain_recipe_manage_can_view_cost($conn);
$recipeManagePreview = null;
$recipeManagePreviewError = null;
if ($selectedHeader) {
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
$recipeItemCostState = $selectedRecipe
    ? (new RecipeEditorItemCostService())->buildEditorState(
        $conn,
        $selectedRecipe,
        $selectedHeader ? posmain_recipe_manage_preview_context($_GET, $selectedHeader) : [],
        $canViewRecipeCost
    )
    : ['visible' => false, 'items' => [], 'line_costs' => [], 'variant_line_costs' => []];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card recipe-workspace">
                <div class="card-body">
                    <?php if ($recipeManageFlash): ?>
                        <div class="alert alert-<?= posmain_recipe_manage_h($recipeManageFlash['type']) ?>">
                            <?= posmain_recipe_manage_h(posmain_recipe_manage_ui_message($recipeManageFlash['message'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$recipeManageWritesEnabled): ?>
                        <div class="alert alert-warning">
                            تعديل الوصفات متوقف حسب إعدادات النظام الحالية. الوضع: <?= posmain_recipe_manage_h($recipeManageMode) ?>.
                        </div>
                    <?php endif; ?>

                    <?php if ($recipeManageCreateMode): ?>
                        <div class="recipe-page-header mb-3">
                            <div>
                                <h2 class="mb-1"><?= $recipeManageCreateItemLabel !== '' ? posmain_recipe_manage_h($recipeManageCreateItemLabel) : 'إنشاء وصفة' ?></h2>
                                <div class="text-muted">اختر الصنف واضبط طريقة التحضير، ثم أنشئ الوصفة لفتح صفحة المكونات والتنويعات.</div>
                            </div>
                            <div class="recipe-action-row">
                                <a href="recipe_manage.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right"></i> رجوع</a>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-xl-8">
                                <ul class="nav nav-tabs recipe-tabs mb-3" role="tablist" aria-label="أقسام إنشاء الوصفة">
                                    <li class="nav-item"><a class="nav-link recipe-tab-link active" id="recipe-details-tab" href="#recipe-details" role="tab" aria-controls="recipe-details" aria-selected="true">تفاصيل الوصفة</a></li>
                                </ul>
                                <div class="recipe-tab-panel active" id="recipe-details" role="tabpanel" aria-labelledby="recipe-details-tab">
                                    <div class="card border-secondary mb-3 recipe-info-card">
                                        <div class="card-header bg-light">معلومات الوصفة</div>
                                        <div class="card-body">
                                            <form method="POST" id="recipe-info-form" data-recipe-save-form="recipe-info">
                                                <?= csrf_input('recipe_editor') ?>
                                                <input type="hidden" name="action" value="create_draft">
                                                <div class="row">
                                                    <div class="col-md-12 mb-2">
                                                        <label>الصنف</label>
                                                        <?= posmain_recipe_manage_lookup_input('items', 'sellable', 'create_sellable_item_id', 'ابحث باسم الصنف', [
                                                            'value' => $recipeManageCreateItemLabel,
                                                            'auto_submit_form' => 'recipe-info-form',
                                                        ]) ?>
                                                        <input type="hidden" id="create_sellable_item_id" name="sellable_item_id" value="<?= $recipeManageCreateItemId > 0 ? $recipeManageCreateItemId : '' ?>" required>
                                                    </div>
                                                    <div class="col-md-4 mb-2">
                                                        <label>طريقة التحضير</label>
                                                        <select name="recipe_type" class="form-control">
                                                            <?php foreach (posmain_recipe_manage_recipe_types() as $value => $label): ?>
                                                                <option value="<?= posmain_recipe_manage_h($value) ?>"><?= posmain_recipe_manage_h($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4 mb-2">
                                                        <label>كمية الناتج</label>
                                                        <input type="text" name="yield_qty" class="form-control" value="1.00" required>
                                                    </div>
                                                    <div class="col-md-4 mb-2">
                                                        <div class="recipe-stock-rule-check mt-4">
                                                            <input type="checkbox" name="allow_sale_without_stock" value="1" class="form-check-input" id="create_allow_sale_without_stock">
                                                            <label class="form-check-label" for="create_allow_sale_without_stock">السماح بالبيع عند عدم توفر المكونات</label>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="default_wastage_percent" value="0.00">
                                                    <input type="hidden" name="costing_method" value="item_cost_price">
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="recipe-section-fade" aria-hidden="true"></div>
                                    <div class="card border-secondary mb-3">
                                        <div class="card-header bg-light">
                                            <h3>التنويعات</h3>
                                            <p>اختر الصنف أولا، وسيتم فتح صفحة الوصفة مباشرة مع تنويعاته إن وجدت.</p>
                                        </div>
                                        <div class="card-body">
                                            <div class="recipe-empty-state">بعد اختيار الصنف سيتم إنشاء الوصفة وفتح صفحة المكونات. إذا كان للصنف تنويعات ستظهر كبطاقات قابلة للفتح والتعديل.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-4">
                                <div class="card border-secondary recipe-side-card">
                                    <div class="card-header bg-light">الحالة</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-blue"><i class="fas fa-utensils"></i></span>
                                            <span><strong><?= $recipeManageCreateItemLabel !== '' ? posmain_recipe_manage_h($recipeManageCreateItemLabel) : 'لم يتم اختيار صنف' ?></strong><small>اختر الصنف لفتح صفحة الوصفة تلقائيا</small></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($selectedRecipe): ?>
                        <?php
                        $statusLabel = posmain_recipe_manage_status_label($selectedHeader['status'] ?? '');
                        $typeLabel = posmain_recipe_manage_recipe_type_label($selectedHeader['recipe_type'] ?? '');
                        $itemLabel = posmain_recipe_manage_sellable_item_label($selectedHeader);
                        $mainItemId = (int) ($selectedHeader['main_sellable_item_id'] ?? $selectedHeader['sellable_item_id'] ?? 0);
                        $recipeVariants = $selectedRecipe['variants'] ?? [];
                        $componentCount = count($selectedRecipe['lines']);
                        $availabilityPreview = $recipeManagePreview['availability'] ?? [];
                        $costPreview = $recipeManagePreview['cost'] ?? null;
                        $recipeItemCosts = $recipeItemCostState['items'] ?? [];
                        $recipeLineCosts = $recipeItemCostState['line_costs'] ?? [];
                        $recipeVariantLineCosts = $recipeItemCostState['variant_line_costs'] ?? [];
                        $mainItemCostRow = count($recipeVariants) === 0
                            ? ($recipeItemCosts[(int) $mainItemId] ?? null)
                            : null;
                        $canMakeText = posmain_recipe_manage_qty($availabilityPreview['effective_available_qty'] ?? '');
                        $missingText = trim((string) ($availabilityPreview['unavailable_reason'] ?? ''));
                        $missingDisplay = posmain_recipe_manage_ui_message($missingText);
                        $recipeSummaryUnitCost = posmain_recipe_manage_summary_unit_cost(
                            $costPreview,
                            $recipeVariants,
                            $recipeItemCosts,
                            $canViewRecipeCost
                        );
                        ?>
                        <div class="recipe-page-header mb-3">
                            <div>
                                <h2 class="mb-1"><?= posmain_recipe_manage_h($itemLabel) ?></h2>
                                <div class="text-muted">
                                    v<?= (int) ($selectedHeader['version_number'] ?? 0) ?>
                                </div>
                            </div>
                            <div class="recipe-action-row">
                                <?php if ($selectedIsDraft): ?>
                                    <?php if ($canApproveRecipe): ?>
                                        <?= posmain_recipe_manage_action_button('approve', (int) $selectedHeader['id'], '<i class="fas fa-check-circle"></i> إرسال للمراجعة', 'btn-outline-success', $recipeManageWritesEnabled, false) ?>
                                        <?= posmain_recipe_manage_action_button('activate', (int) $selectedHeader['id'], '<i class="fas fa-bolt"></i> تفعيل', 'btn-success', $recipeManageWritesEnabled, false) ?>
                                    <?php endif; ?>
                                <?php elseif (($selectedHeader['status'] ?? '') === 'active'): ?>
                                    <?= posmain_recipe_manage_action_button('clone_new_version', (int) $selectedHeader['id'], '<i class="fas fa-layer-group"></i> إنشاء إصدار جديد', 'btn-primary', $recipeManageWritesEnabled, false) ?>
                                <?php endif; ?>
                                <details class="recipe-more-menu">
                                    <summary class="btn btn-outline-secondary"><i class="fas fa-ellipsis-v"></i> المزيد <i class="fas fa-chevron-down"></i></summary>
                                    <div class="recipe-more-menu-body">
                                        <?php if (($selectedHeader['status'] ?? '') !== 'archived' && $canApproveRecipe): ?>
                                            <?= posmain_recipe_manage_action_button('archive', (int) $selectedHeader['id'], '<i class="fas fa-archive"></i> أرشفة', 'btn-outline-danger btn-sm', $recipeManageWritesEnabled, false) ?>
                                        <?php endif; ?>
                                        <a href="#recipe-versions" class="btn btn-sm btn-outline-secondary recipe-tab-shortcut"><i class="fas fa-history"></i> عرض الإصدارات</a>
                                        <a href="#recipe-advanced" class="btn btn-sm btn-outline-secondary recipe-tab-shortcut"><i class="fas fa-sliders-h"></i> القواعد المتقدمة</a>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <div class="row mb-3 recipe-top-metrics">
                            <div class="col-md-3 mb-2">
                                <div class="recipe-summary-card">
                                    <span>تكلفة الوصفة</span>
                                    <strong><?= $recipeSummaryUnitCost !== '' ? posmain_recipe_manage_h($recipeSummaryUnitCost) : '-' ?></strong>
                                </div>
                            </div>
                            <div class="col-md-3 mb-2">
                                <div class="recipe-summary-card">
                                    <span>يمكن تحضير</span>
                                    <strong><?= $canMakeText !== '' ? posmain_recipe_manage_h($canMakeText) : '-' ?></strong>
                                </div>
                            </div>
                            <div class="col-md-3 mb-2">
                                <div class="recipe-summary-card">
                                    <span>الحالة</span>
                                    <strong><?= posmain_recipe_manage_h($statusLabel) ?></strong>
                                </div>
                            </div>
                            <div class="col-md-3 mb-2">
                                <div class="recipe-summary-card">
                                    <span>مكونات ناقصة</span>
                                    <strong><?= $missingDisplay !== '' ? posmain_recipe_manage_h($missingDisplay) : 'لا يوجد' ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-xl-8">
                                <ul class="nav nav-tabs recipe-tabs mb-3" role="tablist" aria-label="أقسام الوصفة">
                                    <li class="nav-item"><a class="nav-link recipe-tab-link active" id="recipe-details-tab" href="#recipe-details" role="tab" aria-controls="recipe-details" aria-selected="true">تفاصيل الوصفة</a></li>
                                    <li class="nav-item"><a class="nav-link recipe-tab-link" id="recipe-cost-stock-tab" href="#recipe-cost-stock" role="tab" aria-controls="recipe-cost-stock" aria-selected="false">التكلفة والمخزون</a></li>
                                    <li class="nav-item"><a class="nav-link recipe-tab-link" id="recipe-versions-tab" href="#recipe-versions" role="tab" aria-controls="recipe-versions" aria-selected="false">الإصدارات</a></li>
                                    <li class="nav-item"><a class="nav-link recipe-tab-link" id="recipe-advanced-tab" href="#recipe-advanced" role="tab" aria-controls="recipe-advanced" aria-selected="false">قواعد متقدمة</a></li>
                                </ul>

                                <div class="recipe-tab-panel active" id="recipe-details" role="tabpanel" aria-labelledby="recipe-details-tab">
                                <?php if (count($recipeVariants) > 0): ?>
                                    <div class="recipe-item-page-link mb-3">
                                        <a class="btn btn-sm btn-outline-secondary" href="add_item.php?edit=<?= $mainItemId ?>">
                                            <i class="fas fa-external-link-alt"></i> فتح صفحة الصنف
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <div class="card border-secondary mb-3 recipe-info-card">
                                    <div class="card-header bg-light">معلومات الوصفة</div>
                                    <div class="card-body">
                                        <?php if ($selectedIsDraft): ?>
                                            <form method="POST" id="recipe-info-form" data-recipe-save-form="recipe-info">
                                                <?= csrf_input('recipe_editor') ?>
                                                <input type="hidden" name="action" value="update_draft">
                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                <input type="hidden" name="recipe_name" value="<?= posmain_recipe_manage_h($selectedHeader['recipe_name'] ?? '') ?>">
                                                <div class="row">
                                                    <div class="col-md-4 mb-2">
                                                        <label>طريقة التحضير</label>
                                                        <select name="recipe_type" class="form-control">
                                                            <?= posmain_recipe_manage_options(posmain_recipe_manage_recipe_types(), $selectedHeader['recipe_type'] ?? 'make_to_order') ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4 mb-2">
                                                        <label>كمية الناتج</label>
                                                        <input type="text" name="yield_qty" class="form-control" value="<?= posmain_recipe_manage_h(posmain_recipe_manage_qty($selectedHeader['yield_qty'] ?? '1.00')) ?>" required>
                                                    </div>
                                                    <div class="col-md-4 mb-2">
                                                        <div class="recipe-stock-rule-check mt-4">
                                                            <input type="checkbox" name="allow_sale_without_stock" value="1" class="form-check-input" id="allow_sale_without_stock" <?= !empty($selectedHeader['allow_sale_without_stock']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="allow_sale_without_stock">السماح بالبيع عند عدم توفر المكونات</label>
                                                        </div>
                                                    </div>
                                                    <input type="hidden" name="requires_recipe_for_sale" value="<?= !empty($selectedHeader['requires_recipe_for_sale']) ? '1' : '0' ?>">
                                                    <input type="hidden" name="default_wastage_percent" value="<?= posmain_recipe_manage_h(posmain_recipe_manage_qty($selectedHeader['default_wastage_percent'] ?? '0.00')) ?>">
                                                    <input type="hidden" name="costing_method" value="<?= posmain_recipe_manage_h($selectedHeader['costing_method'] ?? 'item_cost_price') ?>">
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="row">
                                                <div class="col-md-4 mb-2"><strong>طريقة التحضير:</strong> <?= posmain_recipe_manage_h($typeLabel) ?></div>
                                                <div class="col-md-4 mb-2"><strong>كمية الناتج:</strong> <?= posmain_recipe_manage_h(posmain_recipe_manage_qty($selectedHeader['yield_qty'] ?? '1.00')) ?></div>
                                                <div class="col-md-4 mb-2"><strong>قاعدة المخزون:</strong> <?= !empty($selectedHeader['allow_sale_without_stock']) ? 'السماح بالبيع عند عدم التوفر' : 'منع البيع عند عدم التوفر' ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="recipe-section-fade" aria-hidden="true"></div>
                                <?php if ($canViewRecipeCost && !empty($recipeItemCostState['visible'])): ?>
                                    <?php if ($mainItemCostRow): ?>
                                        <?= posmain_recipe_manage_item_cost_card(
                                            (int) $selectedHeader['id'],
                                            $mainItemCostRow,
                                            'تكلفة الصنف',
                                            $selectedIsDraft,
                                            $recipeManageWritesEnabled
                                        ) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (count($recipeVariants) > 0): ?>
                                    <div class="recipe-variation-recipes mb-3">
                                        <?php foreach ($recipeVariants as $variantRecipeIndex => $variant): ?>
                                            <?php
                                            $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
                                            $variantCostRow = $recipeItemCosts[$variantItemId] ?? null;
                                            ?>
                                            <?= posmain_recipe_manage_variant_recipe_card(
                                                $conn,
                                                $selectedHeader,
                                                $variant,
                                                (int) $variantRecipeIndex,
                                                $recipeManageWritesEnabled,
                                                false,
                                                $variantCostRow,
                                                $canViewRecipeCost,
                                                $recipeVariantLineCosts[$variantItemId] ?? []
                                            ) ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                <div class="card border-secondary mb-3">
                                    <div class="card-header bg-light">
                                        <h3>مكونات الوصفة</h3>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($selectedIsDraft): ?>
                                            <form method="POST" class="recipe-component-form mb-3" data-recipe-save-form="add-component">
                                                <?= csrf_input('recipe_editor') ?>
                                                <input type="hidden" name="action" value="add_line">
                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                <div class="row">
                                                    <div class="col-md-4 mb-2">
                                                        <label>المكون</label>
                                                        <?= posmain_recipe_manage_component_input('add') ?>
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>الكمية</label>
                                                        <input type="number" name="qty_per_yield" class="form-control recipe-qty-input" value="1" step="1" min="1" required>
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>الوحدة</label>
                                                        <?= posmain_recipe_manage_unit_select($conn, 'unit_id', 0, 'اختر المكون أولا') ?>
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>الهالك %</label>
                                                        <input type="text" name="wastage_percent" class="form-control" value="0.00">
                                                    </div>
                                                    <div class="col-md-2 mb-2">
                                                        <label>ينطبق على</label>
                                                        <input type="text" class="form-control" value="كل الطلبات" readonly>
                                                    </div>
                                                    <input type="hidden" name="unit_conversion_to_base" value="1.00000000">
                                                    <input type="hidden" name="order_type" value="any">
                                                    <input type="hidden" name="channel" value="any">
                                                    <input type="hidden" name="modifier_group_id" value="">
                                                    <input type="hidden" name="modifier_option_id" value="">
                                                    <input type="hidden" name="modifier_behavior" value="additive">
                                                    <input type="hidden" name="substitution_group" value="">
                                                    <input type="hidden" name="sort_order" value="0">
                                                    <input type="hidden" name="is_required" value="1">
                                                    <input type="hidden" name="notes" value="">
                                                    <div class="col-md-12">
                                                        <button type="submit" class="btn btn-primary recipe-add-component-button">
                                                            <i class="fas fa-plus"></i> إضافة مكون جديد
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($componentCount < 1): ?>
                                            <div class="recipe-empty-state">
                                                <strong>لم يتم إضافة مكونات بعد</strong>
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>المكون</th>
                                                            <th class="recipe-unit-price-col">سعر الوحدة</th>
                                                            <th class="recipe-qty-col text-end">الكمية</th>
                                                            <th>الوحدة</th>
                                                            <th>الهالك %</th>
                                                            <th>النوع</th>
                                                            <th>التكلفة</th>
                                                            <th>ينطبق على</th>
                                                            <th>إجراءات</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($selectedRecipe['lines'] as $line): ?>
                                                            <tr>
                                                                <td>
                                                                    <span class="recipe-component-name">
                                                                        <span class="recipe-component-icon"><i class="fas <?= posmain_recipe_manage_h(posmain_recipe_manage_component_icon_class($line)) ?>"></i></span>
                                                                        <span><?= posmain_recipe_manage_h(posmain_recipe_manage_component_label($line)) ?></span>
                                                                    </span>
                                                                </td>
                                                                <td class="recipe-unit-price-col"><?= posmain_recipe_manage_component_unit_cost_field($line, $recipeLineCosts, $canViewRecipeCost) ?></td>
                                                                <td class="recipe-qty-col text-end"><?= posmain_recipe_manage_h(posmain_recipe_manage_integer_qty($line['qty_per_yield'] ?? '')) ?></td>
                                                                <td><?= posmain_recipe_manage_h(posmain_recipe_manage_unit_label($line)) ?></td>
                                                                <td><?= posmain_recipe_manage_h(posmain_recipe_manage_qty($line['wastage_percent'] ?? '0.00')) ?></td>
                                                                <td><?= posmain_recipe_manage_h(posmain_recipe_manage_component_type_label($line)) ?></td>
                                                                <td><?= $canViewRecipeCost ? posmain_recipe_manage_h(posmain_recipe_manage_line_cost_label($line, $recipeLineCosts)) : 'مخفية' ?></td>
                                                                <td><?= posmain_recipe_manage_h(posmain_recipe_manage_applies_to_label($line)) ?></td>
                                                                <td>
                                                                    <?php if ($selectedIsDraft): ?>
                                                                        <details class="recipe-row-actions">
                                                                            <summary class="btn btn-sm btn-outline-secondary recipe-icon-button" title="تعديل"><i class="fas fa-pen"></i></summary>
                                                                            <form method="POST" class="recipe-inline-edit mt-2" data-recipe-save-form="edit-component">
                                                                                <?= csrf_input('recipe_editor') ?>
                                                                                <input type="hidden" name="action" value="update_line">
                                                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                                                <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                                                                                <label>المكون</label>
                                                                                <?= posmain_recipe_manage_component_input('line_' . (int) $line['id'], $line) ?>
                                                                                <label class="mt-2">الكمية</label>
                                                                                <input type="number" name="qty_per_yield" class="form-control recipe-qty-input" value="<?= posmain_recipe_manage_h(posmain_recipe_manage_integer_qty($line['qty_per_yield'] ?? '1')) ?>" step="1" min="1" required>
                                                                                <label class="mt-2">الوحدة</label>
                                                                                <?= posmain_recipe_manage_unit_select($conn, 'unit_id', (int) ($line['unit_id'] ?? 0)) ?>
                                                                                <label class="mt-2">الهالك %</label>
                                                                                <input type="text" name="wastage_percent" class="form-control" value="<?= posmain_recipe_manage_h(posmain_recipe_manage_qty($line['wastage_percent'] ?? '0.00')) ?>">
                                                                                <input type="hidden" name="unit_conversion_to_base" value="<?= posmain_recipe_manage_h($line['unit_conversion_to_base'] ?? '1.00000000') ?>">
                                                                                <input type="hidden" name="order_type" value="<?= posmain_recipe_manage_h($line['order_type'] ?? 'any') ?>">
                                                                                <input type="hidden" name="channel" value="<?= posmain_recipe_manage_h($line['channel'] ?? 'any') ?>">
                                                                                <input type="hidden" name="modifier_group_id" value="<?= posmain_recipe_manage_h($line['modifier_group_id'] ?? '') ?>">
                                                                                <input type="hidden" name="modifier_option_id" value="<?= posmain_recipe_manage_h($line['modifier_option_id'] ?? '') ?>">
                                                                                <input type="hidden" name="modifier_behavior" value="<?= posmain_recipe_manage_h($line['modifier_behavior'] ?? 'additive') ?>">
                                                                                <input type="hidden" name="substitution_group" value="<?= posmain_recipe_manage_h($line['substitution_group'] ?? '') ?>">
                                                                                <input type="hidden" name="sort_order" value="<?= (int) ($line['sort_order'] ?? 0) ?>">
                                                                                <input type="hidden" name="is_required" value="<?= !empty($line['is_required']) ? '1' : '0' ?>">
                                                                                <label class="mt-2">ملاحظات</label>
                                                                                <input type="text" name="notes" class="form-control" maxlength="500" value="<?= posmain_recipe_manage_h($line['notes'] ?? '') ?>">
                                                                            </form>
                                                                            <form method="POST" class="mt-2">
                                                                                <?= csrf_input('recipe_editor') ?>
                                                                                <input type="hidden" name="action" value="remove_line">
                                                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                                                <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                                                                                <button type="submit" class="btn btn-sm btn-outline-danger" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>><i class="fas fa-trash"></i> حذف</button>
                                                                            </form>
                                                                        </details>
                                                                    <?php else: ?>
                                                                        عرض فقط
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                </div>

                                <div class="card border-secondary mb-3 recipe-tab-panel" id="recipe-cost-stock" role="tabpanel" aria-labelledby="recipe-cost-stock-tab">
                                    <div class="card-header bg-light">التكلفة والتوفر</div>
                                    <div class="card-body">
                                        <?php if ($componentCount < 1): ?>
                                            <div class="recipe-empty-state">ستظهر التكلفة والتوفر بعد إضافة المكونات.</div>
                                        <?php elseif ($recipeManagePreviewError): ?>
                                            <div class="alert alert-warning mb-0"><?= posmain_recipe_manage_h(posmain_recipe_manage_ui_message($recipeManagePreviewError)) ?></div>
                                        <?php else: ?>
                                            <div class="row">
                                                <div class="col-md-4 mb-2"><strong>الحساب لـ:</strong> الفرع الرئيسي</div>
                                                <div class="col-md-4 mb-2"><strong>سياسة التكلفة:</strong> <?= posmain_recipe_manage_h(posmain_recipe_manage_costing_policy_label($selectedHeader['costing_method'] ?? 'item_cost_price')) ?></div>
                                                <div class="col-md-4 mb-2"><strong>آخر حساب:</strong> <?= posmain_recipe_manage_h(date('Y-m-d H:i')) ?></div>
                                                <?php if ($canViewRecipeCost && $costPreview): ?>
                                                    <div class="col-md-4 mb-2"><strong>تكلفة الوصفة:</strong> <?= posmain_recipe_manage_qty($costPreview['cost_per_yield'] ?? '') ?></div>
                                                    <div class="col-md-4 mb-2"><strong>تكلفة الوحدة المباعة:</strong> <?= posmain_recipe_manage_h($recipeSummaryUnitCost !== '' ? $recipeSummaryUnitCost : posmain_recipe_manage_qty($costPreview['cost_per_sell_unit'] ?? '')) ?></div>
                                                    <div class="col-md-4 mb-2"><strong>هامش الربح:</strong> -</div>
                                                <?php endif; ?>
                                                <?php if ($canViewRecipeCost && !empty($recipeItemCostState['visible'])): ?>
                                                    <div class="col-md-12 mt-2">
                                                        <?php if ($mainItemCostRow): ?>
                                                            <?= posmain_recipe_manage_item_cost_card(
                                                                (int) $selectedHeader['id'],
                                                                $mainItemCostRow,
                                                                'تكلفة الصنف المحفوظة',
                                                                $selectedIsDraft,
                                                                $recipeManageWritesEnabled
                                                            ) ?>
                                                        <?php elseif (count($recipeVariants) > 0): ?>
                                                            <div class="alert alert-light mb-0">تكلفة كل تنويعة تظهر داخل قسم التنويعات في تفاصيل الوصفة.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="col-md-4 mb-2"><strong>يمكن تحضير الآن:</strong> <?= $canMakeText !== '' ? posmain_recipe_manage_h($canMakeText) : '-' ?></div>
                                                <div class="col-md-4 mb-2"><strong>المكون المحدد:</strong> <?= $missingDisplay !== '' ? posmain_recipe_manage_h($missingDisplay) : 'لا يوجد' ?></div>
                                                <div class="col-md-4 mb-2"><strong>مكونات ناقصة:</strong> <?= $missingDisplay !== '' ? posmain_recipe_manage_h($missingDisplay) : 'لا يوجد' ?></div>
                                            </div>
                                            <form method="GET" class="mt-2">
                                                <input type="hidden" name="recipe_id" value="<?= (int) $selectedHeader['id'] ?>">
                                                <input type="hidden" name="preview" value="1">
                                                <input type="hidden" name="store_id" value="<?= (int) ($_GET['store_id'] ?? 0) ?>">
                                                <input type="hidden" name="order_type" value="<?= posmain_recipe_manage_h($_GET['order_type'] ?? 'takeaway') ?>">
                                                <input type="hidden" name="channel" value="<?= posmain_recipe_manage_h($_GET['channel'] ?? 'pos') ?>">
                                                <input type="hidden" name="safety_stock" value="0">
                                                <input type="hidden" name="costing_method" value="<?= posmain_recipe_manage_h($_GET['costing_method'] ?? ($selectedHeader['costing_method'] ?? 'item_cost_price')) ?>">
                                                <button type="submit" class="btn btn-outline-primary">إعادة الحساب</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card border-secondary mb-3 recipe-tab-panel" id="recipe-versions" role="tabpanel" aria-labelledby="recipe-versions-tab">
                                    <div class="card-header bg-light">الإصدارات والنشاط</div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>الإصدار</th>
                                                        <th>الحالة</th>
                                                        <th>الاسم</th>
                                                        <th>الاعتماد</th>
                                                        <th>آخر تعديل</th>
                                                        <th>إجراءات</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (($selectedRecipe['versions'] ?? []) as $version): ?>
                                                        <tr class="<?= (int) $version['id'] === (int) $selectedHeader['id'] ? 'table-primary' : '' ?>">
                                                            <td>v<?= (int) ($version['version_number'] ?? 0) ?></td>
                                                            <td><?= posmain_recipe_manage_h(posmain_recipe_manage_status_label($version['status'] ?? '')) ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['recipe_name'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['approved_at'] ?? '') ?></td>
                                                            <td><?= posmain_recipe_manage_h($version['updated_at'] ?? '') ?></td>
                                                            <td><a class="btn btn-sm btn-outline-secondary" href="recipe_manage.php?recipe_id=<?= (int) $version['id'] ?>">عرض</a></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border-secondary recipe-tab-panel" id="recipe-advanced" role="tabpanel" aria-labelledby="recipe-advanced-tab">
                                    <div class="card-header bg-light">قواعد متقدمة</div>
                                    <div class="card-body">
                                        <p class="mb-0 text-muted">قواعد الفرع والقناة ونوع الطلب وتاريخ التفعيل والتغليف والصلاحيات موجودة في محرك الوصفات بدون إظهارها في كل صف مكون.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xl-4">
                                <div class="card border-secondary mb-3 recipe-side-card">
                                    <div class="card-header bg-light">الحالة</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon"><i class="fas fa-pen"></i></span>
                                            <span><strong><?= posmain_recipe_manage_h($statusLabel) ?></strong><small><?= ($selectedHeader['status'] ?? '') === 'active' ? 'مستخدمة حاليا في نقطة البيع والمخزون' : 'ليست مفعلة في نقطة البيع بعد' ?></small></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card border-secondary mb-3 recipe-side-card">
                                    <div class="card-header bg-light">الإصدار</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-blue"><i class="fas fa-layer-group"></i></span>
                                            <span><strong>v<?= (int) ($selectedHeader['version_number'] ?? 0) ?></strong><small>أنت تعدل هذا الإصدار</small></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card border-secondary mb-3 recipe-side-card">
                                    <div class="card-header bg-light">تكلفة الوصفة</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-green"><i class="fas fa-money-bill-wave"></i></span>
                                            <span><strong><?= $recipeSummaryUnitCost !== '' ? posmain_recipe_manage_h($recipeSummaryUnitCost) . ' ج.م' : '-' ?></strong><small>لكل وحدة</small></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card border-secondary mb-3 recipe-side-card">
                                    <div class="card-header bg-light">يمكن تحضير</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-green"><i class="fas fa-chart-line"></i></span>
                                            <span><strong><?= $canMakeText !== '' ? posmain_recipe_manage_h($canMakeText) : '-' ?></strong><small>وحدات حسب المخزون الحالي</small></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card border-secondary mb-3 recipe-side-card">
                                    <div class="card-header bg-light">مكونات ناقصة</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-green"><i class="fas fa-check-circle"></i></span>
                                            <span><strong><?= $missingDisplay !== '' ? posmain_recipe_manage_h($missingDisplay) : 'لا يوجد' ?></strong><small><?= $missingDisplay !== '' ? 'راجع المخزون أو الوحدات' : 'كل المكونات متوفرة' ?></small></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card border-secondary recipe-side-card">
                                    <div class="card-header bg-light">المفعل في نقطة البيع</div>
                                    <div class="card-body">
                                        <div class="recipe-side-card-row">
                                            <span class="recipe-side-icon recipe-side-icon-blue"><i class="fas fa-store"></i></span>
                                            <span><strong><?= posmain_recipe_manage_h(posmain_recipe_manage_active_version_label($selectedRecipe['versions'] ?? [])) ?></strong><small><?= posmain_recipe_manage_h(posmain_recipe_manage_active_version_note($selectedRecipe['versions'] ?? [])) ?></small></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($selectedIsDraft): ?>
                            <div class="recipe-bottom-save-bar">
                                <button type="button" id="recipe-global-save-button" class="btn btn-primary btn-lg" <?= $recipeManageWritesEnabled ? '' : 'disabled' ?>>
                                    <i class="fas fa-save"></i> حفظ التغييرات
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="recipe-page-header mb-3">
                            <div>
                                <a href="recipe_manage.php?create_recipe=1" class="btn btn-primary btn-lg recipe-create-top-button">
                                    <i class="fas fa-plus"></i> إنشاء وصفة جديدة
                                </a>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-12">
                                <div class="card border-secondary">
                                    <div class="card-header bg-light">مكتبة الوصفات</div>
                                    <div class="card-body">
                                        <form method="GET" class="mb-3">
                                            <div class="input-group">
                                                <input type="text" name="q" class="form-control" value="<?= posmain_recipe_manage_h($recipeManageFilters['q']) ?>" placeholder="ابحث باسم الصنف">
                                                <button class="btn btn-outline-secondary" type="submit">تصفية</button>
                                            </div>
                                        </form>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>الصنف المرتبط</th>
                                                        <th>الحالة</th>
                                                        <th>الإصدار النشط</th>
                                                        <th>المتاح للتحضير</th>
                                                        <th>آخر تعديل</th>
                                                        <th>إجراءات</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recipeManageRows as $row): ?>
                                                        <?php
                                                        $availabilityCell = posmain_recipe_manage_availability_cell(
                                                            (int) ($row['cached_effective_is_available'] ?? 0),
                                                            (string) ($row['cached_effective_available_qty'] ?? '0'),
                                                            (string) ($row['status'] ?? ''),
                                                            (string) ($row['cached_unavailable_reason'] ?? '')
                                                        );
                                                        ?>
                                                        <tr>
                                                            <td><?= posmain_recipe_manage_h(posmain_recipe_manage_sellable_item_label($row)) ?></td>
                                                            <td><?= posmain_recipe_manage_h(posmain_recipe_manage_status_label($row['status'] ?? '')) ?></td>
                                                            <td>v<?= (int) ($row['version_number'] ?? 0) ?></td>
                                                            <td><?= $availabilityCell ?></td>
                                                            <td><?= posmain_recipe_manage_h($row['updated_at'] ?? '') ?></td>
                                                            <td><a class="btn btn-sm btn-outline-primary" href="recipe_manage.php?recipe_id=<?= (int) $row['id'] ?>">فتح</a></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <?php if (count($recipeManageRows) === 0): ?>
                                                        <tr>
                                                            <td colspan="6" class="text-center text-muted py-4">
                                                                لا توجد وصفات بعد. ابدأ بإضافة صنف قابل للبيع ثم أنشئ وصفة له من صفحة
                                                                <a href="myitems.php">الأصناف</a>.
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<style>
.recipe-workspace {
    direction: rtl;
    background: #f7f9fc;
    border: 0;
    box-shadow: none;
}
.recipe-workspace > .card-body {
    padding: 24px 28px;
}
.recipe-workspace .card {
    border: 1px solid #e3e8ef !important;
    border-radius: 8px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}
.recipe-workspace .card-header {
    background: #fff !important;
    border-bottom: 1px solid #edf1f6;
    color: #1f2937;
    font-weight: 700;
}
.recipe-workspace input,
.recipe-workspace select,
.recipe-workspace textarea {
    border-color: #d7dde6;
    border-radius: 7px;
    color: #374151;
    min-height: 42px;
    text-align: right;
}
.recipe-workspace input:focus,
.recipe-workspace select:focus,
.recipe-workspace textarea:focus {
    background: #fff !important;
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
}
.recipe-workspace label {
    color: #1f2937;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 6px;
}
.recipe-page-header {
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: flex-start;
    background: #fff;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    padding: 20px 22px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
}
.recipe-page-header h2 {
    color: #111827;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: 0;
}
.recipe-action-row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-start;
}
.recipe-action-row .btn {
    align-items: center;
    border-radius: 7px;
    display: inline-flex;
    gap: 7px;
    min-height: 40px;
    padding: 8px 13px;
}
.recipe-action-row form {
    margin: 0;
}
.recipe-bottom-save-bar {
    display: flex;
    justify-content: flex-start;
    margin-top: 18px;
    padding-bottom: 8px;
}
.recipe-bottom-save-bar .btn {
    font-weight: 800;
    min-width: 190px;
}
.recipe-more-menu {
    position: relative;
}
.recipe-more-menu summary {
    list-style: none;
}
.recipe-more-menu summary::-webkit-details-marker {
    display: none;
}
.recipe-more-menu-body {
    background: #fff;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.14);
    display: grid;
    gap: 8px;
    left: 0;
    min-width: 210px;
    padding: 10px;
    position: absolute;
    top: calc(100% + 8px);
    z-index: 20;
}
.recipe-more-menu-body .btn {
    justify-content: flex-start;
    width: 100%;
}
.recipe-top-metrics {
    display: none;
}
.recipe-workspace .col-xl-8 {
    display: flex;
    flex-direction: column;
}
.recipe-tabs {
    background: #fff;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    flex-wrap: nowrap;
    gap: 4px;
    order: 0;
    overflow-x: auto;
    padding: 8px;
}
.recipe-tabs .nav-link {
    border: 0;
    border-radius: 7px;
    color: #64748b !important;
    font-weight: 700;
    padding: 9px 13px;
    white-space: nowrap;
}
.recipe-tabs .nav-link.active {
    background: #eef2ff;
    color: #4338ca !important;
}
.recipe-tab-panel {
    display: none;
}
.recipe-tab-panel.active {
    display: block;
}
#recipe-details {
    order: 1;
}
#recipe-cost-stock {
    order: 2;
}
#recipe-versions {
    order: 3;
}
#recipe-advanced {
    order: 4;
}
#recipe-details .card-header h3 {
    color: #111827;
    font-weight: 800;
}
#recipe-details .card-header p {
    color: #64748b;
}
#recipe-details .card-header h3 {
    font-size: 22px;
    margin: 0;
}
#recipe-details .card-header p {
    margin: 4px 0 0;
}
.recipe-item-page-link {
    display: flex;
    justify-content: flex-start;
}
.recipe-create-top-button {
    font-size: 20px;
    font-weight: 800;
    padding: 14px 28px;
}
.recipe-empty-state {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    color: #64748b;
    padding: 18px;
    text-align: center;
}
.recipe-stock-rule-check {
    align-items: center;
    display: flex;
    gap: 10px;
    line-height: 1.4;
    min-height: 38px;
}
.recipe-stock-rule-check .form-check-input {
    flex: 0 0 auto;
    float: none;
    margin: 0;
    position: static;
}
.recipe-stock-rule-check .form-check-label {
    margin: 0;
}
.recipe-section-fade {
    background: linear-gradient(90deg, rgba(226, 232, 240, 0), rgba(148, 163, 184, 0.55), rgba(226, 232, 240, 0));
    height: 1px;
    margin: 20px auto;
    max-width: 460px;
    width: 58%;
}
.recipe-variation-recipe-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
    margin-bottom: 16px;
    overflow: hidden;
}
.recipe-variation-recipe-card summary {
    align-items: center;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    cursor: pointer;
    display: flex;
    gap: 12px;
    justify-content: space-between;
    list-style: none;
    padding: 16px 18px;
}
.recipe-variation-recipe-card summary::-webkit-details-marker {
    display: none;
}
.recipe-variation-recipe-toggle {
    color: #64748b;
    flex-shrink: 0;
    font-size: 14px;
    transition: transform 0.2s ease;
}
.recipe-variation-recipe-card[open] .recipe-variation-recipe-toggle {
    transform: rotate(180deg);
}
.recipe-variation-recipe-card-direct {
    display: block;
}
.recipe-variation-direct-title {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 18px;
}
.recipe-variation-recipe-title strong {
    color: #111827;
    display: block;
    font-size: 22px;
    font-weight: 900;
}
.recipe-variation-recipe-body {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 16px;
}
.recipe-variation-recipe-table th {
    color: #475569;
    font-size: 13px;
    white-space: nowrap;
}
.recipe-variation-recipe-table td {
    vertical-align: middle;
}
.recipe-qty-col {
    min-width: 88px;
    width: 96px;
}
.recipe-qty-input,
.recipe-variation-amount {
    font-size: 14px;
    font-weight: 700;
    min-width: 72px;
    text-align: center;
    width: 100%;
}
.recipe-variation-component-cell {
    min-width: 230px;
}
.recipe-unit-price-col {
    min-width: 58px;
    width: 68px;
    white-space: nowrap;
}
.recipe-component-unit-cost {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    min-width: 54px;
    padding: 2px 4px;
    text-align: center;
    width: 100%;
}
.recipe-component-form {
    background: #f8fafc;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    padding: 16px;
}
.recipe-add-component-button {
    font-weight: 800;
    min-width: 150px;
}
.recipe-lookup-results {
    left: 0;
    right: auto;
    text-align: right;
}
.recipe-workspace .table {
    color: #273142;
}
.recipe-workspace .table thead th {
    background: #f8fafc;
    border-bottom: 1px solid #e3e8ef;
    color: #64748b;
    font-size: 12px;
    font-weight: 800;
    padding: 12px;
}
.recipe-workspace .table td {
    border-color: #eef2f7;
    padding: 12px;
    vertical-align: middle;
}
.recipe-component-name,
.recipe-side-card-row {
    align-items: center;
    display: flex;
    gap: 10px;
}
.recipe-component-icon,
.recipe-side-icon {
    align-items: center;
    background: #eef2ff;
    border-radius: 999px;
    color: #4338ca;
    display: inline-flex;
    flex: 0 0 auto;
    height: 34px;
    justify-content: center;
    width: 34px;
}
.recipe-side-icon-green {
    background: #ecfdf5;
    color: #059669;
}
.recipe-side-icon-blue {
    background: #eff6ff;
    color: #2563eb;
}
.recipe-icon-button {
    align-items: center;
    display: inline-flex;
    height: 34px;
    justify-content: center;
    padding: 0;
    width: 34px;
}
.recipe-row-actions {
    position: relative;
}
.recipe-row-actions summary {
    list-style: none;
}
.recipe-row-actions summary::-webkit-details-marker {
    display: none;
}
.recipe-inline-edit {
    background: #fff;
    border: 1px solid #e3e8ef;
    border-radius: 8px;
    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.12);
    left: auto;
    min-width: 300px;
    padding: 12px;
    position: absolute;
    right: 0;
    top: 38px;
    z-index: 15;
}
.recipe-side-card .card-body {
    padding: 16px;
}
.recipe-side-card-row strong {
    color: #111827;
    display: block;
    font-size: 18px;
    line-height: 1.2;
}
.recipe-side-card-row small {
    color: #64748b;
    display: block;
    margin-top: 3px;
}
.recipe-empty-state {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    color: #475569;
    padding: 18px;
}
.recipe-empty-state p {
    margin: 4px 0 0;
}
@media (max-width: 991px) {
    .recipe-workspace > .card-body {
        padding: 16px;
    }
    .recipe-page-header {
        display: block;
    }
    .recipe-action-row {
        justify-content: flex-start;
        margin-top: 12px;
    }
    .recipe-page-header h2 {
        font-size: 24px;
    }
    .recipe-inline-edit {
        position: static;
    }
}
</style>

<script>
(function () {
    const scope = {
        pos_tenant: <?= (int) ($selectedHeader['pos_tenant'] ?? 0) ?>,
        pos_branch: <?= (int) ($selectedHeader['pos_branch'] ?? 0) ?>,
        exclude_recipe_id: <?= (int) ($selectedHeader['id'] ?? 0) ?>
    };
    const timers = new WeakMap();
    const tabLinks = Array.prototype.slice.call(document.querySelectorAll('.recipe-tab-link'));
    const tabPanels = Array.prototype.slice.call(document.querySelectorAll('.recipe-tab-panel'));
    let lastRecipeSaveForm = null;

    function activateRecipeTab(targetId, updateHash) {
        if (!targetId || !document.getElementById(targetId)) {
            return;
        }
        tabLinks.forEach(function (link) {
            const isActive = link.getAttribute('href') === '#' + targetId;
            link.classList.toggle('active', isActive);
            link.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        tabPanels.forEach(function (panel) {
            const isActive = panel.id === targetId;
            panel.classList.toggle('active', isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });
        if (updateHash && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + targetId);
        }
    }

    tabLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            activateRecipeTab((link.getAttribute('href') || '').replace('#', ''), true);
        });
    });
    document.querySelectorAll('.recipe-tab-shortcut').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            activateRecipeTab((link.getAttribute('href') || '').replace('#', ''), true);
            const moreMenu = link.closest('details');
            if (moreMenu) {
                moreMenu.open = false;
            }
        });
    });
    if (window.location.hash && document.querySelector(window.location.hash + '.recipe-tab-panel')) {
        activateRecipeTab(window.location.hash.slice(1), false);
    } else {
        activateRecipeTab('recipe-details', false);
    }

    document.querySelectorAll('[data-recipe-save-form]').forEach(function (form) {
        form.addEventListener('focusin', function () {
            lastRecipeSaveForm = form;
        });
        form.addEventListener('input', function () {
            lastRecipeSaveForm = form;
        });
        form.addEventListener('change', function () {
            lastRecipeSaveForm = form;
        });
    });

    function isVisibleRecipeForm(form) {
        return !!form && !!(form.offsetWidth || form.offsetHeight || form.getClientRects().length);
    }

    function componentFormHasSelection(form) {
        if (!form) {
            return false;
        }
        const ingredient = form.querySelector('input[name="ingredient_item_id"]');
        const subRecipe = form.querySelector('input[name="sub_recipe_id"]');
        const lookup = form.querySelector('.recipe-lookup-input');

        return (ingredient && ingredient.value.trim() !== '')
            || (subRecipe && subRecipe.value.trim() !== '')
            || (lookup && lookup.value.trim() !== '');
    }

    function activeRecipeSaveForm() {
        if (isVisibleRecipeForm(lastRecipeSaveForm)) {
            if (lastRecipeSaveForm.dataset.recipeSaveForm === 'add-component' && !componentFormHasSelection(lastRecipeSaveForm)) {
                lastRecipeSaveForm = null;
            } else {
                return lastRecipeSaveForm;
            }
        }

        if (isVisibleRecipeForm(lastRecipeSaveForm)) {
            return lastRecipeSaveForm;
        }

        const activePanel = document.querySelector('.recipe-tab-panel.active');
        if (!activePanel) {
            return document.getElementById('recipe-info-form');
        }

        if (activePanel.id === 'recipe-cost-stock') {
            const costForm = activePanel.querySelector('form[data-recipe-save-form="item-costs"]');
            if (isVisibleRecipeForm(costForm)) {
                return costForm;
            }
        }

        if (activePanel.id === 'recipe-details') {
            const focusedForm = document.activeElement ? document.activeElement.closest('form[data-recipe-save-form]') : null;
            if (isVisibleRecipeForm(focusedForm)) {
                return focusedForm;
            }
            const openVariantRecipe = activePanel.querySelector('details[open] form[data-recipe-save-form="variant-recipe"]');
            if (isVisibleRecipeForm(openVariantRecipe)) {
                return openVariantRecipe;
            }
            const openEdit = activePanel.querySelector('details[open] form[data-recipe-save-form="edit-component"]');
            if (isVisibleRecipeForm(openEdit)) {
                return openEdit;
            }
            const addComponent = activePanel.querySelector('form[data-recipe-save-form="add-component"]');
            if (componentFormHasSelection(addComponent)) {
                return addComponent;
            }
            return document.getElementById('recipe-info-form');
        }

        return activePanel.querySelector('form[data-recipe-save-form]') || document.getElementById('recipe-info-form');
    }

    const globalSaveButton = document.getElementById('recipe-global-save-button');
    if (globalSaveButton) {
        globalSaveButton.addEventListener('click', function () {
            const form = activeRecipeSaveForm();
            if (!form) {
                return;
            }
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }

    function refreshVariantRecipeRows(card) {
        const variantId = card.getAttribute('data-variant-item-id') || '0';
        card.querySelectorAll('.recipe-variant-recipe-row').forEach(function (row, index) {
            const prefix = 'variant_recipe_' + variantId + '_' + index;
            const lookup = row.querySelector('.recipe-lookup-input');
            const component = row.querySelector('input[name="variant_recipe_component_id[]"]');
            const lineType = row.querySelector('input[name="variant_recipe_line_type[]"]');
            if (component) {
                component.id = prefix + '_component_id';
            }
            if (lineType) {
                lineType.id = prefix + '_line_type';
            }
            if (lookup) {
                lookup.dataset.targetInput = prefix + '_component_id';
                lookup.dataset.lineTypeInput = prefix + '_line_type';
            }
        });
    }

    function emptyVariantRecipeRowHtml(card) {
        const sample = card.querySelector('.recipe-variant-recipe-row');
        if (!sample) {
            return '';
        }
        const clone = sample.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) {
            if (input.name === 'variant_recipe_qty_per_yield[]') {
                input.value = '1';
            } else if (input.name === 'variant_recipe_wastage_percent[]') {
                input.value = '0.00';
            } else if (input.name === 'variant_recipe_line_type[]') {
                input.value = 'ingredient';
            } else {
                input.value = '';
            }
            if (input.classList.contains('recipe-lookup-input')) {
                input.dataset.lookupBound = '';
            }
        });
        clone.querySelectorAll('select').forEach(function (select) {
            select.value = '';
        });
        clone.querySelectorAll('.recipe-lookup-results').forEach(function (box) {
            box.innerHTML = '';
            box.style.display = 'none';
        });
        return clone.outerHTML;
    }

    document.addEventListener('click', function (event) {
        const addVariantComponent = event.target.closest ? event.target.closest('.recipe-add-variant-component') : null;
        if (addVariantComponent) {
            const card = addVariantComponent.closest('[data-variant-recipe-card]');
            const body = card ? card.querySelector('.recipe-variant-recipe-rows') : null;
            if (card && body) {
                body.insertAdjacentHTML('beforeend', emptyVariantRecipeRowHtml(card));
                refreshVariantRecipeRows(card);
            }
        }
        const removeVariantComponent = event.target.closest ? event.target.closest('.recipe-remove-variant-component') : null;
        if (removeVariantComponent) {
            const card = removeVariantComponent.closest('[data-variant-recipe-card]');
            const row = removeVariantComponent.closest('.recipe-variant-recipe-row');
            if (card && row && card.querySelectorAll('.recipe-variant-recipe-row').length > 1) {
                row.remove();
                refreshVariantRecipeRows(card);
            }
        }
    });
    document.querySelectorAll('[data-variant-recipe-card]').forEach(refreshVariantRecipeRows);

    document.addEventListener('input', function (event) {
        const input = event.target.closest ? event.target.closest('.recipe-item-cost-input') : null;
        if (!input) {
            return;
        }
        const form = input.closest('form');
        const manualFlag = form ? form.querySelector('.recipe-item-cost-manual-flag') : null;
        const calculated = (input.getAttribute('data-calculated-cost') || '').trim();
        if (manualFlag) {
            manualFlag.value = input.value.trim() !== calculated ? '1' : '0';
        }
    });

    document.addEventListener('click', function (event) {
        const resetButton = event.target.closest ? event.target.closest('.recipe-item-cost-reset') : null;
        if (!resetButton) {
            return;
        }
        const form = resetButton.closest('form');
        if (!form) {
            return;
        }
        const input = form.querySelector('.recipe-item-cost-input');
        const resetFlag = form.querySelector('.recipe-item-cost-reset-flag');
        const manualFlag = form.querySelector('.recipe-item-cost-manual-flag');
        if (input) {
            input.value = input.getAttribute('data-calculated-cost') || '0';
        }
        if (resetFlag) {
            resetFlag.value = '1';
        }
        if (manualFlag) {
            manualFlag.value = '0';
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });

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
            button.textContent = item.label || item.name || 'مكون';
            button.addEventListener('click', function () {
                const target = document.getElementById(input.dataset.targetInput || '');
                if (target) {
                    target.value = item.id || '';
                }
                const ingredientTarget = document.getElementById(input.dataset.ingredientInput || '');
                const subRecipeTarget = document.getElementById(input.dataset.subRecipeInput || '');
                const lineTypeTarget = document.getElementById(input.dataset.lineTypeInput || '');
                const typeLabelTarget = document.getElementById(input.dataset.typeLabel || '');
                if (ingredientTarget || subRecipeTarget || lineTypeTarget) {
                    const lineType = item.line_type || 'ingredient';
                    if (ingredientTarget) {
                        ingredientTarget.value = lineType === 'sub_recipe' ? '' : (item.id || '');
                    }
                    if (subRecipeTarget) {
                        subRecipeTarget.value = lineType === 'sub_recipe' ? (item.id || '') : '';
                    }
                    if (lineTypeTarget) {
                        lineTypeTarget.value = lineType;
                    }
                    if (typeLabelTarget) {
                        typeLabelTarget.value = item.component_type || item.item_type || lineType;
                    }
                }
                const groupTarget = document.getElementById(input.dataset.targetGroupInput || '');
                if (groupTarget && item.group_id) {
                    groupTarget.value = item.group_id;
                }
                const nameTarget = document.getElementById(input.dataset.targetNameInput || '');
                if (nameTarget && !nameTarget.value.trim()) {
                    nameTarget.value = 'وصفة ' + (item.name || button.textContent || '').trim();
                }
                input.value = button.textContent;
                clearResults(input);
                if (input.dataset.autoSubmitForm) {
                    const submitForm = document.getElementById(input.dataset.autoSubmitForm);
                    if (submitForm) {
                        if (typeof submitForm.requestSubmit === 'function') {
                            submitForm.requestSubmit();
                        } else {
                            submitForm.submit();
                        }
                    }
                    return;
                }
                if (input.dataset.selectRedirectTemplate && item.id) {
                    window.location.href = input.dataset.selectRedirectTemplate.replace('{id}', encodeURIComponent(String(item.id)));
                }
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

    function bindLookupInput(input) {
        if (!input || input.dataset.lookupBound === '1') {
            return;
        }
        input.dataset.lookupBound = '1';
        input.addEventListener('input', function () {
            clearTimeout(timers.get(input));
            timers.set(input, setTimeout(function () { fetchLookup(input); }, 220));
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { clearResults(input); }, 200);
        });
    }

    document.querySelectorAll('.recipe-lookup-input').forEach(bindLookupInput);
    document.addEventListener('focusin', function (event) {
        if (event.target && event.target.classList && event.target.classList.contains('recipe-lookup-input')) {
            bindLookupInput(event.target);
        }
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
    $scope = (new RecipeScopeResolver())->resolveForConn($conn, $_POST, 'write');
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

function posmain_recipe_manage_summary_unit_cost(?array $costPreview, array $variants, array $itemCosts, bool $canViewCost): string
{
    if (!$canViewCost) {
        return '';
    }

    if (count($variants) > 0 && $itemCosts) {
        $amounts = [];
        foreach ($variants as $variant) {
            $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
            if ($variantItemId < 1 || !isset($itemCosts[$variantItemId])) {
                continue;
            }
            $row = $itemCosts[$variantItemId];
            $amounts[] = (string) ($row['display_cost'] ?? $row['calculated_cost'] ?? '0');
        }
        if ($amounts) {
            $normalized = array_values(array_unique(array_map(static function (string $amount): string {
                return RecipeDecimal::normalize($amount);
            }, $amounts)));
            if (count($normalized) === 1) {
                return posmain_recipe_manage_qty($normalized[0]);
            }

            sort($normalized);
            return posmain_recipe_manage_qty($normalized[0]) . ' - ' . posmain_recipe_manage_qty($normalized[count($normalized) - 1]);
        }
    }

    if (!$costPreview) {
        return '';
    }

    $range = $costPreview['variant_cost_range'] ?? null;
    if (is_array($range)) {
        $min = (string) ($range['cost_per_sell_unit_min'] ?? '');
        $max = (string) ($range['cost_per_sell_unit_max'] ?? '');
        if ($min !== '' && $max !== '' && RecipeDecimal::compare($min, $max) !== 0) {
            return posmain_recipe_manage_qty($min) . ' - ' . posmain_recipe_manage_qty($max);
        }
    }

    return posmain_recipe_manage_qty($costPreview['cost_per_sell_unit'] ?? '');
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

function posmain_recipe_manage_action_button(string $action, int $recipeId, string $label, string $class, bool $enabled, bool $escapeLabel = true): string
{
    $buttonLabel = $escapeLabel ? posmain_recipe_manage_h($label) : $label;

    return '<form method="POST" class="d-inline">'
        . csrf_input('recipe_editor')
        . '<input type="hidden" name="action" value="' . posmain_recipe_manage_h($action) . '">'
        . '<input type="hidden" name="recipe_id" value="' . $recipeId . '">'
        . '<button type="submit" class="btn ' . posmain_recipe_manage_h($class) . '" ' . ($enabled ? '' : 'disabled') . '>'
        . $buttonLabel
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
    if (array_key_exists('value', $extra)) {
        $attrs['value'] = (string) $extra['value'];
    }
    if (!empty($extra['target_group_input'])) {
        $attrs['data-target-group-input'] = (string) $extra['target_group_input'];
    }
    if (!empty($extra['target_name_input'])) {
        $attrs['data-target-name-input'] = (string) $extra['target_name_input'];
    }
    if (!empty($extra['select_redirect_template'])) {
        $attrs['data-select-redirect-template'] = (string) $extra['select_redirect_template'];
    }
    if (!empty($extra['auto_submit_form'])) {
        $attrs['data-auto-submit-form'] = (string) $extra['auto_submit_form'];
    }
    foreach (['target_ingredient_input', 'target_sub_recipe_input', 'target_line_type_input', 'target_type_label'] as $extraName) {
        if (!empty($extra[$extraName])) {
            $dataName = str_replace('_', '-', substr($extraName, 7));
            $attrs['data-' . $dataName] = (string) $extra[$extraName];
        }
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

function posmain_recipe_manage_component_input(string $prefix, ?array $line = null): string
{
    $ingredientId = (int) ($line['ingredient_item_id'] ?? 0);
    $subRecipeId = (int) ($line['sub_recipe_id'] ?? 0);
    $lineType = (string) ($line['line_type'] ?? 'ingredient');
    $label = $line ? posmain_recipe_manage_component_label($line) : '';

    return posmain_recipe_manage_lookup_input('components', 'any', $prefix . '_component_lookup', 'ابحث عن مكون بالاسم', [
            'value' => $label,
            'target_ingredient_input' => $prefix . '_ingredient_item_id',
            'target_sub_recipe_input' => $prefix . '_sub_recipe_id',
            'target_line_type_input' => $prefix . '_line_type',
            'target_type_label' => $prefix . '_component_type_label',
        ])
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_ingredient_item_id" name="ingredient_item_id" value="' . ($ingredientId > 0 ? $ingredientId : '') . '">'
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_sub_recipe_id" name="sub_recipe_id" value="' . ($subRecipeId > 0 ? $subRecipeId : '') . '">'
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_line_type" name="line_type" value="' . posmain_recipe_manage_h($lineType) . '">';
}

function posmain_recipe_manage_modifier_input(string $prefix, ?array $line = null): string
{
    $groupId = (int) ($line['modifier_group_id'] ?? 0);
    $optionId = (int) ($line['modifier_option_id'] ?? 0);

    return posmain_recipe_manage_lookup_input('modifier_options', '', $prefix . '_modifier_option_id', 'ابحث عن اختيار إضافة', [
            'target_group_input' => $prefix . '_modifier_group_id',
        ])
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_modifier_group_id" name="modifier_group_id" value="' . ($groupId > 0 ? $groupId : '') . '">'
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_modifier_option_id" name="modifier_option_id" value="' . ($optionId > 0 ? $optionId : '') . '">';
}

function posmain_recipe_manage_component_label(array $line): string
{
    $label = trim((string) ($line['ingredient_item_name'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $label = trim((string) ($line['sub_recipe_name'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return 'مكون';
}

function posmain_recipe_manage_component_type_label(array $line): string
{
    $lineType = (string) ($line['line_type'] ?? 'ingredient');
    if ($lineType === 'sub_recipe') {
        return 'وصفة محضرة';
    }
    if ($lineType === 'packaging') {
        return 'تغليف';
    }
    if ($lineType === 'modifier_ingredient') {
        return 'تأثير إضافة';
    }
    if ($lineType === 'labor_placeholder') {
        return 'عمل';
    }

    return 'مكون خام';
}

function posmain_recipe_manage_component_icon_class(array $line): string
{
    $lineType = (string) ($line['line_type'] ?? 'ingredient');
    if ($lineType === 'sub_recipe') {
        return 'fa-blender';
    }
    if ($lineType === 'packaging') {
        return 'fa-box-open';
    }
    if ($lineType === 'modifier_ingredient') {
        return 'fa-sliders-h';
    }
    if ($lineType === 'labor_placeholder') {
        return 'fa-user-cog';
    }

    return 'fa-seedling';
}

function posmain_recipe_manage_unit_label(array $line): string
{
    $unitName = trim((string) ($line['unit_name'] ?? ''));
    return $unitName !== '' ? $unitName : '-';
}

function posmain_recipe_manage_sellable_item_label(array $row): string
{
    $name = trim((string) ($row['main_sellable_item_name'] ?? $row['sellable_item_name'] ?? ''));
    $barcode = trim((string) ($row['main_sellable_item_barcode'] ?? $row['sellable_item_barcode'] ?? ''));
    if ($name !== '') {
        return trim($name . ($barcode !== '' ? ' - ' . $barcode : ''));
    }

    return 'الصنف غير موجود';
}

function posmain_recipe_manage_variant_recipe_card(
    mysqli $conn,
    array $recipe,
    array $variant,
    int $index,
    bool $enabled,
    bool $direct = false,
    ?array $costRow = null,
    bool $canViewCost = false,
    array $lineCosts = []
): string {
    $recipeId = (int) ($recipe['id'] ?? 0);
    $variantItemId = (int) ($variant['variant_item_id'] ?? 0);
    $label = trim((string) ($variant['variant_label'] ?? ''));
    $name = trim((string) ($variant['iname'] ?? $variant['name'] ?? ''));
    $title = $name !== '' ? $name : ($label !== '' ? $label : 'تنويعة');
    $costHtml = ($canViewCost && $costRow)
        ? posmain_recipe_manage_item_cost_fields($recipeId, $costRow, $enabled)
        : '';
    $lines = is_array($variant['editable_recipe_lines'] ?? null) ? $variant['editable_recipe_lines'] : [];
    $rowHtml = '';
    foreach ($lines as $lineIndex => $line) {
        $rowHtml .= posmain_recipe_manage_variant_recipe_line_row(
            $conn,
            $variantItemId,
            $line,
            (int) $lineIndex,
            $lineCosts,
            $canViewCost
        );
    }
    if ($rowHtml === '') {
        $rowHtml = posmain_recipe_manage_variant_recipe_line_row($conn, $variantItemId, null, 0, $lineCosts, $canViewCost);
    }
    $disabled = $enabled ? '' : ' disabled';

    if ($direct) {
        return '<div class="recipe-variation-recipe-card recipe-variation-recipe-card-direct" data-variant-recipe-card data-variant-item-id="' . $variantItemId . '">'
        . ($costHtml !== '' ? '<div class="recipe-variation-cost-row px-3 pt-3">' . $costHtml . '</div>' : '')
        . '<div class="recipe-variation-recipe-body">'
        . '<form method="POST" class="recipe-variant-recipe-form" data-recipe-save-form="variant-recipe">'
        . csrf_input('recipe_editor')
        . '<input type="hidden" name="action" value="save_variant_recipe">'
        . '<input type="hidden" name="recipe_id" value="' . $recipeId . '">'
        . '<input type="hidden" name="variant_item_id" value="' . $variantItemId . '">'
        . '<div class="table-responsive">'
        . '<table class="table table-sm recipe-variation-recipe-table mb-2">'
        . '<thead><tr><th>المكون</th><th class="recipe-unit-price-col">سعر الوحدة</th><th class="recipe-qty-col">الكمية</th><th>الوحدة</th><th>الهالك %</th><th>ملاحظات</th><th>إجراءات</th></tr></thead>'
        . '<tbody class="recipe-variant-recipe-rows">' . $rowHtml . '</tbody>'
        . '</table>'
        . '</div>'
        . '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">'
        . '<button type="button" class="btn btn-outline-primary btn-sm recipe-add-variant-component"' . $disabled . '><i class="fas fa-plus"></i> إضافة مكون للتنويعة</button>'
        . '</div>'
        . '</form>'
        . '</div>'
        . '</div>';
    }

    return '<details class="recipe-variation-recipe-card" data-variant-recipe-card data-variant-item-id="' . $variantItemId . '">'
        . '<summary>'
        . '<span class="recipe-variation-recipe-title"><strong>' . posmain_recipe_manage_h($title) . '</strong>'
        . ($costHtml !== '' ? '<span class="recipe-variation-cost-inline text-muted"> — تكلفة: ' . posmain_recipe_manage_h(posmain_recipe_manage_qty($costRow['display_cost'] ?? '')) . ' ج.م</span>' : '')
        . '</span>'
        . '<span class="recipe-variation-recipe-toggle" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>'
        . '</summary>'
        . ($costHtml !== '' ? '<div class="recipe-variation-cost-row px-3 pt-2">' . $costHtml . '</div>' : '')
        . '<div class="recipe-variation-recipe-body">'
        . '<form method="POST" class="recipe-variant-recipe-form" data-recipe-save-form="variant-recipe">'
        . csrf_input('recipe_editor')
        . '<input type="hidden" name="action" value="save_variant_recipe">'
        . '<input type="hidden" name="recipe_id" value="' . $recipeId . '">'
        . '<input type="hidden" name="variant_item_id" value="' . $variantItemId . '">'
        . '<div class="table-responsive">'
        . '<table class="table table-sm recipe-variation-recipe-table mb-2">'
        . '<thead><tr><th>المكون</th><th class="recipe-unit-price-col">سعر الوحدة</th><th class="recipe-qty-col">الكمية</th><th>الوحدة</th><th>الهالك %</th><th>ملاحظات</th><th>إجراءات</th></tr></thead>'
        . '<tbody class="recipe-variant-recipe-rows">' . $rowHtml . '</tbody>'
        . '</table>'
        . '</div>'
        . '<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">'
        . '<button type="button" class="btn btn-outline-primary btn-sm recipe-add-variant-component"' . $disabled . '><i class="fas fa-plus"></i> إضافة مكون للتنويعة</button>'
        . '</div>'
        . '</form>'
        . '</div>'
        . '</details>';
}

function posmain_recipe_manage_availability_cell(int $isAvailable, string $effectiveQty, string $status, string $unavailableReason): string
{
    // Only surface availability for active recipes; drafts/archived have no live cache row.
    if ($status !== 'active') {
        return '<span class="text-muted">—</span>';
    }

    // No cache row yet (availability feature off or not refreshed) — show unknown rather
    // than a misleading "available" so the owner knows the cache is empty.
    if ($isAvailable === 0 && (float) $effectiveQty <= 0 && $unavailableReason === '') {
        return '<span class="badge bg-secondary">غير محسوب</span>';
    }

    $qty = posmain_recipe_manage_qty($effectiveQty);
    if ($isAvailable === 1) {
        if ((float) $effectiveQty > 0 && (float) $effectiveQty <= 5) {
            return '<span class="badge bg-warning">متبقي ' . posmain_recipe_manage_h($qty) . '</span>';
        }
        return '<span class="badge bg-success">متاح (' . posmain_recipe_manage_h($qty) . ')</span>';
    }

    $reason = trim($unavailableReason);
    return '<span class="badge bg-danger" title="' . posmain_recipe_manage_h($reason) . '">غير متاح</span>';
}

function posmain_recipe_manage_item_cost_card(int $recipeId, array $costRow, string $title, bool $isDraft, bool $enabled): string
{
    // Owners may adjust or override an item's cost even after the recipe is activated
    // (e.g. set a manual override, or reset back to auto-calculated). The form is
    // therefore enabled whenever writes are allowed, not only for draft recipes.
    $fields = posmain_recipe_manage_item_cost_fields($recipeId, $costRow, $enabled);
    if ($fields === '') {
        return '';
    }

    return '<div class="card border-secondary mb-3 recipe-item-cost-card">'
        . '<div class="card-header bg-light">' . posmain_recipe_manage_h($title) . '</div>'
        . '<div class="card-body">' . $fields . '</div>'
        . '</div>';
}

function posmain_recipe_manage_item_cost_fields(int $recipeId, array $costRow, bool $enabled): string
{
    $itemId = (int) ($costRow['item_id'] ?? 0);
    if ($itemId < 1) {
        return '';
    }

    $calculated = posmain_recipe_manage_qty($costRow['calculated_cost'] ?? '0');
    $display = posmain_recipe_manage_qty($costRow['display_cost'] ?? '0');
    $manual = !empty($costRow['manual_cost_edit']);
    $disabled = $enabled ? '' : ' disabled';

    return '<form method="POST" class="recipe-item-cost-form" data-recipe-save-form="item-costs">'
        . csrf_input('recipe_editor')
        . '<input type="hidden" name="action" value="save_item_costs">'
        . '<input type="hidden" name="recipe_id" value="' . $recipeId . '">'
        . '<input type="hidden" name="item_cost_item_id[]" value="' . $itemId . '">'
        . '<input type="hidden" name="item_cost_manual_edit[]" value="' . ($manual ? '1' : '0') . '" class="recipe-item-cost-manual-flag">'
        . '<div class="row align-items-end">'
        . '<div class="col-md-4 mb-2">'
        . '<label class="text-muted mb-1">التكلفة المحسوبة من المكونات</label>'
        . '<div class="form-control-plaintext"><strong>' . posmain_recipe_manage_h($calculated) . ' ج.م</strong></div>'
        . '</div>'
        . '<div class="col-md-4 mb-2">'
        . '<label>تكلفة الصنف</label>'
        . '<input type="number" class="form-control recipe-item-cost-input" name="item_cost_price[]" value="' . posmain_recipe_manage_h($display) . '" step="0.001" min="0" data-calculated-cost="' . posmain_recipe_manage_h($calculated) . '"' . $disabled . '>'
        . '</div>'
        . '<div class="col-md-4 mb-2">'
        . '<button type="button" class="btn btn-outline-secondary recipe-item-cost-reset"' . $disabled . '>إعادة للحساب التلقائي</button>'
        . '<input type="hidden" name="item_cost_reset_auto[]" value="0" class="recipe-item-cost-reset-flag">'
        . '</div>'
        . '</div>'
        . ($manual ? '<small class="text-muted">تم تعديل التكلفة يدويا.</small>' : '<small class="text-muted">يتم تحديث التكلفة تلقائيا عند تغيير المكونات.</small>')
        . '</form>';
}

function posmain_recipe_manage_line_cost_label(array $line, array $lineCosts): string
{
    $lineId = (int) ($line['id'] ?? 0);
    if ($lineId < 1 || !isset($lineCosts[$lineId])) {
        return '-';
    }

    $row = $lineCosts[$lineId];
    $total = is_array($row) ? (string) ($row['total_cost'] ?? '0') : (string) $row;

    return posmain_recipe_manage_qty($total) . ' ج.م';
}

function posmain_recipe_manage_line_unit_cost_value(array $line, array $lineCosts): string
{
    $lineId = (int) ($line['id'] ?? 0);
    if ($lineId < 1 || !isset($lineCosts[$lineId])) {
        return '';
    }

    $row = $lineCosts[$lineId];
    if (!is_array($row)) {
        return '';
    }

    return posmain_recipe_manage_qty($row['unit_cost'] ?? '');
}

function posmain_recipe_manage_component_unit_cost_field(array $line, array $lineCosts, bool $canViewCost): string
{
    if (!$canViewCost) {
        return '<input type="text" class="form-control form-control-sm recipe-component-unit-cost" value="مخفية" readonly tabindex="-1" aria-label="سعر الوحدة">';
    }

    $unitCost = posmain_recipe_manage_line_unit_cost_value($line, $lineCosts);
    $display = $unitCost !== '' ? $unitCost : '-';

    return '<input type="text" class="form-control form-control-sm recipe-component-unit-cost" value="' . posmain_recipe_manage_h($display) . '" readonly tabindex="-1" aria-label="سعر الوحدة" title="سعر الوحدة من بطاقة الصنف (غير قابل للتعديل)">';
}

function posmain_recipe_manage_variant_recipe_line_row(
    mysqli $conn,
    int $variantItemId,
    ?array $line,
    int $index,
    array $lineCosts = [],
    bool $canViewCost = false
): string {
    $prefix = 'variant_recipe_' . $variantItemId . '_' . $index;
    $lineType = (string) ($line['line_type'] ?? 'ingredient');
    if ($lineType === 'modifier_ingredient') {
        $lineType = 'ingredient';
    }
    $componentId = $lineType === 'sub_recipe'
        ? (int) ($line['sub_recipe_id'] ?? 0)
        : (int) ($line['ingredient_item_id'] ?? 0);
    $label = $line ? posmain_recipe_manage_component_label($line) : '';
    $baseLineId = (int) ($line['base_line_id'] ?? $line['id'] ?? 0);
    $qty = posmain_recipe_manage_integer_qty($line['qty_per_yield'] ?? '1');
    $unitId = (int) ($line['unit_id'] ?? 0);
    $waste = posmain_recipe_manage_qty($line['wastage_percent'] ?? '0.00');
    $notes = (string) ($line['notes'] ?? '');

    $unitCostField = $line
        ? posmain_recipe_manage_component_unit_cost_field($line, $lineCosts, $canViewCost)
        : posmain_recipe_manage_component_unit_cost_field(['id' => 0], $lineCosts, $canViewCost);

    return '<tr class="recipe-variant-recipe-row">'
        . '<td class="recipe-variation-component-cell">'
        . posmain_recipe_manage_lookup_input('components', 'any', $prefix . '_component_id', 'ابحث عن مكون', [
            'value' => $label,
            'target_line_type_input' => $prefix . '_line_type',
        ])
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_component_id" name="variant_recipe_component_id[]" value="' . ($componentId > 0 ? $componentId : '') . '">'
        . '<input type="hidden" id="' . posmain_recipe_manage_h($prefix) . '_line_type" name="variant_recipe_line_type[]" value="' . posmain_recipe_manage_h($lineType) . '">'
        . '<input type="hidden" name="variant_recipe_base_line_id[]" value="' . ($baseLineId > 0 ? $baseLineId : '') . '">'
        . '</td>'
        . '<td class="recipe-unit-price-col">' . $unitCostField . '</td>'
        . '<td class="recipe-qty-col"><input type="number" class="form-control form-control-sm recipe-variation-amount recipe-qty-input" name="variant_recipe_qty_per_yield[]" value="' . posmain_recipe_manage_h($qty) . '" step="1" min="1" inputmode="numeric"></td>'
        . '<td>' . posmain_recipe_manage_unit_select($conn, 'variant_recipe_unit_id[]', $unitId, 'الوحدة الأساسية') . '</td>'
        . '<td><input type="number" class="form-control form-control-sm" name="variant_recipe_wastage_percent[]" value="' . posmain_recipe_manage_h($waste) . '" step="0.01" min="0"></td>'
        . '<td><input type="text" class="form-control form-control-sm" name="variant_recipe_notes[]" value="' . posmain_recipe_manage_h($notes) . '" placeholder="اختياري"></td>'
        . '<td><button type="button" class="btn btn-sm btn-outline-danger recipe-remove-variant-component"><i class="fas fa-trash"></i></button></td>'
        . '</tr>';
}

function posmain_recipe_manage_unit_select(mysqli $conn, string $name, int $selectedId = 0, string $emptyLabel = 'استخدم وحدة الصنف الأساسية'): string
{
    $options = '<option value="">' . posmain_recipe_manage_h($emptyLabel) . '</option>';
    if (posmain_recipe_manage_table_exists($conn, 'myunits')) {
        $result = $conn->query('SELECT id, uname FROM myunits WHERE COALESCE(isdeleted, 0) = 0 ORDER BY uname ASC, id ASC');
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            $label = trim((string) ($row['uname'] ?? ''));
            $options .= '<option value="' . $id . '"' . ($selectedId === $id ? ' selected' : '') . '>'
                . posmain_recipe_manage_h($label !== '' ? $label : ('وحدة ' . $id))
                . '</option>';
        }
    }

    return '<select name="' . posmain_recipe_manage_h($name) . '" class="form-control">' . $options . '</select>';
}

function posmain_recipe_manage_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0) > 0;
}

function posmain_recipe_manage_item_label(mysqli $conn, int $itemId): string
{
    if ($itemId < 1) {
        return '';
    }

    $stmt = $conn->prepare('SELECT iname, barcode FROM myitems WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return '';
    }

    $name = trim((string) ($row['iname'] ?? ''));
    $barcode = trim((string) ($row['barcode'] ?? ''));
    return trim($name . ($barcode !== '' ? ' - ' . $barcode : ''));
}

function posmain_recipe_manage_recipe_types(): array
{
    return [
        'make_to_order' => 'يصنع عند الطلب',
        'batch_prepared' => 'يحضر كدفعة',
        'hybrid' => 'مكون محضر',
    ];
}

function posmain_recipe_manage_recipe_title(?array $header): string
{
    if (!$header) {
        return 'وصفة';
    }
    $itemName = trim((string) ($header['sellable_item_name'] ?? ''));
    if ($itemName !== '') {
        return 'وصفة ' . $itemName;
    }
    $recipeName = trim((string) ($header['recipe_name'] ?? ''));
    return $recipeName !== '' ? $recipeName : 'وصفة';
}

function posmain_recipe_manage_status_label($status): string
{
    $status = strtolower(trim((string) $status));
    $labels = [
        'draft' => 'قيد التحرير',
        'pending_review' => 'بانتظار المراجعة',
        'approved' => 'معتمدة',
        'active' => 'نشطة',
        'scheduled' => 'مجدولة',
        'archived' => 'مؤرشفة',
    ];

    return $labels[$status] ?? ($status !== '' ? str_replace('_', ' ', $status) : 'قيد التحرير');
}

function posmain_recipe_manage_recipe_type_label($type): string
{
    $types = posmain_recipe_manage_recipe_types();
    $type = (string) $type;
    if (isset($types[$type])) {
        return $types[$type];
    }

    $fallbacks = [
        'packaging_bundle' => 'إعداد تغليف',
        'modifier_only' => 'تنويعات',
        'sub_recipe' => 'مكون محضر',
    ];

    return $fallbacks[$type] ?? 'يصنع عند الطلب';
}

function posmain_recipe_manage_applies_to_label(array $line): string
{
    $orderType = strtolower(trim((string) ($line['order_type'] ?? 'any')));
    $channel = strtolower(trim((string) ($line['channel'] ?? 'any')));
    $labels = [];

    if ($orderType !== '' && $orderType !== 'any') {
        $labels[] = posmain_recipe_manage_order_types()[$orderType] ?? str_replace('_', ' ', $orderType);
    }
    if ($channel !== '' && $channel !== 'any') {
        $labels[] = posmain_recipe_manage_channels()[$channel] ?? strtoupper($channel);
    }

    return $labels ? implode(' / ', $labels) : 'كل الطلبات';
}

function posmain_recipe_manage_costing_policy_label($method): string
{
    $method = strtolower(trim((string) $method));
    $labels = [
        'item_cost_price' => 'سعر تكلفة الصنف',
        'moving_average' => 'متوسط التكلفة المرجح',
        'last_purchase' => 'آخر تكلفة شراء',
        'manual_snapshot' => 'تكلفة معيارية يدوية',
    ];

    return $labels[$method] ?? 'سعر تكلفة الصنف';
}

function posmain_recipe_manage_line_types(): array
{
    return [
        'ingredient' => 'مكون خام',
        'packaging' => 'تغليف',
        'modifier_ingredient' => 'تأثير إضافة',
        'sub_recipe' => 'وصفة محضرة',
        'labor_placeholder' => 'عمل تقديري',
    ];
}

function posmain_recipe_manage_modifier_behaviors(): array
{
    return [
        'additive' => 'إضافة',
        'substitution_remove' => 'إزالة بديل',
        'substitution_add' => 'إضافة بديل',
    ];
}

function posmain_recipe_manage_order_types(): array
{
    return [
        'any' => 'أي نوع',
        'dine_in' => 'داخل المطعم',
        'takeaway' => 'تيك أواي',
        'delivery' => 'توصيل',
    ];
}

function posmain_recipe_manage_channels(): array
{
    return [
        'any' => 'أي قناة',
        'pos' => 'POS',
        'table' => 'الطاولات',
        'moova' => 'Moova',
        'cofe' => 'Cofe',
        'api' => 'API',
    ];
}

function posmain_recipe_manage_costing_methods(): array
{
    return [
        'item_cost_price' => 'سعر تكلفة الصنف',
        'moving_average' => 'متوسط التكلفة',
        'last_purchase' => 'آخر شراء',
        'manual_snapshot' => 'تكلفة يدوية',
    ];
}

function posmain_recipe_manage_active_version_label(array $versions): string
{
    foreach ($versions as $version) {
        if (($version['status'] ?? '') === 'active') {
            return 'v' . (int) ($version['version_number'] ?? 0);
        }
    }

    return 'لا يوجد';
}

function posmain_recipe_manage_active_version_note(array $versions): string
{
    foreach ($versions as $version) {
        if (($version['status'] ?? '') === 'active') {
            $activatedAt = trim((string) ($version['approved_at'] ?? ''));
            return $activatedAt !== '' ? 'مفعل منذ ' . $activatedAt : 'مستخدم حاليا في نقطة البيع';
        }
    }

    return 'لم يتم تفعيل أي إصدار بعد';
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

    return number_format((float) $value, 2, '.', '');
}

function posmain_recipe_manage_integer_qty($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    return (string) max(0, (int) round((float) $value));
}

function posmain_recipe_manage_money_value($value): string
{
    return number_format((float) $value, 3, '.', '');
}

function posmain_recipe_manage_ui_message($message): string
{
    $message = trim((string) $message);
    if ($message === '') {
        return '';
    }

    $normalized = rtrim($message, '.');
    $translations = [
        'Recipe updated' => 'تم تحديث الوصفة',
        'Draft recipe created' => 'تم إنشاء الوصفة',
        'Recipe draft updated' => 'تم حفظ تغييرات الوصفة',
        'Recipe line added' => 'تمت إضافة المكون',
        'Recipe line updated' => 'تم حفظ المكون',
        'Recipe line removed' => 'تم حذف المكون',
        'Recipe approved' => 'تم اعتماد الوصفة',
        'Recipe activated' => 'تم تفعيل الوصفة',
        'Recipe archived' => 'تمت أرشفة الوصفة',
        'Recipe cloned as a new version' => 'تم إنشاء إصدار جديد',
        'Item variations updated' => 'تم حفظ التنويعات',
        'Variation recipe updated' => 'تم حفظ وصفة التنويعة',
        'Please choose an item by name' => 'اختر الصنف بالاسم',
        'Please choose a component by name' => 'اختر المكون بالاسم',
        'Required ingredient out of stock' => 'مكون مطلوب غير متوفر في المخزون',
        'Manual unavailable' => 'الصنف غير متاح يدويا',
        'No active recipe' => 'لا توجد وصفة مفعلة',
        'Recipe invalid' => 'الوصفة غير مكتملة',
        'Unit conversion missing' => 'تحويل الوحدة غير موجود',
    ];

    return $translations[$normalized] ?? $message;
}
