<?php

require_once __DIR__ . '/../classes/Updates/UpdateMaintenance.php';

if (!function_exists('posmain_update_maintenance_guard')) {
    function posmain_update_maintenance_guard(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $maintenance = new PosmainUpdateMaintenance();
        if (!$maintenance->isEnabled() || $maintenance->shouldBypassRequest()) {
            return;
        }

        $payload = $maintenance->payload() ?: ['message' => 'System update in progress.'];
        $message = (string) ($payload['message'] ?? 'System update in progress.');

        if (function_exists('posmain_is_json_request') && posmain_is_json_request()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 30');
            echo json_encode([
                'ok' => false,
                'error' => 'maintenance_mode',
                'message' => $message,
                'maintenance' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 30');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Maintenance</title></head><body>';
        echo '<h1>Maintenance</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</body></html>';
        exit;
    }
}
