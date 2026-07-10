<?php

require_once __DIR__ . '/../classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/pos_operational_store.php';

/**
 * Resolve tenant/branch for business-day pages when POS session scope is empty.
 *
 * @return array{tenant: int, branch: int}
 */
function posmain_business_day_resolve_scope(?int $tenant = null, ?int $branch = null): array
{
    $resolvedTenant = $tenant !== null
        ? max(0, $tenant)
        : (int) ($_SESSION['pos_tenant'] ?? $_SESSION['tenant'] ?? 0);
    $resolvedBranch = $branch !== null
        ? max(0, $branch)
        : (int) ($_SESSION['pos_branch'] ?? $_SESSION['branch'] ?? 0);

    if ($resolvedTenant < 1 || $resolvedBranch < 1) {
        $operational = posmain_operational_branch_scope();
        if ($resolvedTenant < 1) {
            $resolvedTenant = max(0, (int) ($operational['pos_tenant'] ?? 0));
        }
        if ($resolvedBranch < 1) {
            $resolvedBranch = max(0, (int) ($operational['pos_branch'] ?? 0));
        }
    }

    return [
        'tenant' => $resolvedTenant,
        'branch' => $resolvedBranch,
    ];
}

/**
 * Resolve tenant/branch business-day context for report/POS pages.
 *
 * @return array{
 *   tenant: int,
 *   branch: int,
 *   cutoff_hour: int,
 *   current_business_day: string,
 *   previous_business_day: string,
 *   date_from: string,
 *   date_to: string,
 *   service: BusinessDayService
 * }
 */
function posmain_business_day_context(
    ?mysqli $conn = null,
    ?int $tenant = null,
    ?int $branch = null,
    ?string $now = null
): array {
    $service = new BusinessDayService();
    $scope = posmain_business_day_resolve_scope($tenant, $branch);
    $resolvedTenant = $scope['tenant'];
    $resolvedBranch = $scope['branch'];

    $cutoffHour = BusinessDayService::DEFAULT_CUTOFF_HOUR;
    $current = date('Y-m-d');
    if ($conn instanceof mysqli) {
        $cutoffHour = $service->cutoffHourForBranch($conn, $resolvedTenant, $resolvedBranch);
        $current = $service->currentBusinessDayForBranch($conn, $resolvedTenant, $resolvedBranch, $now);
    } else {
        $current = $service->businessDayForTimestamp($now ?: date('Y-m-d H:i:s'), $cutoffHour);
    }

    $previous = $service->previousBusinessDay($current);

    return [
        'tenant' => $resolvedTenant,
        'branch' => $resolvedBranch,
        'cutoff_hour' => $cutoffHour,
        'current_business_day' => $current,
        'previous_business_day' => $previous,
        'date_from' => $current,
        'date_to' => $current,
        'service' => $service,
    ];
}

function posmain_current_business_day(
    ?mysqli $conn = null,
    ?int $tenant = null,
    ?int $branch = null,
    ?string $now = null
): string {
    return (string) posmain_business_day_context($conn, $tenant, $branch, $now)['current_business_day'];
}
