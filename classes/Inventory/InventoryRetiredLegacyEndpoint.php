<?php

class InventoryRetiredLegacyEndpoint
{
    public static function respond(string $endpointCode, string $responseType = 'json'): void
    {
        $message = 'تم إيقاف هذا المسار القديم للمخزون. استخدم شاشات المخزون الجديدة للتسوية أو الجرد أو التعديل.';
        http_response_code(410);

        if ($responseType === 'html') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>مسار مخزون متوقف</title><div style="font-family:Arial,sans-serif;direction:rtl;padding:24px">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
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
