<?php
/**
 * HTTP-level smoke: legacy delivery customer endpoints are removed (404).
 */

$base = getenv('POSMAIN_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8010';

function deliveryHttpGetStatus(string $url): int
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
    if (!isset($http_response_header[0])) {
        return 0;
    }
    if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

function deliveryHttpAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "delivery-http-smoke-FAIL: {$msg}\n");
        exit(1);
    }
}

try {
    foreach (['search_customer.php', 'save_customer.php', 'update_customer.php'] as $endpoint) {
        $status = deliveryHttpGetStatus($base . '/do/' . $endpoint);
        deliveryHttpAssert(in_array($status, [404, 403], true), $endpoint . ' should be unreachable (404/403), got ' . $status);
    }

    echo "delivery-http-smoke-ok legacy-endpoints-removed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'delivery-http-smoke-skipped: ' . $e->getMessage() . PHP_EOL);
    exit(0);
}
