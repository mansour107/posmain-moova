<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/supermarket_item_lookup.php';

header('Content-Type: application/json; charset=utf-8');
posmain_supermarket_require_pos_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

posmain_supermarket_require_ajax_csrf();

$barcode = posmain_supermarket_normalize_term((string) ($_POST['barcode'] ?? ''));
if ($barcode === '') {
    echo json_encode(['success' => false, 'message' => 'Empty barcode']);
    exit;
}

$item = posmain_supermarket_lookup_item($conn, $barcode);
if ($item) {
    echo json_encode(['success' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Item not found']);
