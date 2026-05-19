<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/classes/MoovaPosIntegration.php';

header('Content-Type: application/json; charset=UTF-8');

function moova_proxy_json($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_proxy_reachability_error($details)
{
    return [
        'success' => false,
        'error' => 'moova_unreachable',
        'code' => 'MOOVA_UNREACHABLE',
        'message' => 'Moova is unreachable. Check the Moova service connection and try again.',
        'retryable' => true,
        'details' => (string) $details,
    ];
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

function moova_proxy_host_for_url($host)
{
    $host = (string) $host;
    if ($host !== '' && strpos($host, ':') !== false && $host[0] !== '[') {
        return '[' . $host . ']';
    }

    return $host;
}

function moova_proxy_build_url(array $parts)
{
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $url = $parts['scheme'] . '://';
    if (isset($parts['user']) && $parts['user'] !== '') {
        $url .= $parts['user'];
        if (isset($parts['pass']) && $parts['pass'] !== '') {
            $url .= ':' . $parts['pass'];
        }
        $url .= '@';
    }
    $url .= moova_proxy_host_for_url($parts['host']);
    if (isset($parts['port'])) {
        $url .= ':' . $parts['port'];
    }
    $url .= $parts['path'] ?? '';
    if (isset($parts['query']) && $parts['query'] !== '') {
        $url .= '?' . $parts['query'];
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $url .= '#' . $parts['fragment'];
    }

    return $url;
}

function moova_proxy_browser_reachable_url($url, $widgetOrigin)
{
    $parts = parse_url((string) $url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    if (strtolower((string) $parts['host']) !== 'host.docker.internal') {
        return $url;
    }

    $originParts = parse_url((string) $widgetOrigin);
    if (empty($originParts['host']) || strtolower((string) $originParts['host']) === 'host.docker.internal') {
        return $url;
    }

    $parts['host'] = (string) $originParts['host'];
    $rewritten = moova_proxy_build_url($parts);

    return $rewritten !== '' ? $rewritten : $url;
}

function moova_proxy_rewrite_browser_moova_urls($responseBody, $widgetOrigin)
{
    $payload = json_decode((string) $responseBody, true);
    if (!is_array($payload) || empty($payload['config']['moova']) || !is_array($payload['config']['moova'])) {
        return $responseBody;
    }

    foreach (['baseUrl', 'websocketUrl', 'widgetUrl'] as $key) {
        if (isset($payload['config']['moova'][$key]) && is_string($payload['config']['moova'][$key])) {
            $payload['config']['moova'][$key] = moova_proxy_browser_reachable_url(
                $payload['config']['moova'][$key],
                $widgetOrigin
            );
        }
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : $responseBody;
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
    moova_proxy_json(502, moova_proxy_reachability_error($error));
}

$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$responseBody = substr($response, $headerSize);
curl_close($ch);

$responseBody = moova_proxy_rewrite_browser_moova_urls($responseBody, $widgetOrigin);

http_response_code($statusCode ?: 502);
echo $responseBody !== false && $responseBody !== '' ? $responseBody : '{}';
