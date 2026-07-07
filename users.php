<?php
require_once __DIR__ . '/includes/auth_guard.php';
include 'includes/connect.php';
require_admin_or_permission('users.manage', $conn);
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'team.php?tab=staff' . ($query !== '' ? '&' . $query : '');
header('Location: ' . $target);
exit;
