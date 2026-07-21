<?php

require_once __DIR__ . '/../classes/Sync/SchemaManager.php';

if (!function_exists('posmain_require_delivery_schema_ready')) {
    function posmain_require_delivery_schema_ready(mysqli $conn): void
    {
        if ((new SyncSchemaManager())->pendingDeliveryStatements($conn) !== []) {
            throw new RuntimeException('DELIVERY_SCHEMA_MIGRATIONS_PENDING');
        }
    }
}

if (!function_exists('posmain_delivery_api_error')) {
    function posmain_delivery_api_error(Throwable $exception): array
    {
        $code = trim($exception->getMessage()) ?: 'DELIVERY_OPERATION_FAILED';
        if ($code === 'DELIVERY_SCHEMA_MIGRATIONS_PENDING') {
            return [409, [
                'success' => false,
                'code' => $code,
                'error' => 'يلزم تطبيق تحديثات قاعدة بيانات التوصيل قبل استخدام هذه الوظيفة.',
            ]];
        }
        if ($exception instanceof InvalidArgumentException) {
            return [422, ['success' => false, 'code' => $code, 'error' => $code]];
        }

        error_log('Delivery API failed: ' . $exception->getMessage());
        return [500, [
            'success' => false,
            'code' => 'DELIVERY_OPERATION_FAILED',
            'error' => 'تعذر إتمام عملية التوصيل الآن. حاول مرة أخرى أو تواصل مع المدير.',
        ]];
    }
}

if (!function_exists('posmain_emit_delivery_api_error')) {
    function posmain_emit_delivery_api_error(Throwable $exception): void
    {
        [$status, $payload] = posmain_delivery_api_error($exception);
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
