<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/InventoryFeatureFlags.php';

class InventoryLegacyStockEndpointGuard
{
    public static function blockIfLive(string $endpointCode, string $responseType = 'json'): void
    {
        $flags = new InventoryFeatureFlags(function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ($flags->mode() !== 'live') {
            return;
        }

        $message = 'تم إيقاف مسار المخزون القديم بعد تفعيل نظام المخزون الجديد';
        http_response_code(409);

        if ($responseType === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>مسار مخزون قديم</title><div style="font-family:Arial,sans-serif;direction:rtl;padding:24px">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'message' => $message,
            'code' => $endpointCode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
