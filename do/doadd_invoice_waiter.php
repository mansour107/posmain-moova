<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true || !isset($_SESSION['is_waiter']) || $_SESSION['is_waiter'] != 1) {
    header('Location: ../waiter_login.php');
    exit;
}

$_POST['waiter_id'] = (int) ($_SESSION['waiter_id'] ?? 0);
$_POST['info'] = trim((string) ($_POST['info'] ?? '')) . ' | الويتر: ' . (string) ($_SESSION['waiter_name'] ?? '');

if (isset($_POST['quantity']) && is_array($_POST['quantity']) && !isset($_POST['itmqty'])) {
    $_POST['itmqty'] = $_POST['quantity'];
}
if (isset($_POST['price']) && is_array($_POST['price']) && !isset($_POST['itmprice'])) {
    $_POST['itmprice'] = $_POST['price'];
}
if (isset($_POST['total']) && is_array($_POST['total']) && !isset($_POST['itmtotal'])) {
    $_POST['itmtotal'] = $_POST['total'];
}
if (isset($_POST['table_id']) && !isset($_POST['selected_table_id'])) {
    $_POST['selected_table_id'] = $_POST['table_id'];
}
if (!isset($_POST['pro_tybe'])) {
    $_POST['pro_tybe'] = 9;
}

require __DIR__ . '/doadd_invoice.php';
