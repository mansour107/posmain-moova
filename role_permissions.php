<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
include 'includes/connect.php';
page_guard('roles.manage', $conn, true);
$roleId = (int) ($_GET['id'] ?? 0);
header('Location: team.php?tab=roles' . ($roleId > 0 ? '&role=' . $roleId : ''));
exit;
