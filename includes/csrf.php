<?php

require_once __DIR__ . '/session_bootstrap.php';

if (!function_exists('csrf_token')) {
    function csrf_token(string $namespace = 'default'): string
    {
        $namespace = csrf_normalize_namespace($namespace);
        if (!isset($_SESSION['posmain_csrf_tokens']) || !is_array($_SESSION['posmain_csrf_tokens'])) {
            $_SESSION['posmain_csrf_tokens'] = [];
        }

        if (empty($_SESSION['posmain_csrf_tokens'][$namespace])) {
            $_SESSION['posmain_csrf_tokens'][$namespace] = bin2hex(random_bytes(32));
        }

        if ($namespace === 'default') {
            $_SESSION['csrf_token'] = $_SESSION['posmain_csrf_tokens'][$namespace];
        }

        return (string) $_SESSION['posmain_csrf_tokens'][$namespace];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input(string $namespace = 'default', string $field = 'csrf_token'): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . '" value="'
            . htmlspecialchars(csrf_token($namespace), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_meta_tag')) {
    function csrf_meta_tag(string $namespace = 'default', string $name = 'csrf-token'): string
    {
        return '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="'
            . htmlspecialchars(csrf_token($namespace), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(?string $candidate, string $namespace = 'default'): bool
    {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            return false;
        }

        $namespace = csrf_normalize_namespace($namespace);
        $expected = (string) ($_SESSION['posmain_csrf_tokens'][$namespace] ?? '');
        if ($expected === '' && $namespace === 'default') {
            $expected = (string) ($_SESSION['csrf_token'] ?? '');
        }

        return $expected !== '' && hash_equals($expected, $candidate);
    }
}

if (!function_exists('verify_csrf_from_post_or_header')) {
    function verify_csrf_from_post_or_header(string $namespace = 'default', string $field = 'csrf_token'): bool
    {
        return verify_csrf_token(csrf_request_token($field), $namespace);
    }
}

if (!function_exists('csrf_request_token')) {
    function csrf_request_token(string $field = 'csrf_token'): string
    {
        $field = $field !== '' ? $field : 'csrf_token';
        $candidates = [
            $_POST[$field] ?? null,
            $_POST['csrf'] ?? null,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
            $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(string $namespace = 'default', string $field = 'csrf_token'): void
    {
        if (verify_csrf_from_post_or_header($namespace, $field)) {
            return;
        }

        http_response_code(403);
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $isJson = strpos($accept, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || strpos((string) ($_SERVER['PHP_SELF'] ?? ''), '/ajax/') !== false;

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'code' => 'CSRF_INVALID',
                'message' => 'CSRF_INVALID',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo 'CSRF_INVALID';
        exit;
    }
}

if (!function_exists('csrf_normalize_namespace')) {
    function csrf_normalize_namespace(string $namespace): string
    {
        $namespace = trim($namespace);
        return $namespace !== '' ? $namespace : 'default';
    }
}
