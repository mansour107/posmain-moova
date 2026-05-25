<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/csv_export.php';
require_once __DIR__ . '/classes/Recipe/RecipeOperationsReportService.php';

require_login();
if (!posmain_recipe_operations_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeOperationsCanViewCost = posmain_recipe_operations_can_view_cost($conn);
$recipeOperationsReportTypes = posmain_recipe_operations_report_types($recipeOperationsCanViewCost);
$recipeOperationsReport = posmain_recipe_operations_report_key($_GET['report'] ?? '', $recipeOperationsReportTypes);
$recipeOperationsFilters = posmain_recipe_operations_filters($_GET);
$recipeOperationsService = new RecipeOperationsReportService();
$recipeOperationsRows = $recipeOperationsService->report($conn, $recipeOperationsReport, $recipeOperationsFilters);
$recipeOperationsColumns = posmain_recipe_operations_columns($recipeOperationsReport, $recipeOperationsCanViewCost);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    posmain_recipe_operations_export_csv($recipeOperationsReport, $recipeOperationsColumns, $recipeOperationsRows);
    exit;
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
                    <h3 class="card-title">Recipe Operations Reports</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label>Report</label>
                            <select name="report" class="form-control">
                                <?php foreach ($recipeOperationsReportTypes as $key => $label): ?>
                                    <option value="<?= posmain_recipe_operations_h($key) ?>" <?= $recipeOperationsReport === $key ? 'selected' : '' ?>>
                                        <?= posmain_recipe_operations_h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= posmain_recipe_operations_h($recipeOperationsFilters['date_from'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= posmain_recipe_operations_h($recipeOperationsFilters['date_to'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" min="0" value="<?= $recipeOperationsFilters['pos_tenant'] >= 0 ? (int) $recipeOperationsFilters['pos_tenant'] : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" min="0" value="<?= $recipeOperationsFilters['pos_branch'] >= 0 ? (int) $recipeOperationsFilters['pos_branch'] : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Store</label>
                            <input type="number" name="store_id" class="form-control" min="0" value="<?= $recipeOperationsFilters['store_id'] >= 0 ? (int) $recipeOperationsFilters['store_id'] : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Recipe</label>
                            <input type="number" name="recipe_id" class="form-control" min="1" value="<?= $recipeOperationsFilters['recipe_id'] > 0 ? (int) $recipeOperationsFilters['recipe_id'] : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Item</label>
                            <input type="number" name="sellable_item_id" class="form-control" min="1" value="<?= $recipeOperationsFilters['sellable_item_id'] > 0 ? (int) $recipeOperationsFilters['sellable_item_id'] : '' ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Ingredient</label>
                            <input type="number" name="ingredient_item_id" class="form-control" min="1" value="<?= $recipeOperationsFilters['ingredient_item_id'] > 0 ? (int) $recipeOperationsFilters['ingredient_item_id'] : '' ?>">
                        </div>
                        <div class="col-md-1 mt-3">
                            <label>Modifier</label>
                            <input type="number" name="modifier_option_id" class="form-control" min="1" value="<?= $recipeOperationsFilters['modifier_option_id'] > 0 ? (int) $recipeOperationsFilters['modifier_option_id'] : '' ?>">
                        </div>
                        <div class="col-md-2 mt-3">
                            <label>Low Threshold</label>
                            <input type="text" name="low_stock_threshold" class="form-control" value="<?= posmain_recipe_operations_h($recipeOperationsFilters['low_stock_threshold']) ?>">
                        </div>
                        <div class="col-md-2 mt-3">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" min="1" max="5000" value="<?= (int) $recipeOperationsFilters['limit'] ?>">
                        </div>
                        <div class="col-md-3 mt-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="variance_only" value="1" id="varianceOnly" <?= !empty($recipeOperationsFilters['variance_only']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="varianceOnly">Production variance only</label>
                            </div>
                        </div>
                        <div class="col-md-5 mt-4 d-flex">
                            <button type="submit" class="btn btn-primary mr-2">Run Report</button>
                            <button type="submit" name="export" value="csv" class="btn btn-outline-secondary">Export CSV</button>
                        </div>
                    </form>

                    <div class="alert alert-info">
                        This page is read-only and uses the recipe ledger, production, availability cache, and cost snapshot tables.
                        <?php if (!$recipeOperationsCanViewCost): ?>
                            Cost and margin columns are hidden for this session.
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" data-page-length="50">
                            <thead class="thead-dark">
                                <tr>
                                    <?php foreach ($recipeOperationsColumns as $column => $label): ?>
                                        <th><?= posmain_recipe_operations_h($label) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipeOperationsRows as $row): ?>
                                    <tr>
                                        <?php foreach ($recipeOperationsColumns as $column => $label): ?>
                                            <td><?= posmain_recipe_operations_h(posmain_recipe_operations_cell($row[$column] ?? '')) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$recipeOperationsRows): ?>
                        <div class="alert alert-secondary mt-3">No recipe operations rows matched the selected filters.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_operations_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('reports.view', $conn)
        || posmain_recipe_can_view_sensitive_reports($conn);
}

function posmain_recipe_operations_can_view_cost(mysqli $conn): bool
{
    return posmain_recipe_can_view_costs($conn);
}

function posmain_recipe_operations_report_types(bool $canViewCost = true): array
{
    $types = [
        'ingredient_consumption' => 'Ingredient consumption',
        'production_variance' => 'Production variance',
        'low_stock_impact' => 'Low stock affected items',
        'expected_vs_actual_usage' => 'Expected vs actual usage',
    ];

    if ($canViewCost) {
        return [
            'cost_history' => 'Recipe cost history',
            'ingredient_consumption' => $types['ingredient_consumption'],
            'recipe_cogs' => 'Recipe COGS by item',
            'production_variance' => $types['production_variance'],
            'low_stock_impact' => $types['low_stock_impact'],
            'cogs_reconciliation' => 'COGS journal reconciliation',
            'expected_vs_actual_usage' => $types['expected_vs_actual_usage'],
            'modifier_revenue_cost' => 'Modifier revenue vs cost',
        ];
    }

    return $types;
}

function posmain_recipe_operations_report_key($value, array $reportTypes): string
{
    $key = strtolower(trim((string) $value));

    return isset($reportTypes[$key]) ? $key : 'ingredient_consumption';
}

function posmain_recipe_operations_filters(array $request): array
{
    return [
        'date_from' => posmain_recipe_operations_date($request['date_from'] ?? ''),
        'date_to' => posmain_recipe_operations_date($request['date_to'] ?? ''),
        'pos_tenant' => isset($request['pos_tenant']) && $request['pos_tenant'] !== '' ? max(0, (int) $request['pos_tenant']) : -1,
        'pos_branch' => isset($request['pos_branch']) && $request['pos_branch'] !== '' ? max(0, (int) $request['pos_branch']) : -1,
        'store_id' => isset($request['store_id']) && $request['store_id'] !== '' ? max(0, (int) $request['store_id']) : -1,
        'recipe_id' => isset($request['recipe_id']) && (int) $request['recipe_id'] > 0 ? (int) $request['recipe_id'] : 0,
        'sellable_item_id' => isset($request['sellable_item_id']) && (int) $request['sellable_item_id'] > 0 ? (int) $request['sellable_item_id'] : 0,
        'ingredient_item_id' => isset($request['ingredient_item_id']) && (int) $request['ingredient_item_id'] > 0 ? (int) $request['ingredient_item_id'] : 0,
        'modifier_option_id' => isset($request['modifier_option_id']) && (int) $request['modifier_option_id'] > 0 ? (int) $request['modifier_option_id'] : 0,
        'low_stock_threshold' => posmain_recipe_operations_decimal($request['low_stock_threshold'] ?? '5'),
        'variance_only' => isset($request['variance_only']) && (string) $request['variance_only'] === '1',
        'limit' => max(1, min(5000, (int) ($request['limit'] ?? 500))),
    ];
}

function posmain_recipe_operations_columns(string $report, bool $canViewCost = true): array
{
    $columns = [
        'cost_history' => [
            'calculated_at' => 'Calculated',
            'recipe_name' => 'Recipe',
            'sellable_item_name' => 'Item',
            'version_number' => 'Version',
            'cost_per_yield' => 'Cost/Yield',
            'cost_per_sell_unit' => 'Cost/Sell Unit',
        ],
        'ingredient_consumption' => [
            'item_name' => 'Ingredient',
            'movement_type' => 'Movement',
            'qty_consumed' => 'Qty Consumed',
            'total_cost' => 'Total Cost',
            'movement_count' => 'Rows',
            'last_movement_at' => 'Last Movement',
        ],
        'recipe_cogs' => [
            'recipe_name' => 'Recipe',
            'sellable_item_name' => 'Item',
            'recipe_cogs' => 'Recipe COGS',
            'ingredient_qty_out' => 'Ingredient Qty',
            'order_count' => 'Orders',
            'movement_count' => 'Rows',
            'last_consumed_at' => 'Last Consumed',
        ],
        'production_variance' => [
            'committed_at' => 'Committed',
            'recipe_name' => 'Recipe',
            'output_item_name' => 'Output Item',
            'planned_output_qty' => 'Planned',
            'actual_output_qty' => 'Actual',
            'variance_qty' => 'Variance Qty',
            'variance_percent' => 'Variance %',
            'variance_reason' => 'Reason',
            'input_cost' => 'Input Cost',
        ],
        'low_stock_impact' => [
            'sellable_item_name' => 'Menu Item',
            'recipe_name' => 'Recipe',
            'effective_available_qty' => 'Available Qty',
            'effective_is_available' => 'Available',
            'blocking_item_name' => 'Blocking Item',
            'unavailable_reason' => 'Reason',
            'availability_revision' => 'Revision',
            'updated_at' => 'Updated',
        ],
        'cogs_reconciliation' => [
            'order_id' => 'Order',
            'accounting_journal_id' => 'Journal',
            'recipe_names' => 'Recipes',
            'sellable_item_names' => 'Items',
            'movement_total' => 'Movement Cost',
            'journal_debit_total' => 'Journal Debit',
            'journal_credit_total' => 'Journal Credit',
            'debit_difference' => 'Debit Diff',
            'credit_difference' => 'Credit Diff',
            'reconciliation_status' => 'Status',
        ],
        'expected_vs_actual_usage' => [
            'order_id' => 'Order',
            'usage_id' => 'Usage',
            'recipe_name' => 'Recipe',
            'sellable_item_name' => 'Item',
            'ingredient_item_name' => 'Ingredient',
            'line_types' => 'Line Types',
            'expected_qty' => 'Expected Qty',
            'actual_qty' => 'Actual Qty',
            'qty_difference' => 'Qty Diff',
            'expected_cost' => 'Expected Cost',
            'actual_cost' => 'Actual Cost',
            'reconciliation_status' => 'Status',
        ],
        'modifier_revenue_cost' => [
            'order_id' => 'Order',
            'usage_id' => 'Usage',
            'recipe_name' => 'Recipe',
            'sellable_item_name' => 'Item',
            'modifier_option_name' => 'Modifier',
            'modifier_qty' => 'Modifier Qty',
            'modifier_revenue' => 'Revenue',
            'modifier_ingredient_cost' => 'Ingredient Cost',
            'modifier_margin' => 'Margin',
            'modifier_margin_percent' => 'Margin %',
            'ingredient_item_names' => 'Ingredients',
            'reconciliation_status' => 'Status',
        ],
    ];

    if ($canViewCost) {
        return $columns[$report] ?? $columns['ingredient_consumption'];
    }

    $safeColumns = $columns[$report] ?? $columns['ingredient_consumption'];
    foreach ([
        'cost_per_yield',
        'cost_per_sell_unit',
        'total_cost',
        'recipe_cogs',
        'input_cost',
        'movement_total',
        'journal_debit_total',
        'journal_credit_total',
        'debit_difference',
        'credit_difference',
        'expected_cost',
        'actual_cost',
        'cost_difference',
        'modifier_ingredient_cost',
        'modifier_margin',
        'modifier_margin_percent',
    ] as $costColumn) {
        unset($safeColumns[$costColumn]);
    }

    return $safeColumns;
}

function posmain_recipe_operations_date($value): string
{
    $text = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
}

function posmain_recipe_operations_decimal($value): string
{
    $text = trim((string) $value);

    return preg_match('/^\d+(\.\d{1,6})?$/', $text) === 1 ? $text : '5';
}

function posmain_recipe_operations_export_csv(string $report, array $columns, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recipe_' . $report . '.csv"');
    $out = fopen('php://output', 'w');
    posmain_csv_write_row($out, array_values($columns));
    foreach ($rows as $row) {
        $values = [];
        foreach (array_keys($columns) as $column) {
            $values[] = $row[$column] ?? '';
        }
        posmain_csv_write_row($out, posmain_csv_safe_row($values));
    }
    fclose($out);
}

function posmain_recipe_operations_cell($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_numeric($value) && strpos((string) $value, '.') !== false) {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }

    return (string) $value;
}

function posmain_recipe_operations_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
