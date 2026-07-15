<?php

/**
 * Decision-matrix contract for PostLoginRouteService::resolveBestLanding().
 * Pure permission maps — no DB required.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Security/PostLoginRouteService.php';

function postLoginRouteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$router = new PostLoginRouteService();

$cases = [
    'reports_only' => [
        'permissions' => ['reports.view' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'sales-reports.php',
    ],
    'manager_like_no_widgets' => [
        'permissions' => [
            'reports.view' => true,
            'reports.cash_flow' => true,
            'inventory.edit' => true,
            'menu.edit' => true,
            'pos.open' => true,
            'kds.view' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'sales-reports.php',
    ],
    'widgets_present' => [
        'permissions' => [
            'erp.dashboard.main_cards' => true,
            'reports.view' => true,
            'pos.open' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_DASHBOARD,
        'url' => 'dashboard.php',
    ],
    'settings_over_reports' => [
        'permissions' => [
            'system.tools.run' => true,
            'reports.view' => true,
            'pos.open' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'setting.php',
    ],
    'team_over_pos' => [
        'permissions' => [
            'users.manage' => true,
            'pos.open' => true,
            'kds.view' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'team.php',
    ],
    'cash_flow_without_reports_view' => [
        'permissions' => [
            'reports.cash_flow' => true,
            'pos.open' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'cash_flow_report.php',
    ],
    'accounting_only' => [
        'permissions' => ['accounting.view' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'daily_journal.php',
    ],
    'inventory_only' => [
        'permissions' => ['inventory.edit' => true, 'pos.open' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'inventory_dashboard.php',
    ],
    'menu_only' => [
        'permissions' => ['menu.edit' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'myitems.php',
    ],
    'delivery_only' => [
        'permissions' => ['delivery.dispatch' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_BACKOFFICE,
        'url' => 'delivery_board.php',
    ],
    'pos_and_kds_only' => [
        'permissions' => [
            'pos.open' => true,
            'kds.view' => true,
        ],
        'workspace' => PostLoginRouteService::WORKSPACE_CHOOSER,
        'url' => 'workspace.php',
    ],
    'pos_only' => [
        'permissions' => ['pos.open' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_POS,
        'url' => 'pos_barcode.php',
    ],
    'kds_only' => [
        'permissions' => ['kds.view' => true],
        'workspace' => PostLoginRouteService::WORKSPACE_KDS,
        'url' => 'kds.php',
    ],
    'none' => [
        'permissions' => [],
        'workspace' => PostLoginRouteService::WORKSPACE_NONE,
        'url' => 'no_access.php',
    ],
];

foreach ($cases as $name => $case) {
    $resolved = $router->resolveBestLanding($case['permissions']);
    postLoginRouteAssert(
        ($resolved['workspace'] ?? '') === $case['workspace'],
        $name . ' workspace expected ' . $case['workspace'] . ', got ' . ($resolved['workspace'] ?? '')
    );
    postLoginRouteAssert(
        ($resolved['url'] ?? '') === $case['url'],
        $name . ' url expected ' . $case['url'] . ', got ' . ($resolved['url'] ?? '')
    );
}

$chooser = $router->resolveBestLanding(['pos.open' => true, 'kds.view' => true]);
postLoginRouteAssert(
    isset($chooser['choices']) && count($chooser['choices']) === 2,
    'pos+kds chooser must expose exactly two choices'
);
foreach ($chooser['choices'] as $choice) {
    postLoginRouteAssert(
        in_array($choice['key'] ?? '', [PostLoginRouteService::WORKSPACE_POS, PostLoginRouteService::WORKSPACE_KDS], true),
        'chooser choices must be POS/KDS only'
    );
}

$dashboardSource = (string) file_get_contents($root . '/dashboard.php');
postLoginRouteAssert(
    strpos($dashboardSource, 'PostLoginRouteService') !== false
        && strpos($dashboardSource, 'erp.dashboard.main_cards') !== false
        && strpos($dashboardSource, 'resolveRedirect') !== false,
    'dashboard.php must redirect empty shells via PostLoginRouteService'
);
postLoginRouteAssert(
    strpos($dashboardSource, "include('includes/header.php')") !== false
        || strpos($dashboardSource, 'include("includes/header.php")') !== false
        || preg_match('/include\s*\(\s*[\'"]includes\/header\.php[\'"]\s*\)/', $dashboardSource) === 1,
    'dashboard.php must still render chrome when widgets exist'
);

$routeSource = (string) file_get_contents($root . '/classes/Security/PostLoginRouteService.php');
postLoginRouteAssert(
    strpos($routeSource, "in_array(\$roleKey, ['owner', 'manager']") === false,
    'owner/manager must not hard-redirect to blank dashboard'
);
postLoginRouteAssert(
    strpos($routeSource, "roleKey === 'cashier'") !== false
        && strpos($routeSource, "roleKey === 'kitchen'") !== false
        && strpos($routeSource, "roleKey === 'waiter'") !== false,
    'frontline role short-circuits must remain'
);

echo "post_login_route_priority_contract_test: OK\n";
