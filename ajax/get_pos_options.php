<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');

header('Content-Type: application/json; charset=UTF-8');

function pos_options_json_response(bool $success, array $payload = []): void
{
    echo json_encode(array_merge(['success' => $success], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

function pos_options_fetch_rows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!$result) {
        throw new RuntimeException($conn->error);
    }

    $options = [];
    while ($row = $result->fetch_assoc()) {
        $options[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['aname'] ?? ''),
        ];
    }

    return $options;
}

try {
    $type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';

    if ($type === 'customers') {
        pos_options_json_response(true, [
            'options' => pos_options_fetch_rows(
                $conn,
                "SELECT id, aname
                 FROM `acc_head`
                 WHERE code LIKE '122%'
                   AND isdeleted = 0
                   AND is_basic = 0
                 ORDER BY code, id"
            ),
        ]);
    }

    if ($type === 'banks') {
        pos_options_json_response(true, [
            'options' => pos_options_fetch_rows(
                $conn,
                "SELECT id, aname
                 FROM `acc_head`
                 WHERE (parent_id = 124 OR code LIKE '124%')
                   AND is_basic = 0
                   AND isdeleted = 0
                 ORDER BY aname"
            ),
        ]);
    }

    pos_options_json_response(false, ['message' => 'Unsupported options type']);
} catch (Throwable $e) {
    pos_options_json_response(false, ['message' => $e->getMessage()]);
}
