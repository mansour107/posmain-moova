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
$recipeProductionStores = posmain_recipe_production_stock_stores($conn);
$recipeProductionStoreNames = [];
foreach ($recipeProductionStores as $store) {
    $recipeProductionStoreNames[(int) $store['id']] = (string) $store['aname'];
}
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

<style>
    .recipe-production-page {
        direction: rtl;
        background: #f6f8fb;
        min-height: calc(100vh - 57px);
    }
    .recipe-production-wrap {
        max-width: 1440px;
        margin: 0 auto;
        padding: 18px;
    }
    .recipe-production-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        background: #102033;
        color: #fff;
        border-radius: 8px;
        box-shadow: 0 12px 28px rgba(16, 32, 51, 0.16);
    }
    .recipe-production-title {
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 0;
    }
    .recipe-production-subtitle {
        margin: 6px 0 0;
        color: #c8d4df;
        font-size: 13px;
    }
    .recipe-production-mode {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        border-radius: 8px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.2);
        color: #fff;
        white-space: nowrap;
    }
    .recipe-production-grid {
        display: grid;
        grid-template-columns: minmax(300px, 390px) 1fr;
        gap: 16px;
        margin-top: 16px;
    }
    .recipe-production-panel {
        background: #fff;
        border: 1px solid #dde5ee;
        border-radius: 8px;
        box-shadow: 0 8px 22px rgba(16, 32, 51, 0.06);
        overflow: hidden;
    }
    .recipe-production-panel + .recipe-production-panel {
        margin-top: 16px;
    }
    .recipe-production-panel-head {
        padding: 13px 16px;
        border-bottom: 1px solid #e6edf4;
        background: #fbfcfe;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
    }
    .recipe-production-panel-title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: #172235;
    }
    .recipe-production-panel-body {
        padding: 16px;
    }
    .recipe-production-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .recipe-production-label {
        display: block;
        margin-bottom: 5px;
        color: #526173;
        font-size: 12px;
        font-weight: 700;
    }
    .recipe-production-search {
        margin-bottom: 8px;
    }
    .recipe-production-filter-note {
        margin-top: 5px;
        color: #64748b;
        font-size: 12px;
    }
    .recipe-production-list {
        display: grid;
        gap: 8px;
    }
    .recipe-production-batch {
        display: block;
        padding: 11px 12px;
        border: 1px solid #e2e9f0;
        border-radius: 8px;
        color: #172235;
        background: #fff;
    }
    .recipe-production-batch:hover,
    .recipe-production-batch.active {
        color: #fff;
        text-decoration: none;
        background: #24517a;
        border-color: #24517a;
    }
    .recipe-production-muted {
        color: #6b7787;
        font-size: 12px;
    }
    .recipe-production-batch.active .recipe-production-muted,
    .recipe-production-batch:hover .recipe-production-muted {
        color: #dce8f3;
    }
    .recipe-production-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }
    .recipe-production-metric {
        border: 1px solid #e1e8f0;
        border-radius: 8px;
        padding: 10px;
        min-height: 72px;
        background: #fbfcfe;
    }
    .recipe-production-metric span {
        display: block;
        color: #697789;
        font-size: 12px;
        margin-bottom: 5px;
    }
    .recipe-production-metric strong {
        color: #172235;
        font-size: 14px;
        font-weight: 700;
        word-break: break-word;
    }
    .recipe-production-action-grid {
        display: grid;
        grid-template-columns: 1.35fr 1fr;
        gap: 12px;
        margin-top: 14px;
    }
    .recipe-production-action {
        border: 1px solid #dce5ef;
        border-radius: 8px;
        padding: 13px;
        background: #fbfcfe;
    }
    .recipe-production-table th {
        white-space: nowrap;
        color: #526173;
        font-size: 12px;
    }
    .recipe-production-table td {
        vertical-align: middle;
    }
    .recipe-production-shortage {
        background: #fff7f7;
    }
    .recipe-production-ok {
        color: #167343;
        font-weight: 700;
    }
    .recipe-production-bad {
        color: #b3261e;
        font-weight: 700;
    }
    @media (max-width: 991px) {
        .recipe-production-grid,
        .recipe-production-action-grid {
            grid-template-columns: 1fr;
        }
        .recipe-production-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .recipe-production-header {
            align-items: flex-start;
            flex-direction: column;
        }
    }
    @media (max-width: 575px) {
        .recipe-production-wrap {
            padding: 10px;
        }
        .recipe-production-form-grid,
        .recipe-production-summary {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="content-wrapper recipe-production-page">
    <section class="content-header">
        <div class="recipe-production-wrap">
            <div class="recipe-production-header">
                <div>
                    <h1 class="recipe-production-title">تشغيل الإنتاج</h1>
                    <p class="recipe-production-subtitle">تحويل مكونات الوصفة إلى صنف مجهز مع معاينة الرصيد والتكلفة قبل التثبيت.</p>
                </div>
                <div class="recipe-production-mode">
                    <i class="fas fa-industry" aria-hidden="true"></i>
                    <span>الوضع: <?= posmain_recipe_production_h($recipeProductionMode) ?></span>
                </div>
            </div>

            <?php if ($recipeProductionFlash): ?>
                <div class="alert alert-<?= posmain_recipe_production_h($recipeProductionFlash['type']) ?> mt-3">
                    <?= posmain_recipe_production_h($recipeProductionFlash['message']) ?>
                </div>
            <?php endif; ?>

            <?php if (!$recipeProductionWritesEnabled): ?>
                <div class="alert alert-warning mt-3">
                    أوامر الإنتاج متوقفة من إعدادات الوصفات الحالية. الوضع: <?= posmain_recipe_production_h($recipeProductionMode) ?>.
                </div>
            <?php endif; ?>

            <div class="recipe-production-grid">
                <aside>
                    <div class="recipe-production-panel">
                        <div class="recipe-production-panel-head">
                            <h2 class="recipe-production-panel-title">مسودة إنتاج جديدة</h2>
                        </div>
                        <div class="recipe-production-panel-body">
                            <form method="POST">
                                <?= csrf_input('recipe_production') ?>
                                <input type="hidden" name="action" value="create_draft">
                                <div class="mb-3">
                                    <label class="recipe-production-label">الوصفة / الصنف الناتج</label>
                                    <input id="recipeProductionRecipeSearch" class="form-control recipe-production-search" type="search" autocomplete="off" placeholder="ابحث باسم الوصفة أو الصنف الناتج">
                                    <select id="recipeProductionRecipeSelect" name="recipe_id" class="form-control" required>
                                        <option value="">اختر وصفة إنتاج نشطة</option>
                                        <?php foreach ($recipeProductionRecipes as $recipe): ?>
                                            <?php $recipeProductionRecipeLabel = posmain_recipe_production_recipe_label($recipe); ?>
                                            <option value="<?= (int) $recipe['id'] ?>" data-search="<?= posmain_recipe_production_h($recipeProductionRecipeLabel . ' ' . ($recipe['output_item_name'] ?? '') . ' ' . ($recipe['recipe_name'] ?? '')) ?>">
                                                <?= posmain_recipe_production_h($recipeProductionRecipeLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="recipeProductionRecipeFilterNote" class="recipe-production-filter-note"></div>
                                </div>
                                <div class="recipe-production-form-grid">
                                    <div class="mb-3">
                                        <label class="recipe-production-label">الكمية المخططة</label>
                                        <input type="text" name="planned_output_qty" class="form-control" value="1.000000" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="recipe-production-label">المخزن</label>
                                        <?php if ($recipeProductionStores): ?>
                                            <select name="store_id" class="form-control">
                                                <?php foreach ($recipeProductionStores as $store): ?>
                                                    <option value="<?= (int) $store['id'] ?>"><?= posmain_recipe_production_h($store['aname'] ?? '') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="number" name="store_id" class="form-control" min="0" value="0">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="recipe-production-label">ملاحظات</label>
                                    <input type="text" name="notes" class="form-control" maxlength="500">
                                </div>
                                <button type="submit" class="btn btn-primary btn-block" <?= ($recipeProductionWritesEnabled && $canManageProduction) ? '' : 'disabled' ?>>
                                    إنشاء مسودة
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="recipe-production-panel">
                        <div class="recipe-production-panel-head">
                            <h2 class="recipe-production-panel-title">دفعات الإنتاج</h2>
                        </div>
                        <div class="recipe-production-panel-body">
                            <form method="GET" class="mb-3">
                                <div class="mb-2">
                                    <input type="text" name="q" class="form-control" value="<?= posmain_recipe_production_h($recipeProductionFilters['q']) ?>" placeholder="بحث باسم الوصفة أو الصنف">
                                </div>
                                <div class="recipe-production-form-grid">
                                    <div class="mb-2">
                                        <select name="status" class="form-control">
                                            <?= posmain_recipe_production_options(['' => 'كل الحالات', 'draft' => 'مسودة', 'committed' => 'مثبتة', 'cancelled' => 'ملغاة'], $recipeProductionFilters['status']) ?>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <?php if ($recipeProductionStores): ?>
                                            <select name="store_id" class="form-control">
                                                <option value="">كل المخازن</option>
                                                <?php foreach ($recipeProductionStores as $store): ?>
                                                    <option value="<?= (int) $store['id'] ?>" <?= $recipeProductionFilters['store_id'] === (int) $store['id'] ? 'selected' : '' ?>>
                                                        <?= posmain_recipe_production_h($store['aname'] ?? '') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="number" name="store_id" class="form-control" min="0" value="<?= $recipeProductionFilters['store_id'] >= 0 ? (int) $recipeProductionFilters['store_id'] : '' ?>" placeholder="المخزن">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <button class="btn btn-outline-secondary btn-block" type="submit">تصفية</button>
                            </form>

                            <div class="recipe-production-list">
                                <?php foreach ($recipeProductionRows as $row): ?>
                                    <a class="recipe-production-batch <?= (int) $row['id'] === $selectedBatchId ? 'active' : '' ?>" href="<?= posmain_recipe_production_batch_url($recipeProductionFilters, (int) $row['id']) ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?= posmain_recipe_production_h($row['recipe_name'] ?? ('وصفة ' . (int) ($row['recipe_id'] ?? 0))) ?></strong>
                                            <span>#<?= (int) $row['id'] ?></span>
                                        </div>
                                        <div class="recipe-production-muted">
                                            <?= posmain_recipe_production_status_text($row['status'] ?? '') ?> / مخطط <?= posmain_recipe_production_qty($row['planned_output_qty'] ?? '') ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (!$recipeProductionRows): ?>
                                    <div class="text-muted">لا توجد دفعات مطابقة.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </aside>

                <main>
                    <?php if ($selectedBatch): ?>
                        <div class="recipe-production-panel">
                            <div class="recipe-production-panel-head">
                                <h2 class="recipe-production-panel-title">دفعة #<?= (int) $selectedBatchHeader['id'] ?></h2>
                                <?= posmain_recipe_production_status_badge($selectedBatchHeader['status'] ?? '') ?>
                            </div>
                            <div class="recipe-production-panel-body">
                                <div class="recipe-production-summary">
                                    <div class="recipe-production-metric">
                                        <span>الوصفة</span>
                                        <strong><?= posmain_recipe_production_h($selectedBatchHeader['recipe_name'] ?? '') ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>الصنف الناتج</span>
                                        <strong><?= posmain_recipe_production_h($selectedBatchHeader['output_item_name'] ?? ('صنف ' . (int) ($selectedBatchHeader['output_item_id'] ?? 0))) ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>المخزن</span>
                                        <strong><?= posmain_recipe_production_h(posmain_recipe_production_store_name($recipeProductionStoreNames, (int) ($selectedBatchHeader['store_id'] ?? 0))) ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>الكمية المخططة</span>
                                        <strong><?= posmain_recipe_production_qty($selectedBatchHeader['planned_output_qty'] ?? '') ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>الكمية الفعلية</span>
                                        <strong><?= posmain_recipe_production_qty($selectedBatchHeader['actual_output_qty'] ?? '') ?: '-' ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>وقت التثبيت</span>
                                        <strong><?= posmain_recipe_production_h($selectedBatchHeader['committed_at'] ?? '-') ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>سبب الفرق</span>
                                        <strong><?= posmain_recipe_production_h($selectedBatchHeader['variance_reason'] ?? '-') ?></strong>
                                    </div>
                                    <div class="recipe-production-metric">
                                        <span>مرجع الدفعة</span>
                                        <strong><?= posmain_recipe_production_h($selectedBatchHeader['batch_uuid'] ?? '') ?></strong>
                                    </div>
                                </div>

                                <?php if ($selectedIsDraft): ?>
                                    <div class="recipe-production-action-grid">
                                        <form method="POST" class="recipe-production-action">
                                            <?= csrf_input('recipe_production') ?>
                                            <input type="hidden" name="action" value="commit">
                                            <input type="hidden" name="batch_id" value="<?= (int) $selectedBatchHeader['id'] ?>">
                                            <div class="recipe-production-form-grid">
                                                <div class="mb-2">
                                                    <label class="recipe-production-label">الناتج الفعلي</label>
                                                    <input type="text" name="actual_output_qty" class="form-control" value="<?= posmain_recipe_production_h($selectedBatchHeader['planned_output_qty'] ?? '1.000000') ?>" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="recipe-production-label">سبب الفرق</label>
                                                    <input type="text" name="variance_reason" class="form-control" maxlength="255" placeholder="مطلوب عند اختلاف الناتج الفعلي">
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-success" <?= ($recipeProductionWritesEnabled && $canCommitProduction) ? '' : 'disabled' ?>>
                                                تأكيد الإنتاج
                                            </button>
                                        </form>

                                        <form method="POST" class="recipe-production-action">
                                            <?= csrf_input('recipe_production') ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="batch_id" value="<?= (int) $selectedBatchHeader['id'] ?>">
                                            <div class="mb-2">
                                                <label class="recipe-production-label">سبب الإلغاء</label>
                                                <input type="text" name="cancel_reason" class="form-control" maxlength="255">
                                            </div>
                                            <button type="submit" class="btn btn-outline-danger" <?= ($recipeProductionWritesEnabled && $canManageProduction) ? '' : 'disabled' ?>>
                                                إلغاء المسودة
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($productionPreview): ?>
                            <div class="recipe-production-panel">
                                <div class="recipe-production-panel-head">
                                    <h2 class="recipe-production-panel-title">معاينة المدخلات</h2>
                                    <span class="recipe-production-muted">الرصيد المتاح = الموجود - المحجوز</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped mb-0 recipe-production-table">
                                        <thead>
                                            <tr>
                                                <th>الصنف الداخل</th>
                                                <th class="text-right">المطلوب</th>
                                                <th class="text-right">الموجود</th>
                                                <th class="text-right">المحجوز</th>
                                                <th class="text-right">المتاح</th>
                                                <th class="text-right">العجز</th>
                                                <?php if ($canViewProductionCost): ?>
                                                    <th class="text-right">تكلفة الوحدة</th>
                                                    <th class="text-right">إجمالي التكلفة</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($productionPreview['requirements'] as $requirement): ?>
                                                <tr class="<?= !empty($requirement['has_shortage']) ? 'recipe-production-shortage' : '' ?>">
                                                    <td><?= posmain_recipe_production_h($requirement['item_name'] ?? ('صنف ' . (int) ($requirement['ingredient_item_id'] ?? 0))) ?></td>
                                                    <td class="text-right"><?= posmain_recipe_production_qty($requirement['required_qty_base'] ?? '') ?></td>
                                                    <td class="text-right"><?= posmain_recipe_production_qty($requirement['qty_on_hand'] ?? '') ?></td>
                                                    <td class="text-right"><?= posmain_recipe_production_qty($requirement['qty_reserved'] ?? '') ?></td>
                                                    <td class="text-right"><?= posmain_recipe_production_qty($requirement['available_qty'] ?? '') ?></td>
                                                    <td class="text-right">
                                                        <?php if (!empty($requirement['has_shortage'])): ?>
                                                            <span class="recipe-production-bad"><?= posmain_recipe_production_qty($requirement['short_qty'] ?? '') ?></span>
                                                        <?php else: ?>
                                                            <span class="recipe-production-ok">متاح</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($canViewProductionCost): ?>
                                                        <td class="text-right"><?= posmain_recipe_production_money($requirement['unit_cost'] ?? '') ?></td>
                                                        <td class="text-right"><?= posmain_recipe_production_money($requirement['total_cost'] ?? '') ?></td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <?php if ($canViewProductionCost): ?>
                                            <tfoot>
                                                <tr>
                                                    <th colspan="7" class="text-right">إجمالي تكلفة المدخلات</th>
                                                    <th class="text-right"><?= posmain_recipe_production_money($productionPreview['total_input_cost'] ?? '') ?></th>
                                                </tr>
                                            </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        <?php elseif ($productionPreviewError): ?>
                            <div class="alert alert-warning mt-3"><?= posmain_recipe_production_h($productionPreviewError) ?></div>
                        <?php endif; ?>

                        <div class="recipe-production-panel">
                            <div class="recipe-production-panel-head">
                                <h2 class="recipe-production-panel-title">حركات الإنتاج المثبتة</h2>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 recipe-production-table">
                                    <thead>
                                        <tr>
                                            <th>النوع</th>
                                            <th>الصنف</th>
                                            <th class="text-right">المخطط</th>
                                            <th class="text-right">الفعلي</th>
                                            <?php if ($canViewProductionCost): ?>
                                                <th class="text-right">التكلفة</th>
                                            <?php endif; ?>
                                            <th>حركة المخزون</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($selectedBatch['lines'] as $line): ?>
                                            <tr>
                                                <td><?= posmain_recipe_production_h(posmain_recipe_production_line_type($line['line_type'] ?? '')) ?></td>
                                                <td><?= posmain_recipe_production_h($line['item_name'] ?? ('صنف ' . (int) ($line['item_id'] ?? 0))) ?></td>
                                                <td class="text-right"><?= posmain_recipe_production_qty($line['planned_qty'] ?? '') ?></td>
                                                <td class="text-right"><?= posmain_recipe_production_qty($line['actual_qty'] ?? '') ?></td>
                                                <?php if ($canViewProductionCost): ?>
                                                    <td class="text-right"><?= posmain_recipe_production_money($line['total_cost'] ?? '') ?></td>
                                                <?php endif; ?>
                                                <td><?= posmain_recipe_production_h($line['inventory_movement_id'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (!$selectedBatch['lines']): ?>
                                <div class="recipe-production-panel-body text-muted">لم يتم تثبيت حركات لهذه الدفعة بعد.</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="recipe-production-panel">
                            <div class="recipe-production-panel-body text-muted">اختر دفعة إنتاج لمعاينة المدخلات أو تثبيتها.</div>
                        </div>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const search = document.getElementById('recipeProductionRecipeSearch');
    const select = document.getElementById('recipeProductionRecipeSelect');
    const note = document.getElementById('recipeProductionRecipeFilterNote');
    if (!search || !select || !note) {
        return;
    }

    search.addEventListener('input', function () {
        const term = String(search.value || '').trim().toLowerCase();
        let visibleCount = 0;
        Array.from(select.options).forEach(function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            const haystack = String(option.dataset.search || option.textContent || '').toLowerCase();
            const matches = term === '' || haystack.indexOf(term) !== -1;
            option.hidden = !matches;
            if (matches) {
                visibleCount += 1;
            }
        });
        note.textContent = term === '' ? '' : 'نتائج مطابقة: ' + visibleCount;
    });
});
</script>

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

function posmain_recipe_production_stock_stores(mysqli $conn): array
{
    if (!posmain_recipe_production_table_exists($conn, 'acc_head')
        || !posmain_recipe_production_column_exists($conn, 'acc_head', 'is_stock')) {
        return [];
    }

    $result = $conn->query("
        SELECT id, aname
        FROM acc_head
        WHERE isdeleted = 0
          AND is_stock = 1
        ORDER BY aname
        LIMIT 100
    ");
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function posmain_recipe_production_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS table_count
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['table_count'] ?? 0) > 0;
}

function posmain_recipe_production_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['column_count'] ?? 0) > 0;
}

