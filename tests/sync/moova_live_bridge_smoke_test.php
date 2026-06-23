<?php

$token = trim((string) shell_exec("docker exec posmain-mysql mariadb -N -uroot kody2 -e \"SELECT moova_device_token FROM moova_pos_shop_links WHERE status='active' ORDER BY id DESC LIMIT 1\""));
if ($token === '') {
    fwrite(STDERR, "missing device token\n");
    exit(1);
}

$headers = ["Authorization: Bearer {$token}"];
$bootstrap = moovaLiveBridgeGet('http://127.0.0.1:3001/api/integrations/pos/local-bridge/widget/bootstrap', $headers);
$pending = moovaLiveBridgeGet('http://127.0.0.1:3001/api/integrations/pos/local-bridge/pending', $headers);

if (($bootstrap['http_status'] ?? 0) !== 200 || !is_array($bootstrap['body'])) {
    fwrite(STDERR, 'bootstrap failed' . PHP_EOL);
    exit(1);
}
if (($pending['http_status'] ?? 0) !== 200 || !is_array($pending['body'])) {
    fwrite(STDERR, 'pending failed' . PHP_EOL);
    exit(1);
}

echo "moova-live-bridge-smoke-ok\n";
echo json_encode([
    'bootstrap_device_id' => $bootstrap['body']['device']['id'] ?? null,
    'pending_drafts' => count($pending['body']['drafts'] ?? []),
    'pending_commands' => count($pending['body']['commands'] ?? []),
    'remoteReachable' => $pending['body']['remoteReachable'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

function moovaLiveBridgeGet(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_status' => $status,
        'body' => json_decode((string) $raw, true),
    ];
}
