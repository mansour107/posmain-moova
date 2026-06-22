<?php
/**
 * HTTP-level smoke for public delivery customer endpoints (Phase 1).
 */

$base = getenv('POSMAIN_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8010';
$phone = '0100' . random_int(2000000, 8999999);

function deliveryHttpPostJson(string $url, array $data): array
{
    $body = http_build_query($data);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . $url);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON from ' . $url . ': ' . substr($raw, 0, 200));
    }
    return $decoded;
}

function deliveryHttpAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "delivery-http-smoke-FAIL: {$msg}\n");
        exit(1);
    }
}

try {
    $search1 = deliveryHttpPostJson($base . '/do/search_customer.php', ['phone' => $phone]);
    deliveryHttpAssert(($search1['found'] ?? null) === false, 'new phone should not be found');

    $save = deliveryHttpPostJson($base . '/do/save_customer.php', [
        'phone' => $phone,
        'name' => 'HTTP Smoke User',
        'address' => 'HTTP Address 1',
    ]);
    deliveryHttpAssert(($save['success'] ?? false) === true, 'save_customer should succeed');
    deliveryHttpAssert((int) ($save['client_id'] ?? 0) > 0, 'save_customer should return client_id');

    $search2 = deliveryHttpPostJson($base . '/do/search_customer.php', ['phone' => $phone]);
    deliveryHttpAssert(($search2['found'] ?? false) === true, 'saved phone should be found');
    deliveryHttpAssert(($search2['name'] ?? '') === 'HTTP Smoke User', 'search should return saved name');

    $update = deliveryHttpPostJson($base . '/do/update_customer.php', [
        'phone' => $phone,
        'name' => 'HTTP Smoke User Updated',
        'address' => 'HTTP Address 2',
    ]);
    deliveryHttpAssert(($update['success'] ?? false) === true, 'update_customer should succeed');

    $search3 = deliveryHttpPostJson($base . '/do/search_customer.php', ['phone' => $phone]);
    deliveryHttpAssert(($search3['name'] ?? '') === 'HTTP Smoke User Updated', 'search should reflect update');

    $zonesRaw = @file_get_contents($base . '/ajax/delivery_zones_list.php', false, stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
    ]));
    deliveryHttpAssert($zonesRaw !== false, 'delivery_zones_list should respond');
    $zones = json_decode((string) $zonesRaw, true);
    deliveryHttpAssert(is_array($zones), 'zones list JSON should decode');
    if (($zones['success'] ?? false) === true) {
        deliveryHttpAssert(is_array($zones['zones'] ?? null), 'zones list should include zones array');
    } else {
        deliveryHttpAssert(
            stripos((string) ($zones['error'] ?? ''), 'Unauthorized') !== false,
            'unauthenticated zones list should require login'
        );
    }

    // In-process authenticated zones fetch (same DB contract as POS)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['login'] = 'delivery-http-smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['usty'] = 2;
    ob_start();
    require __DIR__ . '/../../ajax/delivery_zones_list.php';
    $zonesAuthRaw = (string) ob_get_clean();
    $zonesAuth = json_decode($zonesAuthRaw, true);
    deliveryHttpAssert(is_array($zonesAuth) && ($zonesAuth['success'] ?? false) === true, 'authenticated zones list should succeed');
    deliveryHttpAssert(is_array($zonesAuth['zones'] ?? null), 'authenticated zones list should include zones array');

    echo "delivery-http-smoke-ok base={$base}\n";
    echo json_encode([
        'phone' => $phone,
        'client_id' => (int) ($save['client_id'] ?? 0),
        'zones_count' => count($zonesAuth['zones'] ?? []),
        'zones_http_auth_gate' => ($zones['success'] ?? false) === false,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'delivery-http-smoke-FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
