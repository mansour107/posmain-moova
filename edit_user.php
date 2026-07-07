<?php

require_once __DIR__ . '/includes/auth_guard.php';
include __DIR__ . '/includes/connect.php';
require_admin_or_permission('users.manage', $conn);

$id = max(1, (int) ($_GET['id'] ?? 0));
header('Location: team.php?tab=staff&user=' . $id . '&section=permissions');
exit;
