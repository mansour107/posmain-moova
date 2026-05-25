<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/recipe_permissions.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/csv_export.php';

require_login();
if (!posmain_recipe_audit_can_view($conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

require_once __DIR__ . '/classes/Recipe/RecipeAuditService.php';

$recipeAuditService = new RecipeAuditService();
$recipeAuditActions = $recipeAuditService->actionOptions($conn);
$recipeAuditEntityTypes = $recipeAuditService->entityTypeOptions($conn);
$recipeAuditFilters = posmain_recipe_audit_filters_from_request($_GET, $recipeAuditActions, $recipeAuditEntityTypes);
$recipeAuditRows = $recipeAuditService->report($conn, $recipeAuditFilters);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    posmain_recipe_audit_export_csv($recipeAuditRows);
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
                    <h3 class="card-title">Recipe Audit Log</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row mb-4 bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label>From</label>
                            <input type="date" name="date_from" class="form-control" value="<?= posmain_recipe_audit_h($recipeAuditFilters['date_from'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label>To</label>
                            <input type="date" name="date_to" class="form-control" value="<?= posmain_recipe_audit_h($recipeAuditFilters['date_to'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label>Tenant</label>
                            <input type="number" name="pos_tenant" class="form-control" value="<?= (int) ($recipeAuditFilters['pos_tenant'] ?? 0) ?>" min="0">
                        </div>
                        <div class="col-md-1">
                            <label>Branch</label>
                            <input type="number" name="pos_branch" class="form-control" value="<?= (int) ($recipeAuditFilters['pos_branch'] ?? 0) ?>" min="0">
                        </div>
                        <div class="col-md-1">
                            <label>Recipe</label>
                            <input type="number" name="recipe_id" class="form-control" value="<?= posmain_recipe_audit_h($_GET['recipe_id'] ?? '') ?>" min="1">
                        </div>
                        <div class="col-md-1">
                            <label>User</label>
                            <input type="number" name="actor_user_id" class="form-control" value="<?= posmain_recipe_audit_h($_GET['actor_user_id'] ?? '') ?>" min="1">
                        </div>
                        <div class="col-md-2">
                            <label>Action</label>
                            <select name="action" class="form-control">
                                <option value="">All</option>
                                <?php foreach ($recipeAuditActions as $action): ?>
                                    <option value="<?= posmain_recipe_audit_h($action) ?>" <?= (($recipeAuditFilters['action'] ?? '') === $action) ? 'selected' : '' ?>>
                                        <?= posmain_recipe_audit_h($action) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Entity</label>
                            <select name="entity_type" class="form-control">
                                <option value="">All</option>
                                <?php foreach ($recipeAuditEntityTypes as $entityType): ?>
                                    <option value="<?= posmain_recipe_audit_h($entityType) ?>" <?= (($recipeAuditFilters['entity_type'] ?? '') === $entityType) ? 'selected' : '' ?>>
                                        <?= posmain_recipe_audit_h($entityType) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mt-3">
                            <label>Limit</label>
                            <input type="number" name="limit" class="form-control" value="<?= (int) ($recipeAuditFilters['limit'] ?? 1000) ?>" min="1" max="5000">
                        </div>
                        <div class="col-md-4 mt-4 d-flex">
                            <button type="submit" class="btn btn-primary mr-2">Run Report</button>
                            <button type="submit" name="export" value="csv" class="btn btn-outline-secondary">Export CSV</button>
                        </div>
                    </form>

                    <div class="alert alert-info">
                        This report is read-only. It shows recipe definition, activation, production, waste, adjustment, and override audit entries written by recipe services.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" data-page-length="50">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Time</th>
                                    <th class="text-center">Tenant</th>
                                    <th class="text-center">Branch</th>
                                    <th class="text-center">Recipe</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th class="text-center">Entity ID</th>
                                    <th class="text-center">User</th>
                                    <th>IP</th>
                                    <th>Before</th>
                                    <th>After</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recipeAuditRows as $row): ?>
                                    <tr>
                                        <td><?= posmain_recipe_audit_h($row['created_at'] ?? '') ?></td>
                                        <td class="text-center"><?= (int) ($row['pos_tenant'] ?? 0) ?></td>
                                        <td class="text-center"><?= (int) ($row['pos_branch'] ?? 0) ?></td>
                                        <td class="text-center"><?= $row['recipe_id'] !== null ? (int) $row['recipe_id'] : '' ?></td>
                                        <td><?= posmain_recipe_audit_h($row['action'] ?? '') ?></td>
                                        <td><?= posmain_recipe_audit_h($row['entity_type'] ?? '') ?></td>
                                        <td class="text-center"><?= $row['entity_id'] !== null ? (int) $row['entity_id'] : '' ?></td>
                                        <td class="text-center"><?= $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : '' ?></td>
                                        <td><?= posmain_recipe_audit_h($row['ip_address'] ?? '') ?></td>
                                        <td><code><?= posmain_recipe_audit_h(posmain_recipe_audit_json_preview($row['before_json'] ?? null)) ?></code></td>
                                        <td><code><?= posmain_recipe_audit_h(posmain_recipe_audit_json_preview($row['after_json'] ?? null)) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$recipeAuditRows): ?>
                        <div class="alert alert-secondary mt-3">No recipe audit rows matched the selected filters.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
