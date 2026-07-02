<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/csv_export.php';

require_login();
if (!posmain_recipe_reconciliation_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

require_once __DIR__ . '/classes/Recipe/RecipeReconciliationService.php';

$recipeReconciliationMovementTypes = [
    '',
    'purchase',
    'sale_direct',
    'recipe_consumption',
    'production_input',
    'production_output',
    'waste',
    'adjustment',
    'transfer_in',
    'transfer_out',
    'reservation',
    'reservation_release',
    'refund_reversal',
    'sync_replay',
    'opening_balance',
];
$recipeReconciliationSourceTypes = [
    '',
    'order',
    'order_line',
    'invoice',
    'fat_details',
    'recipe',
    'recipe_order_line_usage',
    'production_batch',
    'purchase_invoice',
    'adjustment',
    'reservation',
    'sync_event',
    'manual',
];

$recipeReconciliationFilters = posmain_recipe_reconciliation_filters_from_request(
    $_GET,
    $recipeReconciliationMovementTypes,
    $recipeReconciliationSourceTypes
);
$recipeReconciliationRows = (new RecipeReconciliationService())->report($conn, $recipeReconciliationFilters);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    posmain_recipe_reconciliation_export_csv($recipeReconciliationRows);
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
                    <h3 class="card-title">Recipe Stock Reconciliation</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label>From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= posmain_recipe_reconciliation_h($recipeReconciliationFilters['date_from'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= posmain_recipe_reconciliation_h($recipeReconciliationFilters['date_to'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" value="<?= (int) ($recipeReconciliationFilters['pos_tenant'] ?? 0) ?>" min="0">
                        </div>
                        <div class="col-md-1">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" value="<?= (int) ($recipeReconciliationFilters['pos_branch'] ?? 0) ?>" min="0">
                        </div>
                        <div class="col-md-1">
                            <label>Store</label>
                            <input type="number" name="store_id" class="form-control" value="<?= (int) ($recipeReconciliationFilters['store_id'] ?? 0) ?>" min="0">
                        </div>
                        <div class="col-md-1">
                            <label>Item ID</label>
                            <input type="number" name="item_id" class="form-control" value="<?= posmain_recipe_reconciliation_h($_GET['item_id'] ?? '') ?>" min="1">
                        </div>
                        <div class="col-md-2">
                            <label>Movement</label>
                            <select name="movement_type" class="form-control">
                                <?php foreach ($recipeReconciliationMovementTypes as $type): ?>
                                    <option value="<?= posmain_recipe_reconciliation_h($type) ?>" <?= (($recipeReconciliationFilters['movement_type'] ?? '') === $type) ? 'selected' : '' ?>>
                                        <?= $type === '' ? 'All' : posmain_recipe_reconciliation_h($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Source</label>
                            <select name="source_type" class="form-control">
                                <?php foreach ($recipeReconciliationSourceTypes as $type): ?>
                                    <option value="<?= posmain_recipe_reconciliation_h($type) ?>" <?= (($recipeReconciliationFilters['source_type'] ?? '') === $type) ? 'selected' : '' ?>>
                                        <?= $type === '' ? 'All' : posmain_recipe_reconciliation_h($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mt-3">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" value="<?= (int) ($recipeReconciliationFilters['limit'] ?? 1000) ?>" min="1" max="5000">
                        </div>
                        <div class="col-md-3 mt-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="differences_only" value="1" id="differencesOnly" <?= !empty($recipeReconciliationFilters['differences_only']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="differencesOnly">Show differences only</label>
                            </div>
                        </div>
                        <div class="col-md-4 mt-4 d-flex">
                            <button type="submit" class="btn btn-primary mr-2">Run Report</button>
                            <button type="submit" name="export" value="csv" class="btn btn-outline-secondary">Export CSV</button>
                        </div>
                    </form>

                    <div class="alert alert-info">
                        This report is read-only. It compares current legacy item quantity, legacy fat_details movement balance, recipe inventory_movements, and inventory_item_balances.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" data-page-length="50">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Item</th>
                                    <th>Barcode</th>
                                    <th class="text-center">Tenant</th>
                                    <th class="text-center">Branch</th>
                                    <th class="text-center">Store</th>
                                    <th class="text-center">Legacy Qty</th>
                                    <th class="text-center">fat_details Qty</th>
                                    <th class="text-center">Ledger Qty</th>
                                    <th class="text-center">Balance Qty</th>
                                    <th class="text-center">Legacy - fat_details</th>
                                    <th class="text-center">Ledger - Balance</th>
                                    <th class="text-center">Legacy - Ledger</th>
                                    <th class="text-center">Last Movement</th>
                                    <th>Recommended Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipeReconciliationRows as $row): ?>
                                    <tr class="<?= !empty($row['has_difference']) ? 'table-warning' : '' ?>">
                                        <td><?= posmain_recipe_reconciliation_h($row['item_name'] !== '' ? $row['item_name'] : (string) $row['item_id']) ?></td>
                                        <td><?= posmain_recipe_reconciliation_h($row['item_barcode'] ?? '') ?></td>
                                        <td class="text-center"><?= (int) $row['pos_tenant'] ?></td>
                                        <td class="text-center"><?= (int) $row['pos_branch'] ?></td>
                                        <td class="text-center"><?= (int) $row['store_id'] ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['legacy_qty']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['fat_details_qty']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['ledger_qty']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['balance_qty']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['legacy_vs_fat_difference']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['ledger_vs_balance_difference']) ?></td>
                                        <td class="text-center"><?= posmain_recipe_reconciliation_h($row['legacy_vs_ledger_difference']) ?></td>
                                        <td class="text-center"><?= $row['last_movement_id'] ? (int) $row['last_movement_id'] : '' ?></td>
                                        <td><?= posmain_recipe_reconciliation_h($row['recommended_action']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$recipeReconciliationRows): ?>
                        <div class="alert alert-secondary mt-3">No reconciliation rows matched the selected filters.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_reconciliation_can_view(mysqli $conn): bool
{
    return auth_guard_has_permission('reports.view', $conn)
        || posmain_recipe_can_view_sensitive_reports($conn);
}

function posmain_recipe_reconciliation_filters_from_request(array $request, array $movementTypes, array $sourceTypes): array
{
    $itemId = isset($request['item_id']) ? (int) $request['item_id'] : 0;
    $movementType = in_array((string) ($request['movement_type'] ?? ''), $movementTypes, true)
        ? (string) ($request['movement_type'] ?? '')
        : '';
    $sourceType = in_array((string) ($request['source_type'] ?? ''), $sourceTypes, true)
        ? (string) ($request['source_type'] ?? '')
        : '';

    return [
        'pos_tenant' => max(0, (int) ($request['pos_tenant'] ?? 0)),
        'pos_branch' => max(0, (int) ($request['pos_branch'] ?? 0)),
        'store_id' => max(0, (int) ($request['store_id'] ?? 0)),
        'item_ids' => $itemId > 0 ? [$itemId] : [],
        'date_from' => posmain_recipe_reconciliation_date($request['date_from'] ?? ''),
        'date_to' => posmain_recipe_reconciliation_date($request['date_to'] ?? ''),
        'movement_type' => $movementType,
        'source_type' => $sourceType,
        'differences_only' => isset($request['differences_only']) && (string) $request['differences_only'] === '1',
        'limit' => max(1, min(5000, (int) ($request['limit'] ?? 1000))),
    ];
}

function posmain_recipe_reconciliation_date($value): string
{
    $text = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
}

function posmain_recipe_reconciliation_export_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recipe_stock_reconciliation.csv"');
    $out = fopen('php://output', 'w');
    posmain_csv_write_row($out, [
        'item_id',
        'item_barcode',
        'item_name',
        'pos_tenant',
        'pos_branch',
        'store_id',
        'legacy_qty',
        'fat_details_qty',
        'ledger_qty',
        'balance_qty',
        'legacy_vs_fat_difference',
        'ledger_vs_balance_difference',
        'legacy_vs_ledger_difference',
        'last_movement_id',
        'recommended_action',
    ]);
    foreach ($rows as $row) {
        posmain_csv_write_row($out, posmain_csv_safe_row([
            $row['item_id'],
            $row['item_barcode'],
            $row['item_name'],
            $row['pos_tenant'],
            $row['pos_branch'],
            $row['store_id'],
            $row['legacy_qty'],
            $row['fat_details_qty'],
            $row['ledger_qty'],
            $row['balance_qty'],
            $row['legacy_vs_fat_difference'],
            $row['ledger_vs_balance_difference'],
            $row['legacy_vs_ledger_difference'],
            $row['last_movement_id'],
            $row['recommended_action'],
        ]));
    }
    fclose($out);
}

function posmain_recipe_reconciliation_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
