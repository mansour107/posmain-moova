<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
include 'includes/connect.php';
page_guard('roles.manage', $conn, true);
$roleId = max(1, (int) ($_GET['no'] ?? $_GET['id'] ?? 0));
header('Location: team.php?tab=roles' . ($roleId > 0 ? '&role=' . $roleId : ''));
exit;