function posmain_recipe_audit_can_view(mysqli $conn): bool
{
    return posmain_recipe_can_view_sensitive_reports($conn);
}

function posmain_recipe_audit_filters_from_request(array $request, array $actions, array $entityTypes): array
{
    $action = in_array((string) ($request['action'] ?? ''), $actions, true)
        ? (string) ($request['action'] ?? '')
        : '';
    $entityType = in_array((string) ($request['entity_type'] ?? ''), $entityTypes, true)
        ? (string) ($request['entity_type'] ?? '')
        : '';

    return [
        'pos_tenant' => max(0, (int) ($request['pos_tenant'] ?? 0)),
        'pos_branch' => max(0, (int) ($request['pos_branch'] ?? 0)),
        'recipe_id' => isset($request['recipe_id']) && (int) $request['recipe_id'] > 0 ? (int) $request['recipe_id'] : -1,
        'actor_user_id' => isset($request['actor_user_id']) && (int) $request['actor_user_id'] > 0 ? (int) $request['actor_user_id'] : -1,
        'date_from' => posmain_recipe_audit_date($request['date_from'] ?? ''),
        'date_to' => posmain_recipe_audit_date($request['date_to'] ?? ''),
        'action' => $action,
        'entity_type' => $entityType,
        'limit' => max(1, min(5000, (int) ($request['limit'] ?? 1000))),
    ];
}

function posmain_recipe_audit_date($value): string
{
    $text = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : '';
}

function posmain_recipe_audit_json_preview($value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $text = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > 240 ? mb_substr($text, 0, 237, 'UTF-8') . '...' : $text;
    }

    return strlen($text) > 240 ? substr($text, 0, 237) . '...' : $text;
}

function posmain_recipe_audit_export_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recipe_audit_log.csv"');
    $out = fopen('php://output', 'w');
    posmain_csv_write_row($out, [
        'id',
        'created_at',
        'pos_tenant',
        'pos_branch',
        'branch_uuid',
        'recipe_id',
        'action',
        'entity_type',
        'entity_id',
        'actor_user_id',
        'ip_address',
        'before_json',
        'after_json',
    ]);
    foreach ($rows as $row) {
        posmain_csv_write_row($out, posmain_csv_safe_row([
            $row['id'],
            $row['created_at'],
            $row['pos_tenant'],
            $row['pos_branch'],
            $row['branch_uuid'],
            $row['recipe_id'],
            $row['action'],
            $row['entity_type'],
            $row['entity_id'],
            $row['actor_user_id'],
            $row['ip_address'],
            $row['before_json'],
            $row['after_json'],
        ]));
    }
    fclose($out);
}

function posmain_recipe_audit_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
