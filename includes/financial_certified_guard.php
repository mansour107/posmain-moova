<?php

/**
 * Block legacy financial write endpoints. Exact-money posting services are required.
 */
function financial_certified_reject_legacy_writer(string $endpoint): void
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('LEGACY_FINANCIAL_WRITER_FORBIDDEN:' . $endpoint);
    }

    $wantsJson = strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false
        || isset($_GET['json'])
        || isset($_POST['json']);

    http_response_code(410);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'code' => 'LEGACY_FINANCIAL_WRITER_FORBIDDEN',
            'message' => 'Use the certified financial posting services.',
            'endpoint' => $endpoint,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ../warning.php?error=legacy_financial_writer_forbidden');
    exit;
}
