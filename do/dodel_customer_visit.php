<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_customer_visit.php');

declare(strict_types=1);

include __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ../customer_visits.php?error=invalid_method');
    exit;
}

require_csrf('customer_visits');

$id = (int) ($_POST['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare('UPDATE customer_visits SET isdeleted = 1 WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $conn->query("INSERT INTO `process`(`type`) VALUES ('delete visit')");
}

header('Location: ../customer_visits.php?success=deleted');
exit;
