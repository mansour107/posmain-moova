<?php
require_once __DIR__ . '/includes/auth_guard.php';
include 'includes/connect.php';
require_admin_or_permission('users.manage', $conn);
header('Location: team.php?tab=staff&panel=new');
exit;
