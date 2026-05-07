<?php
require_once __DIR__ . '/classes/MoovaPosIntegration.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function moova_proxy_json($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function moova_proxy_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function moova_proxy_origin_from_widget_url($widgetUrl)
{
    $parts = parse_url((string) $widgetUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

$path = isset($_GET['path']) ? (string) $_GET['path'] : '';
$path = rawurldecode($path);

if (strpos($path, '/api/integrations/pos/local-bridge/') !== 0) {
    moova_proxy_json(400, ['error' => 'invalid_moova_proxy_path']);
}

if (empty($_SESSION['userid'])) {
    moova_proxy_json(401, ['error' => 'please_login_first']);
}

require __DIR__ . '/includes/connect.php';

try {
    MoovaPosIntegration::ensureSchema($conn);
    $link = MoovaPosIntegration::findActiveLinkForUser($conn, (int) $_SESSION['userid']);
} catch (Exception $e) {
    error_log('[Moova POS] proxy mapping unavailable: ' . $e->getMessage());
    moova_proxy_json(500, ['error' => 'moova_mapping_unavailable']);
}

if (!$link || empty($link['moova_device_token'])) {
    moova_proxy_json(401, ['error' => 'moova_link_missing']);
}

$widgetUrl = trim((string) ($link['widget_url'] ?: 'https://withmoova.com/pos-widget'));
$moovaOrigin = moova_proxy_origin_from_widget_url($widgetUrl);
if ($moovaOrigin === '') {
    moova_proxy_json(500, ['error' => 'invalid_moova_origin']);
}

$targetUrl = $moovaOrigin . $path;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = file_get_contents('php://input');
$deviceToken = (string) $link['moova_device_token'];
$widgetOrigin = moova_proxy_header('X-Pos-Widget-Origin') ?: (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

$headers = [
    'Authorization: Bearer ' . $deviceToken,
    'X-Pos-Device-Token: ' . $deviceToken,
    'X-Pos-Widget-Origin: ' . $widgetOrigin,
    'Accept: application/json',
];

if ($body !== '' && $body !== false) {
    $headers[] = 'Content-Type: application/json';
}

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body === false ? '' : $body);
}

$response = curl_exec($ch);
if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    moova_proxy_json(502, ['error' => 'moova_unreachable', 'message' => $error]);
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$responseBody = substr($response, $headerSize);
curl_close($ch);

http_response_code($statusCode ?: 502);
echo $responseBody !== false && $responseBody !== '' ? $responseBody : '{}';
