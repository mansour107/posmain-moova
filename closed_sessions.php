<?php
require_once __DIR__ . '/includes/auth_guard.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/cash_shift_navigation.php';

page_guard(null, $conn);

$params = [
    'tab' => CashShiftWorkspaceService::TAB_SHIFTS,
    'status' => 'needs_review',
    'scope' => 'backlog',
];
if (isset($_GET['page'])) {
    $params['page'] = max(1, (int) $_GET['page']);
}
header('Location: ' . posmain_cash_shift_workspace_url($params), true, 302);
exit;
