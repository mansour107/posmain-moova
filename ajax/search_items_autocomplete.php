<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/supermarket_item_lookup.php';

header('Content-Type: application/json; charset=utf-8');
posmain_supermarket_require_pos_session();

$term = posmain_supermarket_normalize_term((string) ($_GET['term'] ?? ''));
echo json_encode(posmain_supermarket_autocomplete_items($conn, $term), JSON_UNESCAPED_UNICODE);
