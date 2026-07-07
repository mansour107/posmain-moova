<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
include 'includes/connect.php';
page_guard('roles.manage', $conn, true);
header('Location: team.php?tab=roles');
exit;
