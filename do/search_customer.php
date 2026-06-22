<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryClientService.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    echo json_encode(['found' => false, 'error' => 'Database connection failed']);
    exit;
}

$phone = (string) ($_POST['phone'] ?? $_GET['phone'] ?? '');
if ($phone === '') {
    echo json_encode(['found' => false, 'error' => 'Phone number is required']);
    $conn->close();
    exit;
}

try {
    $service = new DeliveryClientService();
    $client = $service->findByPhone($conn, $phone);
    if ($client) {
        echo json_encode([
            'found' => true,
            'id' => $client['id'],
            'name' => $client['name'],
            'address' => $client['address'],
            'phone' => $client['phone'],
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
} catch (Throwable $e) {
    error_log('search_customer error: ' . $e->getMessage());
    echo json_encode(['found' => false, 'error' => 'Database query failed']);
}

$conn->close();
