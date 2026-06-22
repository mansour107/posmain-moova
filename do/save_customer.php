<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryClientService.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$phone = (string) ($_POST['phone'] ?? '');
$name = (string) ($_POST['name'] ?? '');
$address = (string) ($_POST['address'] ?? '');

if ($phone === '' || $name === '' || $address === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    $conn->close();
    exit;
}

try {
    $service = new DeliveryClientService();
    $result = $service->upsertByPhone($conn, $phone, $name, $address);
    echo json_encode([
        'success' => true,
        'client_id' => $result['client_id'],
        'name' => $result['name'],
        'phone' => $result['phone'],
        'address' => $result['address'],
    ]);
} catch (Throwable $e) {
    error_log('save_customer error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
