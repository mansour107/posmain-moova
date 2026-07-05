<?php

require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionService.php';

/**
 * Shift gate for POS order writes.
 *
 * Waiters do not own a cashier drawer/shift, so they cannot be gated on a shift
 * they own. Instead, order writes are blocked when the shift that would account
 * for the order is closed. Two signals are combined so waiters and cashiers are
 * gated consistently:
 *
 *   1. The per-session close flag (`pos_shift_closed_for_session`) set by the
 *      cashier close flow. It is authoritative for the session that owns it.
 *   2. The branch-level drawer-session state (the robust source of truth from
 *      DrawerSessionService). A closed branch shift blocks every session on that
 *      branch, including waiters that never carry the session flag.
 *
 * The branch-level check only *enforces* closure once the drawer-session
 * subsystem is actually in use for the branch (at least one session on record).
 * Deployments that have not adopted formal shift open/close yet have no
 * drawer_sessions rows, so the check fails open and normal ordering is never
 * broken.
 */
if (!function_exists('posmain_pos_shift_write_blocked')) {
    function posmain_pos_shift_write_blocked(?array $session = null, ?mysqli $conn = null, int $tenant = 0, int $branch = 0): bool
    {
        $session = $session ?? $_SESSION;

        if (!empty($session['pos_shift_closed_for_session'])) {
            return true;
        }

        if (!($conn instanceof mysqli) || !posmain_drawer_sessions_table_exists($conn)) {
            return false;
        }

        $service = new DrawerSessionService();
        if (!$service->branchHasSessions($conn, $tenant, $branch)) {
            // Subsystem not adopted for this branch – do not block ordering.
            return false;
        }

        return $service->findOpenSessionForBranch($conn, $tenant, $branch) === null;
    }
}

if (!function_exists('posmain_drawer_sessions_table_exists')) {
    function posmain_drawer_sessions_table_exists(mysqli $conn): bool
    {
        try {
            $result = $conn->query("SHOW TABLES LIKE 'drawer_sessions'");
        } catch (Throwable $exception) {
            return false;
        }

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
