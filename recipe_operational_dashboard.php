<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/classes/Recipe/RecipeOperationalDashboardService.php';

require_login();
if (!posmain_recipe_dashboard_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$recipeDashboardFilters = posmain_recipe_dashboard_filters($_GET);
$recipeDashboardFlags = new RecipeFeatureFlags();
$recipeDashboard = (new RecipeOperationalDashboardService())->dashboard($conn, $recipeDashboardFlags, $recipeDashboardFilters);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h3 class="card-title">Recipe Operational Dashboard</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" min="0" value="<?= $recipeDashboardFilters['pos_tenant'] >= 0 ? (int) $recipeDashboardFilters['pos_tenant'] : '' ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" min="0" value="<?= $recipeDashboardFilters['pos_branch'] >= 0 ? (int) $recipeDashboardFilters['pos_branch'] : '' ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Store</label>
                            <input type="number" name="store_id" class="form-control" min="0" value="<?= $recipeDashboardFilters['store_id'] >= 0 ? (int) $recipeDashboardFilters['store_id'] : '' ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" min="1" max="500" value="<?= (int) $recipeDashboardFilters['limit'] ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                        </div>
                    </form>

                    <div class="row">
                        <?php foreach (posmain_recipe_dashboard_summary_cards($recipeDashboard) as $card): ?>
                            <div class="col-md-3 mb-3">
                                <div class="small-box bg-<?= posmain_recipe_dashboard_h($card['class']) ?>">
                                    <div class="inner">
                                        <h3><?= posmain_recipe_dashboard_h($card['value']) ?></h3>
                                        <p><?= posmain_recipe_dashboard_h($card['label']) ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row">
                        <div class="col-lg-5">
                            <div class="card border-secondary">
                                <div class="card-header">
                                    <strong>Recipe Runtime Flags</strong>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm table-striped mb-0">
                                        <tbody>
                                            <?php foreach ($recipeDashboard['config'] as $key => $value): ?>
                                                <tr>
                                                    <th><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_label($key)) ?></th>
                                                    <td><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_value($value)) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="card border-secondary">
                                <div class="card-header">
                                    <strong>Health Checks</strong>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead>
                                            <tr>
                                                <th>Check</th>
                                                <th>Status</th>
                                                <th>Total</th>
                                                <th>Detail</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recipeDashboard['health'] as $check): ?>
                                                <tr class="<?= posmain_recipe_dashboard_row_class($check['severity'] ?? '') ?>">
                                                    <td><?= posmain_recipe_dashboard_h($check['label'] ?? '') ?></td>
                                                    <td><?= posmain_recipe_dashboard_h($check['status'] ?? '') ?></td>
                                                    <td><?= isset($check['total']) ? (int) $check['total'] : '' ?></td>
                                                    <td><?= posmain_recipe_dashboard_h($check['detail'] ?? '') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-secondary">
                        <div class="card-header">
                            <strong>Reconciliation Signals</strong>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-striped mb-0">
                                <tbody>
                                    <?php foreach ($recipeDashboard['last_reconciliation'] as $key => $value): ?>
                                        <tr>
                                            <th><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_label($key)) ?></th>
                                            <td><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_value($value)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php foreach ($recipeDashboard['sections'] as $section): ?>
                        <div class="card border-secondary mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <strong><?= posmain_recipe_dashboard_h($section['label'] ?? $section['key'] ?? '') ?></strong>
                                <span class="badge badge-<?= (($section['total'] ?? 0) > 0) ? 'warning' : 'success' ?>">
                                    <?= (int) ($section['total'] ?? 0) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <?php if (($section['status'] ?? '') === 'missing_schema'): ?>
                                    <div class="alert alert-secondary mb-0"><?= posmain_recipe_dashboard_h($section['message'] ?? 'Required table is not present.') ?></div>
                                <?php elseif (empty($section['rows'])): ?>
                                    <div class="alert alert-success mb-0">No open rows.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered table-striped">
                                            <thead>
                                                <tr>
                                                    <?php foreach (posmain_recipe_dashboard_columns($section['rows']) as $column): ?>
                                                        <th><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_label($column)) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($section['rows'] as $row): ?>
                                                    <tr>
                                                        <?php foreach (posmain_recipe_dashboard_columns($section['rows']) as $column): ?>
                                                            <td><?= posmain_recipe_dashboard_h(posmain_recipe_dashboard_value($row[$column] ?? '')) ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_dashboard_can_view(mysqli $conn): bool
{
    return posmain_recipe_can_view_sensitive_reports($conn);
}

function posmain_recipe_dashboard_filters(array $request): array
{
    return [
        'pos_tenant' => isset($request['pos_tenant']) && $request['pos_tenant'] !== '' ? max(0, (int) $request['pos_tenant']) : -1,
        'pos_branch' => isset($request['pos_branch']) && $request['pos_branch'] !== '' ? max(0, (int) $request['pos_branch']) : -1,
        'store_id' => isset($request['store_id']) && $request['store_id'] !== '' ? max(0, (int) $request['store_id']) : -1,
        'limit' => max(1, min(500, (int) ($request['limit'] ?? 100))),
    ];
}

function posmain_recipe_dashboard_summary_cards(array $dashboard): array
{
    $summary = $dashboard['summary'] ?? [];

    return [
        ['label' => 'Recipe Mode', 'value' => (string) ($summary['recipe_mode'] ?? 'off'), 'class' => !empty($summary['recipe_enabled']) ? 'info' : 'secondary'],
        ['label' => 'Open Issues', 'value' => (string) (int) ($summary['issue_total'] ?? 0), 'class' => ((int) ($summary['issue_total'] ?? 0) > 0) ? 'warning' : 'success'],
        ['label' => 'Negative Balances', 'value' => (string) (int) ($summary['negative_balances'] ?? 0), 'class' => ((int) ($summary['negative_balances'] ?? 0) > 0) ? 'danger' : 'success'],
        ['label' => 'Invalid Movements', 'value' => (string) (int) ($summary['invalid_inventory_movements'] ?? 0), 'class' => ((int) ($summary['invalid_inventory_movements'] ?? 0) > 0) ? 'danger' : 'success'],
        ['label' => 'Failed Availability Sync', 'value' => (string) (int) ($summary['menu_sync_outbox_issues'] ?? 0), 'class' => ((int) ($summary['menu_sync_outbox_issues'] ?? 0) > 0) ? 'danger' : 'success'],
    ];
}

function posmain_recipe_dashboard_columns(array $rows): array
{
    $columns = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (array_keys($row) as $column) {
            $columns[$column] = true;
        }
    }

    return array_keys($columns);
}

function posmain_recipe_dashboard_label(string $key): string
{
    return ucwords(str_replace('_', ' ', $key));
}

function posmain_recipe_dashboard_value($value): string
{
    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }
    if (is_array($value)) {
        return json_encode($value);
    }
    if ($value === null) {
        return '';
    }

    return (string) $value;
}

function posmain_recipe_dashboard_row_class(string $severity): string
{
    switch ($severity) {
        case 'danger':
            return 'table-danger';
        case 'warning':
            return 'table-warning';
        case 'info':
            return 'table-info';
        case 'muted':
            return 'table-secondary';
        default:
            return '';
    }
}

function posmain_recipe_dashboard_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
