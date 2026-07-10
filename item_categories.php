<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';

$tab = 'subgroups';
if (isset($_GET['error'])) {
    header('Location: mygroups.php?tab=' . $tab . '&error=' . urlencode((string) $_GET['error']));
    exit;
}
header('Location: mygroups.php?tab=' . $tab);
exit;
