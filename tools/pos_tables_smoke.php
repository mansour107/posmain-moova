#!/usr/bin/env php
<?php

declare(strict_types=1);

$baseUrl = rtrim((string) (getenv('POSMAIN_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8010'), '/');
$username = (string) (getenv('POSMAIN_E2E_USER_CASHIER') ?: 'p6_cashier');
$password = (string) (getenv('POSMAIN_E2E_DEMO_PASSWORD') ?: 'P6demo123!');
$pin = (string) (getenv('POSMAIN_TEST_PIN_CASHIER') ?: '9753');

function posTablesSmokeRequest(string $url, string $cookieFile, array $options = []): array
{
    $ch = curl_init($url);
    $headers = $options['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $options['method'] ?? 'GET',
        CURLOPT_POSTFIELDS => $options['body'] ?? null,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('curl failed: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

function posTablesSmokeExtractCsrf(string $html, string $fieldName): string
{
    if (preg_match('/name="' . preg_quote($fieldName, '/') . '" value="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }
    if (preg_match('/name="' . preg_quote($fieldName, '/') . '" content="([^"]+)"/', $html, $matches)) {
        return $matches[1];
    }

    throw new RuntimeException('Missing CSRF token: ' . $fieldName);
}

$cookieFile = sys_get_temp_dir() . '/pos_tables_smoke_' . getmypid() . '.cookies';
@unlink($cookieFile);

try {
    $loginPage = posTablesSmokeRequest($baseUrl . '/index.php', $cookieFile);
    if ($loginPage['status'] !== 200) {
        throw new RuntimeException('Login page HTTP ' . $loginPage['status']);
    }

    $loginCsrf = posTablesSmokeExtractCsrf($loginPage['body'], 'csrf_token');
    $loginBody = http_build_query([
        'csrf_token' => $loginCsrf,
        'uname' => $username,
        'password' => $password,
    ]);
    $loginPost = posTablesSmokeRequest($baseUrl . '/index.php', $cookieFile, [
        'method' => 'POST',
        'body' => $loginBody,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if (!in_array($loginPost['status'], [302, 303], true)) {
        throw new RuntimeException('Login failed HTTP ' . $loginPost['status'] . ' body=' . substr($loginPost['body'], 0, 200));
    }

    $posPage = posTablesSmokeRequest($baseUrl . '/pos_barcode.php', $cookieFile);
    if ($posPage['status'] !== 200) {
        throw new RuntimeException('POS page HTTP ' . $posPage['status']);
    }

    if (strpos($posPage['body'], 'id="posForm"') === false) {
        if (!preg_match('/const csrfToken = "([^"]+)"/', $posPage['body'], $pinCsrfMatch)) {
            throw new RuntimeException('Missing PIN CSRF token on unlock screen');
        }
        $pinCsrf = $pinCsrfMatch[1];
        $pinPost = posTablesSmokeRequest($baseUrl . '/ajax/pos_pin_login.php', $cookieFile, [
            'method' => 'POST',
            'body' => http_build_query(['pin' => $pin, 'csrf_token' => $pinCsrf]),
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
            ],
        ]);
        $pinJson = json_decode($pinPost['body'], true);
        if (($pinJson['success'] ?? false) !== true) {
            throw new RuntimeException('PIN unlock failed HTTP ' . $pinPost['status'] . ' body=' . $pinPost['body']);
        }

        $posPage = posTablesSmokeRequest($baseUrl . '/pos_barcode.php', $cookieFile);
        if (strpos($posPage['body'], 'id="posForm"') === false) {
            throw new RuntimeException('POS form still locked after PIN');
        }
    }

    $tables = posTablesSmokeRequest($baseUrl . '/ajax/get_tables.php', $cookieFile, [
        'headers' => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'],
    ]);
    $tablesJson = json_decode($tables['body'], true);
    $tableCount = is_array($tablesJson['tables'] ?? null) ? count($tablesJson['tables']) : 0;

    if (($tablesJson['success'] ?? false) !== true) {
        fwrite(STDERR, "get_tables failed HTTP {$tables['status']} body={$tables['body']}\n");
        exit(1);
    }

    fwrite(STDOUT, "pos-tables-smoke-ok user={$username} tables={$tableCount} http={$tables['status']}\n");
} finally {
    @unlink($cookieFile);
}