function posmain_recipe_production_recipe_label(array $recipe): string
{
    $recipeName = trim((string) ($recipe['recipe_name'] ?? ''));
    $outputName = trim((string) ($recipe['output_item_name'] ?? ''));
    $version = (int) ($recipe['version_number'] ?? 0);
    $yield = posmain_recipe_production_qty($recipe['yield_qty'] ?? '');
    $label = $recipeName !== '' ? $recipeName : 'وصفة ' . (int) ($recipe['id'] ?? 0);
    if ($outputName !== '') {
        $label .= ' / ' . $outputName;
    }
    if ($version > 0) {
        $label .= ' / نسخة ' . $version;
    }
    if ($yield !== '') {
        $label .= ' / إنتاجية ' . $yield;
    }

    return $label;
}

function posmain_recipe_production_store_name(array $storeNames, int $storeId): string
{
    if (isset($storeNames[$storeId]) && $storeNames[$storeId] !== '') {
        return $storeNames[$storeId];
    }

    return $storeId > 0 ? 'مخزن #' . $storeId : 'مخزن عام';
}

function posmain_recipe_production_status_text($status): string
{
    $status = strtolower(trim((string) $status));
    if ($status === 'draft') {
        return 'مسودة';
    }
    if ($status === 'committed') {
        return 'مثبتة';
    }
    if ($status === 'cancelled') {
        return 'ملغاة';
    }

    return 'غير معروف';
}

function posmain_recipe_production_status_badge($status): string
{
    $status = strtolower(trim((string) $status));
    $class = $status === 'committed' ? 'success' : ($status === 'draft' ? 'warning text-dark' : 'secondary');

    return '<span class="badge bg-' . $class . '">' . posmain_recipe_production_h(posmain_recipe_production_status_text($status)) . '</span>';
}

function posmain_recipe_production_line_type($type): string
{
    $type = strtolower(trim((string) $type));
    if ($type === 'input') {
        return 'مدخل';
    }
    if ($type === 'output') {
        return 'ناتج';
    }
    if ($type === 'variance') {
        return 'فرق إنتاج';
    }

    return $type !== '' ? $type : '-';
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
