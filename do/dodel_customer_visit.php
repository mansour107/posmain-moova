<?php
declare(strict_types=1);

include '../includes/connect.php';

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('Location: ../index.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("UPDATE customer_visits SET isdeleted = 1 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $conn->query("INSERT INTO `process`(`type`) VALUES ('delete visit')");
}

header('Location: ../customer_visits.php');
exit;
