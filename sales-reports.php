<?php

require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/page_guard.php';

page_guard('reports.view', $conn);

// Compatibility entry point. POS operating reports now have one canonical UI.
header('Location: cash_flow_report.php?tab=overview', true, 302);
exit;
