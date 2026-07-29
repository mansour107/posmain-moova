<?php

require_once __DIR__ . '/../includes/api_entry_classification.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';

function posmain_menu_api_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function posmain_menu_api_bearer_token()
{
    $authorization = posmain_menu_api_header('Authorization');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return trim((string) $matches[1]);
    }

    return '';
}

function posmain_menu_api_device_token()
{
    foreach (['X-Moova-Device-Token', 'X-Pos-Device-Token'] as $header) {
        $token = posmain_menu_api_header($header);
        if ($token !== '') {
            return $token;
        }
    }

    return posmain_menu_api_bearer_token();
}

function posmain_menu_api_active_link_count(mysqli $conn)
{
    $result = $conn->query("SELECT COUNT(*) AS total FROM moova_pos_shop_links WHERE status = 'active'");
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function posmain_menu_api_json($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function posmain_menu_api_require_access(mysqli $conn)
{
    MoovaPosIntegration::ensureSchema($conn);

    if (posmain_menu_api_active_link_count($conn) < 1) {
        return null;
    }

    $deviceToken = posmain_menu_api_device_token();
    if ($deviceToken === '') {
        posmain_menu_api_json(401, [
            'status' => 'error',
            'code' => 'AUTH_REQUIRED',
            'message' => 'Moova device token is required.',
        ]);
    }

    $branchId = posmain_menu_api_header('X-Moova-Branch-Id');
    $link = $branchId !== ''
        ? MoovaPosIntegration::findActiveLinkByTokenAndBranch($conn, $deviceToken, $branchId)
        : null;
    if (!$link) {
        $link = MoovaPosIntegration::findActiveLinkByToken($conn, $deviceToken);
    }

    if (!$link || !hash_equals((string) ($link['moova_device_token'] ?? ''), $deviceToken)) {
        posmain_menu_api_json(403, [
            'status' => 'error',
            'code' => 'MOOVA_LINK_NOT_FOUND',
            'message' => 'Moova device token is not linked to this POS.',
        ]);
    }

    return $link;
}
