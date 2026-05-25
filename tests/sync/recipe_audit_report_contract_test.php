<?php

$root = dirname(__DIR__, 2);
$report = recipeAuditReportSource($root . '/recipe_audit_report.php');
$service = recipeAuditReportSource($root . '/classes/Recipe/RecipeAuditService.php');
$repository = recipeAuditReportSource($root . '/classes/Recipe/Repository/RecipeAuditRepository.php');
$reportsIndex = recipeAuditReportSource($root . '/reports.php');

recipeAuditReportAssert(
    strpos($report, 'RecipeAuditService.php') !== false,
    'recipe audit report should load the shared audit service'
);
recipeAuditReportAssert(
    strpos($report, 'require_login()') !== false,
    'recipe audit report should require login through the auth guard'
);
recipeAuditReportAssert(
    strpos($report, 'posmain_recipe_audit_can_view($conn)') !== false
        && strpos($report, 'recipe_permissions.php') !== false
        && strpos($report, 'posmain_recipe_can_view_sensitive_reports($conn)') !== false
        && strpos($report, "auth_guard_has_permission('reports.view'") === false,
    'recipe audit report should restrict audit JSON to stock/accounting/admin permissions'
);
recipeAuditReportAssert(
    strpos($report, '->report($conn, $recipeAuditFilters)') !== false,
    'recipe audit report should delegate audit reads to the service'
);
recipeAuditReportAssert(
    strpos($report, "header('Content-Type: text/csv; charset=utf-8')") !== false,
    'recipe audit report should support CSV export'
);
recipeAuditReportAssert(
    strpos($report, "require_once __DIR__ . '/includes/csv_export.php'") !== false
        && strpos($report, 'posmain_csv_safe_row') !== false,
    'recipe audit report should sanitize exported CSV cells'
);
recipeAuditReportAssert(
    strpos($report, 'posmain_recipe_audit_filters_from_request') !== false
        && strpos($report, 'in_array((string) ($request[\'action\'] ?? \'\'), $actions, true)') !== false
        && strpos($report, 'in_array((string) ($request[\'entity_type\'] ?? \'\'), $entityTypes, true)') !== false,
    'recipe audit report should sanitize action/entity filters through service-provided option lists'
);
recipeAuditReportAssert(
    !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE)\b/i', $report),
    'recipe audit report must remain read-only'
);
recipeAuditReportAssert(
    strpos($service, 'function report') !== false
        && strpos($service, 'actionOptions') !== false
        && strpos($service, 'entityTypeOptions') !== false
        && strpos($service, 'encodePayload') !== false
        && strpos($repository, 'function search') !== false,
    'recipe audit service/repository should expose filtered read APIs'
);
foreach ([
    'Recipe audit action is required',
    'Recipe audit entity_type is required',
    'contains invalid characters',
    'must be positive when provided',
    'must be valid JSON when provided',
] as $guard) {
    recipeAuditReportAssert(strpos($repository, $guard) !== false, 'recipe audit repository should guard audit invariant: ' . $guard);
}
recipeAuditReportAssert(
    strpos($reportsIndex, 'recipe_audit_report.php') !== false,
    'recipe audit report should be discoverable from existing report screens'
);

echo "recipe-audit-report-contract-ok\n";

function recipeAuditReportSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeAuditReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
